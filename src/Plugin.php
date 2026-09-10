<?php
namespace DesignCoreHub;

use DesignCoreHub\Admin\Studio;
use DesignCoreHub\Build\SyncManager;
use DesignCoreHub\Remote\Config;
use DesignCoreHub\Rest\Controller;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Plugin {
    private static $instance;

    public static function instance(): self {
        if ( ! self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function boot(): void {
        add_filter( 'cron_schedules', array( self::class, 'cron_schedules' ) );
        add_action( 'init', array( self::class, 'ensure_sync_scheduled' ) );
        add_action( DCH_SYNC_HOOK, array( SyncManager::class, 'cron' ) );

        add_action( 'rest_api_init', array( Controller::class, 'register_routes' ) );
        add_action( 'admin_menu', array( Studio::class, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( Studio::class, 'enqueue_assets' ) );

        // Managed pages must render on a deterministic blank canvas. Otherwise the
        // active theme can inject its own header, footer, container width, block gap,
        // post title and typography around an AI-generated reference implementation.
        add_filter( 'template_include', array( $this, 'managed_canvas_template' ), 999 );
        add_action( 'wp_head', array( $this, 'print_page_css' ), 99 );
        add_filter( 'body_class', array( $this, 'add_body_classes' ) );
    }

    public static function activate(): void {
        $role = get_role( 'administrator' );
        if ( $role && ! $role->has_cap( DCH_CAPABILITY ) ) {
            $role->add_cap( DCH_CAPABILITY );
        }

        Config::ensure_defaults();
        add_filter( 'cron_schedules', array( self::class, 'cron_schedules' ) );
        self::ensure_sync_scheduled();
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( DCH_SYNC_HOOK );
    }

    public static function cron_schedules( array $schedules ): array {
        if ( ! isset( $schedules['dch_five_minutes'] ) ) {
            $schedules['dch_five_minutes'] = array(
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 5 minutes (Design Core Hub)', 'wordpress-design-core-hub' ),
            );
        }
        return $schedules;
    }

    public static function ensure_sync_scheduled(): void {
        if ( ! wp_next_scheduled( DCH_SYNC_HOOK ) ) {
            wp_schedule_event( time() + MINUTE_IN_SECONDS, 'dch_five_minutes', DCH_SYNC_HOOK );
        }
    }

    /**
     * Bypass the active theme for Design Core managed pages.
     *
     * This is deliberately based on the managed-page marker rather than a user
     * selected page template, so existing remote drafts are corrected immediately
     * after the plugin update without needing a new post meta migration.
     */
    public function managed_canvas_template( string $template ): string {
        if ( ! is_singular( 'page' ) ) {
            return $template;
        }

        $post_id = get_queried_object_id();
        if ( ! $post_id || ! get_post_meta( $post_id, '_dch_managed', true ) ) {
            return $template;
        }

        $canvas = DCH_DIR . 'templates/canvas.php';
        return is_readable( $canvas ) ? $canvas : $template;
    }

    public function add_body_classes( array $classes ): array {
        if ( is_singular( 'page' ) ) {
            $post_id = get_queried_object_id();
            if ( $post_id && get_post_meta( $post_id, '_dch_managed', true ) ) {
                $classes[] = 'dch-managed-page';
                $classes[] = 'dch-page-' . absint( $post_id );

                $slug = get_post_field( 'post_name', $post_id );
                if ( $slug ) {
                    $classes[] = 'dch-target-' . sanitize_html_class( $slug );
                }
            }
        }
        return $classes;
    }

    public function print_page_css(): void {
        if ( ! is_singular( 'page' ) ) {
            return;
        }

        $post_id = get_queried_object_id();
        if ( ! $post_id || ! get_post_meta( $post_id, '_dch_managed', true ) ) {
            return;
        }

        $css = (string) get_post_meta( $post_id, '_dch_page_css', true );
        if ( '' === trim( $css ) ) {
            return;
        }

        // CSS is build data, never executable PHP/JS. Guard the closing style token
        // so malformed remote content cannot terminate this element early.
        $css = str_ireplace( '</style', '<\\/style', $css );
        echo "\n<style id=\"design-core-hub-page-css\">\n" . $css . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}

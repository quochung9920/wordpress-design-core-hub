<?php
namespace DesignCoreHub;

use DesignCoreHub\Admin\Studio;
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
        add_action( 'rest_api_init', array( Controller::class, 'register_routes' ) );
        add_action( 'admin_menu', array( Studio::class, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( Studio::class, 'enqueue_assets' ) );
        add_action( 'wp_head', array( $this, 'print_page_css' ), 99 );
        add_filter( 'body_class', array( $this, 'add_body_classes' ) );
    }

    public function add_body_classes( array $classes ): array {
        if ( is_singular( 'page' ) ) {
            $post_id = get_queried_object_id();
            if ( $post_id && get_post_meta( $post_id, '_dch_managed', true ) ) {
                $classes[] = 'dch-managed-page';
                $classes[] = 'dch-page-' . absint( $post_id );
            }
        }
        return $classes;
    }

    public function print_page_css(): void {
        if ( ! is_singular( 'page' ) ) {
            return;
        }

        $post_id = get_queried_object_id();
        $css     = (string) get_post_meta( $post_id, '_dch_page_css', true );
        if ( '' === trim( $css ) ) {
            return;
        }

        $css = str_ireplace( '</style', '<\\/style', $css );
        echo "\n<style id=\"design-core-hub-page-css\">\n" . $css . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}

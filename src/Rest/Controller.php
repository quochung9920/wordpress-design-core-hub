<?php
namespace DesignCoreHub\Rest;

use DesignCoreHub\Build\DraftManager;
use DesignCoreHub\Build\SyncManager;
use DesignCoreHub\History\Repository;
use DesignCoreHub\Remote\Config;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Controller {
    private const NS = 'design-core-hub/v1';
    private const PUBLIC_SYNC_COOLDOWN = 15;

    public static function register_routes(): void {
        register_rest_route(
            self::NS,
            '/remote-status',
            array(
                'methods'             => 'GET',
                'callback'            => array( self::class, 'remote_status' ),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            self::NS,
            '/remote-sync',
            array(
                'methods'             => 'GET',
                'callback'            => array( self::class, 'remote_sync' ),
                'permission_callback' => '__return_true',
            )
        );

        $read = array( 'permission_callback' => array( self::class, 'can_build' ) );
        register_rest_route( self::NS, '/context', array_merge( $read, array( 'methods' => 'GET', 'callback' => array( self::class, 'context' ) ) ) );
        register_rest_route( self::NS, '/design-system', array_merge( $read, array( 'methods' => 'GET', 'callback' => array( self::class, 'design_system' ) ) ) );
        register_rest_route( self::NS, '/blocks', array_merge( $read, array( 'methods' => 'GET', 'callback' => array( self::class, 'blocks' ) ) ) );
        register_rest_route( self::NS, '/settings', array_merge( $read, array( 'methods' => 'GET', 'callback' => array( self::class, 'settings' ) ) ) );
        register_rest_route( self::NS, '/settings', array_merge( $read, array( 'methods' => 'POST', 'callback' => array( self::class, 'update_settings' ) ) ) );
        register_rest_route( self::NS, '/sync', array_merge( $read, array( 'methods' => 'POST', 'callback' => array( self::class, 'manual_sync' ) ) ) );
        register_rest_route( self::NS, '/managed-drafts', array_merge( $read, array( 'methods' => 'GET', 'callback' => array( self::class, 'managed_drafts' ) ) ) );

        $edit = array( 'permission_callback' => array( self::class, 'can_edit_page' ) );
        register_rest_route( self::NS, '/pages/(?P<id>\\d+)/history', array_merge( $edit, array( 'methods' => 'GET', 'callback' => array( self::class, 'history' ) ) ) );
        register_rest_route( self::NS, '/pages/(?P<id>\\d+)/rollback', array_merge( $edit, array( 'methods' => 'POST', 'callback' => array( self::class, 'rollback' ) ) ) );
    }

    public static function can_build(): bool {
        return current_user_can( DCH_CAPABILITY ) && current_user_can( 'edit_pages' );
    }

    public static function can_edit_page( WP_REST_Request $request ): bool {
        $id = absint( $request['id'] );
        return self::can_build() && $id && current_user_can( 'edit_post', $id );
    }

    public static function remote_status(): WP_REST_Response {
        $config = Config::get();
        $status = SyncManager::status();
        $last   = isset( $status['last_applied'] ) && is_array( $status['last_applied'] ) ? $status['last_applied'] : array();

        $data = array(
            'plugin_version'    => DCH_VERSION,
            'site_key'          => $config['site_key'],
            'remote'            => Config::public_view( $config ),
            'sync_url'          => rest_url( self::NS . '/remote-sync' ),
            'status_url'        => rest_url( self::NS . '/remote-status' ),
            'next_cron_gmt'     => self::next_cron_gmt(),
            'state'             => $status['state'],
            'message'           => $status['message'],
            'checked_gmt'       => $status['checked_gmt'],
            'observed_build_id' => $status['observed_build_id'],
            'last_applied'      => array(
                'build_id'    => (string) ( $last['build_id'] ?? '' ),
                'page_id'     => (int) ( $last['page_id'] ?? 0 ),
                'title'       => (string) ( $last['title'] ?? '' ),
                'slug'        => (string) ( $last['slug'] ?? '' ),
                'applied_gmt' => (string) ( $last['applied_gmt'] ?? '' ),
                'block_count' => (int) ( $last['block_count'] ?? 0 ),
                'warnings'    => isset( $last['warnings'] ) && is_array( $last['warnings'] ) ? $last['warnings'] : array(),
            ),
            'error'             => isset( $status['error'] ) && is_array( $status['error'] ) ? $status['error'] : null,
        );

        return self::no_store( self::ok( $data ) );
    }

    public static function remote_sync() {
        $config = Config::get();
        if ( ! $config['enabled'] ) {
            return self::no_store( self::error_response( 'dch_sync_disabled', 'Remote build sync is disabled.', 409 ) );
        }
        if ( ! $config['public_trigger'] ) {
            return self::no_store( self::error_response( 'dch_public_trigger_disabled', 'Public remote sync trigger is disabled.', 403 ) );
        }

        $rate_key = 'dch_public_sync_cooldown';
        if ( get_transient( $rate_key ) ) {
            return self::no_store( self::error_response( 'dch_sync_rate_limited', 'A remote sync was triggered recently. Try again shortly.', 429 ) );
        }
        set_transient( $rate_key, 1, self::PUBLIC_SYNC_COOLDOWN );

        $result = SyncManager::run( false, 'public' );
        if ( is_wp_error( $result ) ) {
            return self::no_store( self::error_response( $result->get_error_code(), $result->get_error_message(), 409 ) );
        }

        return self::no_store( self::ok( $result ) );
    }

    public static function context(): WP_REST_Response {
        $theme  = wp_get_theme();
        $config = Config::get();
        $types  = \WP_Block_Type_Registry::get_instance()->get_all_registered();

        return self::ok(
            array(
                'plugin_version'    => DCH_VERSION,
                'wordpress'         => get_bloginfo( 'version' ),
                'site_name'         => get_bloginfo( 'name' ),
                'site_url'          => home_url( '/' ),
                'theme'             => array(
                    'name'        => $theme->get( 'Name' ),
                    'stylesheet'  => $theme->get_stylesheet(),
                    'version'     => $theme->get( 'Version' ),
                    'block_theme' => function_exists( 'wp_is_block_theme' ) ? wp_is_block_theme() : false,
                ),
                'registered_blocks' => count( $types ),
                'remote'            => Config::public_view( $config ),
                'workflow'          => array(
                    'primary_control' => 'chatgpt_to_github_to_wordpress',
                    'write_target'    => 'managed_draft_pages_only',
                    'mcp_required'    => false,
                    'work_required'   => false,
                ),
            )
        );
    }

    public static function design_system(): WP_REST_Response {
        return self::ok(
            array(
                'settings' => function_exists( 'wp_get_global_settings' ) ? wp_get_global_settings() : array(),
                'styles'   => function_exists( 'wp_get_global_styles' ) ? wp_get_global_styles() : array(),
            )
        );
    }

    public static function blocks( WP_REST_Request $request ): WP_REST_Response {
        $search = strtolower( sanitize_text_field( (string) $request->get_param( 'search' ) ) );
        $limit  = min( 200, max( 1, absint( $request->get_param( 'limit' ) ?: 80 ) ) );
        $result = array();

        foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
            $haystack = strtolower( $name . ' ' . (string) $type->title . ' ' . (string) $type->description );
            if ( $search && false === strpos( $haystack, $search ) ) {
                continue;
            }

            $result[] = array(
                'name'        => $name,
                'title'       => $type->title,
                'description' => $type->description,
                'category'    => $type->category,
                'parent'      => $type->parent,
                'ancestor'    => $type->ancestor,
                'attributes'  => $type->attributes,
                'supports'    => $type->supports,
                'dynamic'     => is_callable( $type->render_callback ),
            );
            if ( count( $result ) >= $limit ) {
                break;
            }
        }

        return self::ok( array( 'blocks' => $result, 'count' => count( $result ) ) );
    }

    public static function settings(): WP_REST_Response {
        return self::ok(
            array(
                'settings'        => Config::get(),
                'latest_path'     => Config::latest_path(),
                'status'          => SyncManager::status(),
                'status_url'      => rest_url( self::NS . '/remote-status' ),
                'public_sync_url' => rest_url( self::NS . '/remote-sync' ),
                'next_cron_gmt'   => self::next_cron_gmt(),
            )
        );
    }

    public static function update_settings( WP_REST_Request $request ): WP_REST_Response {
        $body   = self::json( $request );
        $values = isset( $body['settings'] ) && is_array( $body['settings'] ) ? $body['settings'] : $body;
        $config = Config::update( $values );

        return self::ok(
            array(
                'settings'    => $config,
                'latest_path' => Config::latest_path( $config ),
            )
        );
    }

    public static function manual_sync(): WP_REST_Response {
        $result = SyncManager::run( true, 'manual' );
        if ( is_wp_error( $result ) ) {
            return self::error_response( $result->get_error_code(), $result->get_error_message(), 409 );
        }
        return self::ok( $result );
    }

    public static function managed_drafts(): WP_REST_Response {
        return self::ok( array( 'drafts' => DraftManager::managed_drafts() ) );
    }

    public static function history( WP_REST_Request $request ): WP_REST_Response {
        $items = array_map(
            static function ( $entry ) {
                return array(
                    'id'              => (string) ( $entry['id'] ?? '' ),
                    'created_gmt'     => (string) ( $entry['created_gmt'] ?? '' ),
                    'user_id'         => (int) ( $entry['user_id'] ?? 0 ),
                    'label'           => (string) ( $entry['label'] ?? '' ),
                    'modified_gmt'    => (string) ( $entry['modified_gmt'] ?? '' ),
                    'remote_build_id' => (string) ( $entry['remote_build_id'] ?? '' ),
                );
            },
            Repository::all( absint( $request['id'] ) )
        );

        return self::ok( array( 'history' => $items ) );
    }

    public static function rollback( WP_REST_Request $request ) {
        $id   = absint( $request['id'] );
        $post = get_post( $id );
        if ( ! $post || 'page' !== $post->post_type ) {
            return self::error_response( 'not_found', 'Page not found.', 404 );
        }
        if ( 'draft' !== $post->post_status || ! get_post_meta( $id, '_dch_managed', true ) ) {
            return self::error_response( 'draft_only', 'Rollback is limited to Design Core managed draft pages.', 409 );
        }

        $body  = self::json( $request );
        $entry = Repository::find( $id, sanitize_text_field( (string) ( $body['entry_id'] ?? '' ) ) );
        if ( ! $entry ) {
            return self::error_response( 'history_not_found', 'History entry not found.', 404 );
        }

        Repository::snapshot( $id, 'Before manual rollback' );
        $updated = wp_update_post(
            wp_slash(
                array(
                    'ID'           => $id,
                    'post_title'   => (string) ( $entry['post_title'] ?? $post->post_title ),
                    'post_name'    => (string) ( $entry['post_name'] ?? $post->post_name ),
                    'post_content' => (string) ( $entry['post_content'] ?? '' ),
                )
            ),
            true
        );

        if ( is_wp_error( $updated ) ) {
            return $updated;
        }

        update_post_meta( $id, '_dch_page_css', (string) ( $entry['page_css'] ?? '' ) );
        update_post_meta( $id, '_dch_remote_build_id', (string) ( $entry['remote_build_id'] ?? '' ) );
        update_post_meta( $id, '_dch_remote_manifest_hash', (string) ( $entry['manifest_hash'] ?? '' ) );
        update_post_meta( $id, '_dch_managed', 1 );
        clean_post_cache( $id );

        return self::ok(
            array(
                'page_id'         => $id,
                'remote_build_id' => (string) get_post_meta( $id, '_dch_remote_build_id', true ),
                'preview_url'     => get_preview_post_link( get_post( $id ) ),
            )
        );
    }

    private static function next_cron_gmt(): string {
        $next = wp_next_scheduled( DCH_SYNC_HOOK );
        return $next ? gmdate( 'Y-m-d H:i:s', $next ) : '';
    }

    private static function json( WP_REST_Request $request ): array {
        $body = $request->get_json_params();
        return is_array( $body ) ? $body : array();
    }

    private static function ok( array $data, int $status = 200 ): WP_REST_Response {
        return new WP_REST_Response( array( 'success' => true, 'data' => $data ), $status );
    }

    private static function error_response( string $code, string $message, int $status ): WP_REST_Response {
        return new WP_REST_Response(
            array(
                'success' => false,
                'error'   => array(
                    'code'    => $code,
                    'message' => $message,
                ),
            ),
            $status
        );
    }

    private static function no_store( WP_REST_Response $response ): WP_REST_Response {
        $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
        $response->header( 'X-Robots-Tag', 'noindex, nofollow, noarchive' );
        return $response;
    }
}

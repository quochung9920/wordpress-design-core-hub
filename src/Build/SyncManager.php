<?php
namespace DesignCoreHub\Build;

use DesignCoreHub\Gutenberg\Validator;
use DesignCoreHub\Remote\Config;
use DesignCoreHub\Remote\GitHubProvider;
use DesignCoreHub\Remote\Manifest;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SyncManager {
    public const STATUS_OPTION = 'dch_remote_sync_status';
    private const LOCK_KEY     = 'dch_remote_sync_lock';
    private const CONTENT_MAX  = 2097153;
    private const CSS_MAX      = 1048577;

    public static function cron(): void {
        self::run( false, 'cron' );
    }

    public static function run( bool $force = false, string $source = 'manual' ) {
        $config = Config::get();
        if ( ! $config['enabled'] ) {
            return self::store_status(
                'disabled',
                'Remote build sync is disabled.',
                $source,
                array()
            );
        }

        if ( get_transient( self::LOCK_KEY ) ) {
            return new WP_Error( 'dch_sync_busy', 'A Design Core sync is already running.' );
        }
        set_transient( self::LOCK_KEY, 1, 60 );

        try {
            $provider = new GitHubProvider( $config );

            $latest_response = $provider->fetch_json( Config::latest_path( $config ), 65537, true );
            if ( is_wp_error( $latest_response ) ) {
                return self::fail( $latest_response, $source );
            }

            $latest = Manifest::validate_latest( $latest_response['data'], $config );
            if ( is_wp_error( $latest ) ) {
                return self::fail( $latest, $source );
            }

            $previous = self::status();
            $last_id  = (string) ( $previous['last_applied']['build_id'] ?? '' );
            if ( ! $force && $last_id && hash_equals( $last_id, $latest['build_id'] ) ) {
                return self::store_status(
                    'no_change',
                    'Latest GitHub build is already applied.',
                    $source,
                    array(
                        'observed_build_id' => $latest['build_id'],
                        'last_applied'      => $previous['last_applied'] ?? array(),
                    )
                );
            }

            $manifest_response = $provider->fetch_json( $latest['manifest_path'], 262145, false );
            if ( is_wp_error( $manifest_response ) ) {
                return self::fail( $manifest_response, $source, $latest['build_id'] );
            }
            if ( ! Manifest::verify_sha256( $manifest_response['raw'], $latest['manifest_sha256'] ) ) {
                return self::fail(
                    new WP_Error( 'dch_manifest_integrity', 'manifest.json SHA-256 does not match latest.json.' ),
                    $source,
                    $latest['build_id']
                );
            }

            $manifest = Manifest::validate_build( $manifest_response['data'], $config, $latest['build_id'] );
            if ( is_wp_error( $manifest ) ) {
                return self::fail( $manifest, $source, $latest['build_id'] );
            }

            $content = $provider->fetch_text( $manifest['files']['content']['path'], self::CONTENT_MAX, false );
            if ( is_wp_error( $content ) ) {
                return self::fail( $content, $source, $latest['build_id'] );
            }
            if ( ! Manifest::verify_sha256( $content, $manifest['files']['content']['sha256'] ) ) {
                return self::fail(
                    new WP_Error( 'dch_content_integrity', 'blocks.html SHA-256 does not match manifest.json.' ),
                    $source,
                    $latest['build_id']
                );
            }

            $css = '';
            if ( $manifest['files']['css']['path'] ) {
                $css = $provider->fetch_text( $manifest['files']['css']['path'], self::CSS_MAX, false );
                if ( is_wp_error( $css ) ) {
                    return self::fail( $css, $source, $latest['build_id'] );
                }
                if ( ! Manifest::verify_sha256( $css, $manifest['files']['css']['sha256'] ) ) {
                    return self::fail(
                        new WP_Error( 'dch_css_integrity', 'styles.css SHA-256 does not match manifest.json.' ),
                        $source,
                        $latest['build_id']
                    );
                }
            }

            $validation = Validator::validate( $content );
            if ( ! $validation['valid'] ) {
                return self::fail(
                    new WP_Error( 'dch_invalid_gutenberg', 'Remote Gutenberg build failed validation.' ),
                    $source,
                    $latest['build_id'],
                    array( 'validation' => $validation )
                );
            }

            if ( $manifest['policy']['require_semantic_blocks'] && ( ! empty( $validation['fallback_blocks'] ) || ! empty( $validation['freeform_fragments'] ) ) ) {
                return self::fail(
                    new WP_Error( 'dch_semantic_policy', 'Build contains freeform HTML or fallback blocks while semantic-only policy is enabled.' ),
                    $source,
                    $latest['build_id'],
                    array( 'validation' => $validation )
                );
            }

            $manifest_hash = hash( 'sha256', $manifest_response['raw'] );
            $page          = DraftManager::apply( $manifest, $content, $css, $manifest_hash, $config['site_key'] );
            if ( is_wp_error( $page ) ) {
                return self::fail( $page, $source, $latest['build_id'], array( 'validation' => $validation ) );
            }

            $last_applied = array(
                'build_id'      => $latest['build_id'],
                'manifest_hash' => $manifest_hash,
                'page_id'       => $page['page_id'],
                'title'         => $page['title'],
                'slug'          => $page['slug'],
                'applied_gmt'   => current_time( 'mysql', true ),
                'block_count'   => $validation['count'],
                'max_depth'     => $validation['max_depth'],
                'warnings'      => $validation['warnings'],
            );

            return self::store_status(
                'success',
                $page['created'] ? 'Remote build created and populated a new managed draft.' : 'Remote build updated the managed draft.',
                $source,
                array(
                    'observed_build_id' => $latest['build_id'],
                    'last_applied'      => $last_applied,
                    'page'              => $page,
                    'validation'        => $validation,
                )
            );
        } finally {
            delete_transient( self::LOCK_KEY );
        }
    }

    public static function status(): array {
        $status = get_option( self::STATUS_OPTION, array() );
        if ( ! is_array( $status ) ) {
            $status = array();
        }

        return array_merge(
            array(
                'state'             => 'never_run',
                'message'           => 'No remote sync has run yet.',
                'checked_gmt'       => '',
                'source'            => '',
                'observed_build_id' => '',
                'last_applied'      => array(),
                'error'             => null,
            ),
            $status
        );
    }

    private static function fail( WP_Error $error, string $source, string $build_id = '', array $extra = array() ) {
        return self::store_status(
            'error',
            $error->get_error_message(),
            $source,
            array_merge(
                $extra,
                array(
                    'observed_build_id' => $build_id,
                    'error'             => array(
                        'code'    => $error->get_error_code(),
                        'message' => $error->get_error_message(),
                    ),
                )
            )
        );
    }

    private static function store_status( string $state, string $message, string $source, array $extra ): array {
        $previous = self::status();
        $status   = array(
            'state'             => $state,
            'message'           => $message,
            'checked_gmt'       => current_time( 'mysql', true ),
            'source'            => sanitize_key( $source ),
            'observed_build_id' => (string) ( $extra['observed_build_id'] ?? '' ),
            'last_applied'      => isset( $extra['last_applied'] ) && is_array( $extra['last_applied'] ) ? $extra['last_applied'] : $previous['last_applied'],
            'error'             => array_key_exists( 'error', $extra ) ? $extra['error'] : null,
        );

        foreach ( array( 'page', 'validation' ) as $key ) {
            if ( isset( $extra[ $key ] ) ) {
                $status[ $key ] = $extra[ $key ];
            }
        }

        update_option( self::STATUS_OPTION, $status, false );
        return $status;
    }
}

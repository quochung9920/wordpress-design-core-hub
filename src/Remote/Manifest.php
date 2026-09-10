<?php
namespace DesignCoreHub\Remote;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Manifest {
    public const LATEST_SCHEMA = 'design-core-hub/latest@1';
    public const BUILD_SCHEMA  = 'design-core-hub/build@1';

    public static function validate_latest( array $data, array $config ) {
        if ( self::LATEST_SCHEMA !== ( $data['schema'] ?? '' ) ) {
            return new WP_Error( 'dch_latest_schema', 'latest.json uses an unsupported schema.' );
        }

        $build_id = self::build_id( $data['build_id'] ?? '' );
        if ( '' === $build_id ) {
            return new WP_Error( 'dch_latest_build_id', 'latest.json has an invalid build_id.' );
        }

        $expected_path = Config::join_path( $config['build_root'], $config['site_key'], $build_id, 'manifest.json' );
        $manifest_path = isset( $data['manifest_path'] ) ? trim( (string) $data['manifest_path'], '/' ) : '';
        if ( $expected_path !== $manifest_path ) {
            return new WP_Error( 'dch_latest_manifest_path', 'latest.json manifest_path must point to the immutable build directory.' );
        }

        $manifest_sha256 = self::sha256( $data['manifest_sha256'] ?? '' );
        if ( '' === $manifest_sha256 ) {
            return new WP_Error( 'dch_latest_manifest_hash', 'latest.json requires a valid manifest_sha256.' );
        }

        return array(
            'schema'          => self::LATEST_SCHEMA,
            'build_id'        => $build_id,
            'manifest_path'   => $manifest_path,
            'manifest_sha256' => $manifest_sha256,
        );
    }

    public static function validate_build( array $data, array $config, string $expected_build_id ) {
        if ( self::BUILD_SCHEMA !== ( $data['schema'] ?? '' ) ) {
            return new WP_Error( 'dch_manifest_schema', 'manifest.json uses an unsupported schema.' );
        }

        $build_id = self::build_id( $data['build_id'] ?? '' );
        if ( '' === $build_id || ! hash_equals( $expected_build_id, $build_id ) ) {
            return new WP_Error( 'dch_manifest_build_id', 'manifest.json build_id does not match latest.json.' );
        }

        $target = isset( $data['target'] ) && is_array( $data['target'] ) ? $data['target'] : array();
        if ( 'page' !== ( $target['post_type'] ?? 'page' ) ) {
            return new WP_Error( 'dch_manifest_post_type', 'Only WordPress pages are supported in this release.' );
        }
        if ( 'upsert_draft' !== ( $target['operation'] ?? 'upsert_draft' ) ) {
            return new WP_Error( 'dch_manifest_operation', 'Only the upsert_draft operation is supported.' );
        }

        $title = sanitize_text_field( (string) ( $target['title'] ?? '' ) );
        if ( '' === $title || 200 < strlen( $title ) ) {
            return new WP_Error( 'dch_manifest_title', 'Build target requires a valid page title.' );
        }

        $raw_slug = (string) ( $target['slug'] ?? '' );
        $slug     = sanitize_title( $raw_slug );
        if ( '' === $slug || $slug !== $raw_slug ) {
            return new WP_Error( 'dch_manifest_slug', 'Build target requires a normalized WordPress slug.' );
        }

        $files  = isset( $data['files'] ) && is_array( $data['files'] ) ? $data['files'] : array();
        $prefix = Config::join_path( $config['build_root'], $config['site_key'], $build_id ) . '/';

        $content = self::validate_file( $files['content'] ?? null, $prefix, 'blocks.html', true );
        if ( is_wp_error( $content ) ) {
            return $content;
        }

        $css = self::validate_file( $files['css'] ?? null, $prefix, 'styles.css', false );
        if ( is_wp_error( $css ) ) {
            return $css;
        }

        $policy = isset( $data['policy'] ) && is_array( $data['policy'] ) ? $data['policy'] : array();
        $source = isset( $data['source'] ) && is_array( $data['source'] ) ? $data['source'] : array();

        $expected_previous = '';
        if ( isset( $data['expected_previous_build_id'] ) && '' !== (string) $data['expected_previous_build_id'] ) {
            $expected_previous = self::build_id( $data['expected_previous_build_id'] );
            if ( '' === $expected_previous ) {
                return new WP_Error( 'dch_expected_previous', 'expected_previous_build_id is invalid.' );
            }
        }

        return array(
            'schema'                     => self::BUILD_SCHEMA,
            'build_id'                   => $build_id,
            'expected_previous_build_id' => $expected_previous,
            'target'                     => array(
                'post_type' => 'page',
                'operation' => 'upsert_draft',
                'title'     => $title,
                'slug'      => $slug,
            ),
            'files'                      => array(
                'content' => $content,
                'css'     => $css,
            ),
            'policy'                     => array(
                'require_semantic_blocks' => ! array_key_exists( 'require_semantic_blocks', $policy ) || (bool) $policy['require_semantic_blocks'],
            ),
            'source'                     => array(
                'kind'      => sanitize_key( (string) ( $source['kind'] ?? 'chatgpt' ) ),
                'reference' => sanitize_text_field( (string) ( $source['reference'] ?? '' ) ),
                'note'      => sanitize_text_field( (string) ( $source['note'] ?? '' ) ),
            ),
        );
    }

    public static function verify_sha256( string $contents, string $expected ): bool {
        return hash_equals( strtolower( $expected ), hash( 'sha256', $contents ) );
    }

    private static function validate_file( $file, string $prefix, string $basename, bool $required ) {
        if ( ! is_array( $file ) ) {
            if ( $required ) {
                return new WP_Error( 'dch_manifest_file', 'Build manifest is missing required file metadata for ' . $basename . '.' );
            }
            return array( 'path' => '', 'sha256' => '' );
        }

        $path = trim( (string) ( $file['path'] ?? '' ), '/' );
        $hash = self::sha256( $file['sha256'] ?? '' );

        if ( '' === $path && ! $required ) {
            return array( 'path' => '', 'sha256' => '' );
        }
        if ( 0 !== strpos( $path, $prefix ) || basename( $path ) !== $basename ) {
            return new WP_Error( 'dch_manifest_file_path', sprintf( '%s must stay inside the immutable build directory.', $basename ) );
        }
        if ( '' === $hash ) {
            return new WP_Error( 'dch_manifest_file_hash', sprintf( '%s requires a valid sha256.', $basename ) );
        }

        return array(
            'path'   => $path,
            'sha256' => $hash,
        );
    }

    private static function build_id( $value ): string {
        $value = trim( (string) $value );
        if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$/', $value ) ) {
            return '';
        }
        return $value;
    }

    private static function sha256( $value ): string {
        $value = strtolower( trim( (string) $value ) );
        return preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : '';
    }
}

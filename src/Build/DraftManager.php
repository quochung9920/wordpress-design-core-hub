<?php
namespace DesignCoreHub\Build;

use DesignCoreHub\Gutenberg\BlueprintCompiler;
use DesignCoreHub\History\Repository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class DraftManager {
    private const TARGET_META = '_dch_remote_target_key';

    public static function apply( array $manifest, string $content, string $css, string $manifest_hash, string $site_key ) {
        $target     = $manifest['target'];
        $target_key = hash( 'sha256', $site_key . ':' . $target['slug'] );
        $post       = self::find_managed_draft( $target_key );
        $created    = false;

        if ( is_wp_error( $post ) ) {
            return $post;
        }

        if ( ! $post ) {
            $post_id = wp_insert_post(
                array(
                    'post_type'      => 'page',
                    'post_status'    => 'draft',
                    'post_title'     => $target['title'],
                    'post_name'      => $target['slug'],
                    'post_content'   => '',
                    'comment_status' => 'closed',
                    'ping_status'    => 'closed',
                ),
                true
            );

            if ( is_wp_error( $post_id ) ) {
                return $post_id;
            }

            update_post_meta( $post_id, '_dch_managed', 1 );
            update_post_meta( $post_id, self::TARGET_META, $target_key );
            update_post_meta( $post_id, '_dch_remote_site_key', $site_key );
            $post    = get_post( $post_id );
            $created = true;
        }

        if ( ! $post || 'page' !== $post->post_type || 'draft' !== $post->post_status ) {
            return new WP_Error( 'dch_target_not_draft', 'Remote builds may only write to a managed draft page.' );
        }
        if ( ! get_post_meta( $post->ID, '_dch_managed', true ) ) {
            return new WP_Error( 'dch_target_not_managed', 'Remote build target is not managed by Design Core Hub.' );
        }

        $current_build = (string) get_post_meta( $post->ID, '_dch_remote_build_id', true );
        $expected      = (string) ( $manifest['expected_previous_build_id'] ?? '' );
        if ( $expected && ! hash_equals( $expected, $current_build ) ) {
            return new WP_Error(
                'dch_build_conflict',
                sprintf( 'Build expected previous build "%s" but the draft is currently at "%s".', $expected, $current_build ?: '(none)' )
            );
        }

        Repository::snapshot( $post->ID, 'Before remote build ' . $manifest['build_id'] );

        $updated = wp_update_post(
            wp_slash(
                array(
                    'ID'           => $post->ID,
                    'post_title'   => $target['title'],
                    'post_name'    => $target['slug'],
                    'post_content' => $content,
                )
            ),
            true
        );

        if ( is_wp_error( $updated ) ) {
            return $updated;
        }

        update_post_meta( $post->ID, '_dch_page_css', BlueprintCompiler::sanitize_css( $css ) );
        update_post_meta( $post->ID, '_dch_managed', 1 );
        update_post_meta( $post->ID, self::TARGET_META, $target_key );
        update_post_meta( $post->ID, '_dch_remote_site_key', $site_key );
        update_post_meta( $post->ID, '_dch_remote_build_id', $manifest['build_id'] );
        update_post_meta( $post->ID, '_dch_remote_manifest_hash', $manifest_hash );
        update_post_meta( $post->ID, '_dch_remote_applied_gmt', current_time( 'mysql', true ) );
        update_post_meta( $post->ID, '_dch_remote_source', $manifest['source'] );

        clean_post_cache( $post->ID );
        $post = get_post( $post->ID );

        return array(
            'created'      => $created,
            'page_id'      => (int) $post->ID,
            'title'        => get_the_title( $post ),
            'slug'         => (string) $post->post_name,
            'status'       => (string) $post->post_status,
            'modified_gmt' => (string) $post->post_modified_gmt,
            'edit_url'     => get_edit_post_link( $post->ID, 'raw' ),
            'preview_url'  => get_preview_post_link( $post ),
        );
    }

    public static function managed_drafts( int $limit = 50 ): array {
        $posts = get_posts(
            array(
                'post_type'      => 'page',
                'post_status'    => 'draft',
                'posts_per_page' => min( 100, max( 1, $limit ) ),
                'meta_key'       => '_dch_managed',
                'meta_value'     => '1',
                'orderby'        => 'modified',
                'order'          => 'DESC',
            )
        );

        return array_map(
            static function ( $post ) {
                return array(
                    'id'           => (int) $post->ID,
                    'title'        => get_the_title( $post ),
                    'slug'         => (string) $post->post_name,
                    'build_id'     => (string) get_post_meta( $post->ID, '_dch_remote_build_id', true ),
                    'modified_gmt' => (string) $post->post_modified_gmt,
                    'edit_url'     => get_edit_post_link( $post->ID, 'raw' ),
                    'preview_url'  => get_preview_post_link( $post ),
                );
            },
            $posts
        );
    }

    private static function find_managed_draft( string $target_key ) {
        $posts = get_posts(
            array(
                'post_type'      => 'page',
                'post_status'    => 'draft',
                'posts_per_page' => 2,
                'meta_key'       => self::TARGET_META,
                'meta_value'     => $target_key,
                'orderby'        => 'ID',
                'order'          => 'ASC',
            )
        );

        if ( 1 < count( $posts ) ) {
            return new WP_Error( 'dch_duplicate_targets', 'More than one managed draft uses the same remote target key.' );
        }
        return $posts ? $posts[0] : null;
    }
}

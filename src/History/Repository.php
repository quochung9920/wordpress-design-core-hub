<?php
namespace DesignCoreHub\History;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Repository {
    private const META_KEY = '_dch_history';
    private const LIMIT    = 20;

    public static function snapshot( int $post_id, string $label = '' ): array {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return array();
        }

        $entry = array(
            'id'           => wp_generate_uuid4(),
            'created_gmt'  => current_time( 'mysql', true ),
            'user_id'      => get_current_user_id(),
            'label'        => sanitize_text_field( $label ),
            'post_content' => (string) $post->post_content,
            'page_css'     => (string) get_post_meta( $post_id, '_dch_page_css', true ),
            'modified_gmt' => (string) $post->post_modified_gmt,
        );

        $history = self::all( $post_id );
        array_unshift( $history, $entry );
        $history = array_slice( $history, 0, self::LIMIT );
        update_post_meta( $post_id, self::META_KEY, $history );

        return $entry;
    }

    public static function all( int $post_id ): array {
        $history = get_post_meta( $post_id, self::META_KEY, true );
        return is_array( $history ) ? array_values( $history ) : array();
    }

    public static function find( int $post_id, string $entry_id ): ?array {
        foreach ( self::all( $post_id ) as $entry ) {
            if ( isset( $entry['id'] ) && hash_equals( (string) $entry['id'], $entry_id ) ) {
                return $entry;
            }
        }
        return null;
    }
}

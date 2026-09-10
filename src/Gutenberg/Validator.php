<?php
namespace DesignCoreHub\Gutenberg;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Validator {
    private const MAX_CONTENT_BYTES = 2097152;
    private const MAX_BLOCKS        = 1200;
    private const MAX_DEPTH         = 40;

    public static function validate( string $content ): array {
        $errors   = array();
        $warnings = array();

        if ( strlen( $content ) > self::MAX_CONTENT_BYTES ) {
            $errors[] = 'Build content exceeds the 2 MiB safety limit.';
        }
        if ( '' === trim( $content ) ) {
            return self::result( array( 'Gutenberg content is empty.' ), $warnings, array(), 0, 0, array(), 0 );
        }
        if ( ! has_blocks( $content ) ) {
            return self::result( array( 'No Gutenberg block delimiters were detected.' ), $warnings, array(), 0, 0, array(), 0 );
        }

        $blocks = parse_blocks( $content );
        $state  = array(
            'count'      => 0,
            'max_depth'  => 0,
            'unresolved' => array(),
            'freeform'   => 0,
            'fallback'   => array(),
        );
        self::walk( $blocks, 1, $state );

        if ( $state['count'] > self::MAX_BLOCKS ) {
            $errors[] = sprintf( 'Build contains %d blocks; the limit is %d.', $state['count'], self::MAX_BLOCKS );
        }
        if ( $state['max_depth'] > self::MAX_DEPTH ) {
            $errors[] = sprintf( 'Block nesting depth is %d; the limit is %d.', $state['max_depth'], self::MAX_DEPTH );
        }
        if ( $state['unresolved'] ) {
            $errors[] = 'Unregistered blocks: ' . implode( ', ', array_unique( $state['unresolved'] ) );
        }
        if ( $state['freeform'] > 0 ) {
            $warnings[] = sprintf( '%d freeform HTML fragment(s) exist outside Gutenberg blocks.', $state['freeform'] );
        }
        if ( $state['fallback'] ) {
            $warnings[] = 'Fallback blocks detected: ' . implode( ', ', array_unique( $state['fallback'] ) ) . '. Prefer semantic registered blocks.';
        }

        return self::result(
            $errors,
            $warnings,
            self::summarize( $blocks ),
            $state['count'],
            $state['max_depth'],
            array_values( array_unique( $state['fallback'] ) ),
            $state['freeform']
        );
    }

    private static function walk( array $blocks, int $depth, array &$state ): void {
        $registry = \WP_Block_Type_Registry::get_instance();

        foreach ( $blocks as $block ) {
            $name = isset( $block['blockName'] ) ? $block['blockName'] : null;
            if ( null === $name ) {
                if ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
                    $state['freeform']++;
                }
                continue;
            }

            $state['count']++;
            $state['max_depth'] = max( $state['max_depth'], $depth );

            if ( ! $registry->is_registered( $name ) ) {
                $state['unresolved'][] = $name;
            }
            if ( in_array( $name, array( 'core/html', 'core/shortcode' ), true ) ) {
                $state['fallback'][] = $name;
            }

            if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
                self::walk( $block['innerBlocks'], $depth + 1, $state );
            }
        }
    }

    private static function summarize( array $blocks ): array {
        $summary = array();
        foreach ( $blocks as $block ) {
            if ( empty( $block['blockName'] ) ) {
                continue;
            }
            $summary[] = array(
                'name'     => $block['blockName'],
                'attrs'    => isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array(),
                'children' => ! empty( $block['innerBlocks'] ) ? self::summarize( $block['innerBlocks'] ) : array(),
            );
        }
        return $summary;
    }

    private static function result( array $errors, array $warnings, array $tree, int $count, int $depth, array $fallback, int $freeform ): array {
        return array(
            'valid'              => empty( $errors ),
            'errors'             => $errors,
            'warnings'           => $warnings,
            'tree'               => $tree,
            'count'              => $count,
            'max_depth'          => $depth,
            'fallback_blocks'    => $fallback,
            'freeform_fragments' => $freeform,
        );
    }
}

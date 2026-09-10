<?php
namespace DesignCoreHub\Remote;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Config {
    public const OPTION = 'dch_remote_config';

    public static function defaults(): array {
        return array(
            'enabled'        => true,
            'owner'          => 'quochung9920',
            'repo'           => 'wordpress-design-core-hub',
            'branch'         => 'main',
            'build_root'     => 'builds',
            'site_key'       => self::derive_site_key(),
            'public_trigger' => true,
        );
    }

    public static function ensure_defaults(): array {
        $current = get_option( self::OPTION, array() );
        if ( ! is_array( $current ) ) {
            $current = array();
        }

        $config = self::sanitize( array_merge( self::defaults(), $current ) );
        update_option( self::OPTION, $config, false );
        return $config;
    }

    public static function get(): array {
        $current = get_option( self::OPTION, array() );
        if ( ! is_array( $current ) ) {
            $current = array();
        }
        return self::sanitize( array_merge( self::defaults(), $current ) );
    }

    public static function update( array $values ): array {
        $merged = array_merge( self::get(), $values );
        $config = self::sanitize( $merged );
        update_option( self::OPTION, $config, false );
        return $config;
    }

    public static function latest_path( ?array $config = null ): string {
        $config = $config ?: self::get();
        return self::join_path( $config['build_root'], $config['site_key'], 'latest.json' );
    }

    public static function build_directory( string $build_id, ?array $config = null ): string {
        $config = $config ?: self::get();
        return self::join_path( $config['build_root'], $config['site_key'], $build_id );
    }

    public static function derive_site_key(): string {
        $host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
        $path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
        $raw  = $host;

        if ( $path && '/' !== $path ) {
            $raw .= '-' . trim( $path, '/' );
        }

        $key = sanitize_title( $raw );
        return $key ?: 'wordpress-site';
    }

    public static function sanitize( array $input ): array {
        $defaults = self::defaults();

        $owner      = self::slug_part( isset( $input['owner'] ) ? (string) $input['owner'] : $defaults['owner'] );
        $repo       = self::slug_part( isset( $input['repo'] ) ? (string) $input['repo'] : $defaults['repo'] );
        $branch     = self::branch( isset( $input['branch'] ) ? (string) $input['branch'] : $defaults['branch'] );
        $build_root = self::path( isset( $input['build_root'] ) ? (string) $input['build_root'] : $defaults['build_root'] );
        $site_key   = self::site_key( isset( $input['site_key'] ) ? (string) $input['site_key'] : $defaults['site_key'] );

        return array(
            'enabled'        => self::to_bool( $input['enabled'] ?? $defaults['enabled'] ),
            'owner'          => $owner ?: $defaults['owner'],
            'repo'           => $repo ?: $defaults['repo'],
            'branch'         => $branch ?: $defaults['branch'],
            'build_root'     => $build_root ?: $defaults['build_root'],
            'site_key'       => $site_key ?: $defaults['site_key'],
            'public_trigger' => self::to_bool( $input['public_trigger'] ?? $defaults['public_trigger'] ),
        );
    }

    public static function public_view( ?array $config = null ): array {
        $config = $config ?: self::get();
        return array(
            'enabled'        => (bool) $config['enabled'],
            'provider'       => 'github-public-raw',
            'repository'     => $config['owner'] . '/' . $config['repo'],
            'branch'         => $config['branch'],
            'site_key'       => $config['site_key'],
            'latest_path'    => self::latest_path( $config ),
            'public_trigger' => (bool) $config['public_trigger'],
        );
    }

    public static function join_path( ...$parts ): string {
        $clean = array();
        foreach ( $parts as $part ) {
            $part = trim( (string) $part, '/' );
            if ( '' !== $part ) {
                $clean[] = $part;
            }
        }
        return implode( '/', $clean );
    }

    private static function slug_part( string $value ): string {
        $value = trim( $value );
        if ( ! preg_match( '/^[A-Za-z0-9_.-]{1,100}$/', $value ) ) {
            return '';
        }
        return $value;
    }

    private static function branch( string $value ): string {
        $value = trim( $value );
        if ( ! preg_match( '/^[A-Za-z0-9_.-]{1,120}$/', $value ) ) {
            return '';
        }
        return $value;
    }

    private static function site_key( string $value ): string {
        $value = strtolower( trim( $value ) );
        if ( ! preg_match( '/^[a-z0-9][a-z0-9._-]{0,119}$/', $value ) ) {
            return '';
        }
        return $value;
    }

    private static function path( string $value ): string {
        $value = trim( str_replace( '\\', '/', $value ), '/' );
        if ( '' === $value || false !== strpos( $value, '..' ) ) {
            return '';
        }

        $parts = explode( '/', $value );
        foreach ( $parts as $part ) {
            if ( ! preg_match( '/^[A-Za-z0-9_.-]{1,100}$/', $part ) ) {
                return '';
            }
        }
        return implode( '/', $parts );
    }

    private static function to_bool( $value ): bool {
        if ( is_bool( $value ) ) {
            return $value;
        }
        if ( is_numeric( $value ) ) {
            return (bool) $value;
        }
        return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
    }
}

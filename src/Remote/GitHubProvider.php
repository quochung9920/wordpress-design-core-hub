<?php
namespace DesignCoreHub\Remote;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GitHubProvider {
    private const DEFAULT_LIMIT = 262144;

    /** @var array */
    private $config;

    public function __construct( ?array $config = null ) {
        $this->config = $config ?: Config::get();
    }

    public function fetch_text( string $path, int $limit = self::DEFAULT_LIMIT, bool $cache_bust = false ) {
        if ( ! $this->is_safe_path( $path ) ) {
            return new WP_Error( 'dch_invalid_remote_path', 'Remote build path is invalid.' );
        }

        $limit = max( 1024, min( 4194304, $limit ) );
        $url   = $this->raw_url( $path );
        if ( $cache_bust ) {
            $url = add_query_arg( 'dch', (string) time(), $url );
        }

        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'             => 15,
                'redirection'         => 2,
                'limit_response_size' => $limit,
                'headers'             => array(
                    'Accept'       => 'text/plain, application/json;q=0.9, */*;q=0.1',
                    'User-Agent'   => 'WordPress-Design-Core-Hub/' . DCH_VERSION . '; ' . home_url( '/' ),
                    'Cache-Control'=> $cache_bust ? 'no-cache' : 'max-age=60',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'dch_remote_request_failed', $response->get_error_message() );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $body   = (string) wp_remote_retrieve_body( $response );

        if ( 404 === $status ) {
            return new WP_Error( 'dch_remote_not_found', 'Remote build file was not found: ' . $path );
        }
        if ( 200 !== $status ) {
            return new WP_Error( 'dch_remote_http_error', sprintf( 'GitHub returned HTTP %d for %s.', $status, $path ) );
        }
        if ( strlen( $body ) >= $limit ) {
            return new WP_Error( 'dch_remote_file_too_large', 'Remote build file reached the configured response-size limit.' );
        }

        return $body;
    }

    public function fetch_json( string $path, int $limit = self::DEFAULT_LIMIT, bool $cache_bust = false ) {
        $raw = $this->fetch_text( $path, $limit, $cache_bust );
        if ( is_wp_error( $raw ) ) {
            return $raw;
        }

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) || JSON_ERROR_NONE !== json_last_error() ) {
            return new WP_Error( 'dch_invalid_remote_json', 'Remote JSON could not be decoded: ' . $path );
        }

        return array(
            'raw'  => $raw,
            'data' => $decoded,
        );
    }

    public function raw_url( string $path ): string {
        $segments = array_map( 'rawurlencode', explode( '/', trim( $path, '/' ) ) );
        return sprintf(
            'https://raw.githubusercontent.com/%s/%s/%s/%s',
            rawurlencode( $this->config['owner'] ),
            rawurlencode( $this->config['repo'] ),
            rawurlencode( $this->config['branch'] ),
            implode( '/', $segments )
        );
    }

    private function is_safe_path( string $path ): bool {
        $path = trim( str_replace( '\\', '/', $path ), '/' );
        if ( '' === $path || false !== strpos( $path, '..' ) || 500 < strlen( $path ) ) {
            return false;
        }

        foreach ( explode( '/', $path ) as $segment ) {
            if ( ! preg_match( '/^[A-Za-z0-9_.-]{1,140}$/', $segment ) ) {
                return false;
            }
        }
        return true;
    }
}

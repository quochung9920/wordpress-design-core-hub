<?php
/**
 * Plugin Name: WordPress Design Core Hub
 * Plugin URI: https://github.com/quochung9920/wordpress-design-core-hub
 * Description: Browser-optimized Gutenberg build studio for creating high-fidelity WordPress draft pages from reference HTML and AI-generated block plans.
 * Version: 0.1.0
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Author: Design Core Hub
 * License: GPL-2.0-or-later
 * Text Domain: wordpress-design-core-hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'DCH_VERSION', '0.1.0' );
define( 'DCH_FILE', __FILE__ );
define( 'DCH_DIR', plugin_dir_path( __FILE__ ) );
define( 'DCH_URL', plugin_dir_url( __FILE__ ) );
define( 'DCH_CAPABILITY', 'design_core_hub_build' );

spl_autoload_register(
    static function ( $class ) {
        $prefix = 'DesignCoreHub\\';
        if ( 0 !== strpos( $class, $prefix ) ) {
            return;
        }

        $relative = substr( $class, strlen( $prefix ) );
        $path     = DCH_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

        if ( is_readable( $path ) ) {
            require_once $path;
        }
    }
);

register_activation_hook(
    __FILE__,
    static function () {
        $role = get_role( 'administrator' );
        if ( $role && ! $role->has_cap( DCH_CAPABILITY ) ) {
            $role->add_cap( DCH_CAPABILITY );
        }
    }
);

add_action(
    'plugins_loaded',
    static function () {
        DesignCoreHub\Plugin::instance()->boot();
    }
);

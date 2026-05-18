<?php
/**
 * Plugin Name: WebP Image Optimizer
 * Plugin URI:  https://github.com/Tomasxwav/WebP-Optimizer
 * Description: Escanea y optimiza todas las imágenes WebP de la biblioteca de medios reduciendo su peso sin pérdida visual apreciable.
 * Version:     1.2.2
 * Author:      Tomasxwav
 * License:     GPL v2 or later
 * Text Domain: webp-optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WEBP_OPTIMIZER_VERSION', '1.2.2' );
define( 'WEBP_OPTIMIZER_PATH', plugin_dir_path( __FILE__ ) );
define( 'WEBP_OPTIMIZER_URL', plugin_dir_url( __FILE__ ) );

require_once WEBP_OPTIMIZER_PATH . 'includes/class-webp-optimizer.php';
require_once WEBP_OPTIMIZER_PATH . 'includes/class-admin.php';

register_activation_hook( __FILE__, array( 'WebP_Optimizer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WebP_Optimizer', 'deactivate' ) );

add_action( 'plugins_loaded', function () {
    new WebP_Optimizer_Admin();
} );

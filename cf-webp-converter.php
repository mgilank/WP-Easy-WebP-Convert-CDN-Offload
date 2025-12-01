<?php
/**
 * Plugin Name: Easy WebP Converter & CDN Offload
 * Plugin URI:  https://example.com
 * Description: Convert images to WebP format using local PHP (GD/ImageMagick) or external API, with optional R2/CDN offloading.
 * Version:     1.0.0
 * Author:      Your Name
 * Author URI:  https://example.com
 * License:     GPL2
 * Text Domain: cf-webp-converter
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'CF_WEBP_VERSION', '1.0.0' );
define( 'CF_WEBP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CF_WEBP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Autoloader or require statements
require_once CF_WEBP_PLUGIN_DIR . 'includes/class-cf-webp-activator.php';
require_once CF_WEBP_PLUGIN_DIR . 'admin/class-cf-webp-admin.php';
require_once CF_WEBP_PLUGIN_DIR . 'includes/class-cf-webp-worker-client.php';
require_once CF_WEBP_PLUGIN_DIR . 'includes/class-cf-webp-r2-client.php';
require_once CF_WEBP_PLUGIN_DIR . 'includes/class-cf-webp-processor.php';
require_once CF_WEBP_PLUGIN_DIR . 'includes/class-cf-webp-bulk-handler.php';

/**
 * The code that runs during plugin activation.
 */
function activate_cf_webp_converter() {
	Cf_Webp_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_cf_webp_converter() {
	// Cf_Webp_Activator::deactivate();
}

register_activation_hook( __FILE__, 'activate_cf_webp_converter' );
register_deactivation_hook( __FILE__, 'deactivate_cf_webp_converter' );

/**
 * Begins execution of the plugin.
 */
function run_cf_webp_converter() {
	$plugin_admin = new Cf_Webp_Admin();
	$plugin_admin->run();

    $plugin_processor = new Cf_Webp_Processor();
    $plugin_processor->run();

    $plugin_bulk = new Cf_Webp_Bulk_Handler();
    $plugin_bulk->run();
}

run_cf_webp_converter();

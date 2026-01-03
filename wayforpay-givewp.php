<?php
/**
 * Plugin Name: Wayforpay Gateway for GiveWP
 * Description: An implementation of Wayforpay as a GiveWP payment gateway.
 * Version: 0.1.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Requires Plugins: give
 * Author: Ukrainian Charity Alliance
 * Author URI: https://uba.com.ua
 * Text Domain: wayforpay-givewp
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('WAYFORPAY_GIVEWP_VERSION', '0.1.0');
define('WAYFORPAY_GIVEWP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WAYFORPAY_GIVEWP_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load dependencies
if (file_exists(WAYFORPAY_GIVEWP_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once WAYFORPAY_GIVEWP_PLUGIN_DIR . 'vendor/autoload.php';
}
require_once WAYFORPAY_GIVEWP_PLUGIN_DIR . 'includes/WayforpaySettings.php';

/**
 * Register Wayforpay settings when GiveWP initializes.
 */
add_action('give_init', static function () {
    WayforpaySettings::register();
});

/**
 * Register Wayforpay payment gateway with GiveWP.
 */
add_action('givewp_register_payment_gateway', static function ($paymentGatewayRegister) {
    require_once WAYFORPAY_GIVEWP_PLUGIN_DIR . 'includes/WayforpayGateway.php';
    $paymentGatewayRegister->registerGateway(WayforpayGateway::class);
});

<?php
/**
 * PHPUnit bootstrap file for Wayforpay-GiveWP tests.
 *
 * Loads WordPress test environment and activates required plugins.
 */

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Check for wp-tests-config.php
if (!file_exists(__DIR__ . '/wp-tests-config.php')) {
    die(
        "Error: tests/wp-tests-config.php not found.\n" .
        "Copy tests/wp-tests-config.dist.php to tests/wp-tests-config.php and configure your test database.\n"
    );
}

// Define test config path for WordPress test suite
define('WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php');

// Path to WordPress test suite
$wpTestsDir = dirname(__DIR__) . '/vendor/wordpress/wordpress/tests/phpunit';
if (!file_exists($wpTestsDir . '/includes/functions.php')) {
    die(
        "Error: WordPress test suite not found.\n" .
        "Run 'composer install' to download the WordPress development package.\n"
    );
}

// Load WordPress test functions (provides tests_add_filter)
require_once $wpTestsDir . '/includes/functions.php';

/**
 * Load GiveWP and Wayforpay gateway before WordPress is loaded.
 */
tests_add_filter('muplugins_loaded', function () {
    // Load GiveWP from vendor (installed via wpackagist)
    $givePluginPath = dirname(__DIR__) . '/vendor/wpackagist-plugin/give/give.php';
    if (file_exists($givePluginPath)) {
        require_once $givePluginPath;
    } else {
        die("Error: GiveWP plugin not found at: {$givePluginPath}\n");
    }

    // Load Wayforpay gateway plugin
    require_once dirname(__DIR__) . '/wayforpay-givewp.php';
});

/**
 * Install GiveWP after theme setup.
 */
tests_add_filter('setup_theme', function () {
    echo "Installing GiveWP for tests...\n";
    if (function_exists('give')) {
        give()->install();
    }
});

// Load WordPress test suite bootstrap
require_once $wpTestsDir . '/includes/bootstrap.php';

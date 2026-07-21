<?php
/**
 * WordPress test configuration.
 *
 * Copy this file to wp-tests-config.php and configure your test database.
 * WARNING: The test database will have all tables dropped during testing!
 */

/* Path to the WordPress codebase from wordpress-develop package */
define('ABSPATH', __DIR__ . '/../vendor/wordpress/wordpress/src/');

/* Test with WordPress debug mode */
define('WP_DEBUG', true);

/* Database settings - pre-configured for Docker (see docker-compose.yml) */
define('DB_NAME', 'wayforpay_givewp_test');
define('DB_USER', 'root');
define('DB_PASSWORD', 'root');
define('DB_HOST', '127.0.0.1:3307');
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');

/* Authentication keys - can use defaults for testing */
define('AUTH_KEY', 'test-auth-key');
define('SECURE_AUTH_KEY', 'test-secure-auth-key');
define('LOGGED_IN_KEY', 'test-logged-in-key');
define('NONCE_KEY', 'test-nonce-key');
define('AUTH_SALT', 'test-auth-salt');
define('SECURE_AUTH_SALT', 'test-secure-auth-salt');
define('LOGGED_IN_SALT', 'test-logged-in-salt');
define('NONCE_SALT', 'test-nonce-salt');

$table_prefix = 'wptests_';

define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'Test Blog');

define('WP_PHP_BINARY', 'php');

define('WPLANG', '');

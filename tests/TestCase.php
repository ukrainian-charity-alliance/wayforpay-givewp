<?php

namespace WayforpayGiveWP\Tests;

use WP_UnitTestCase;

/**
 * Base test case for Wayforpay-GiveWP tests.
 *
 * @mixin \PHPUnit\Framework\TestCase
 */
class TestCase extends WP_UnitTestCase
{
    protected const TEST_MERCHANT_ACCOUNT = 'test_merchant';
    protected const TEST_MERCHANT_SECRET = 'test_secret';

    public function setUp(): void
    {
        // Ensure the uploads directory exists so WP_UnitTestCase::tearDown()
        // doesn't fail with RecursiveDirectoryIterator on a fresh install.
        $uploadsDir = dirname(__DIR__) . '/vendor/wordpress/wordpress/src/wp-content/uploads';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }

        parent::setUp();

        // Set server variables for WordPress
        if (!isset($_SERVER['SERVER_NAME'])) {
            $_SERVER['SERVER_NAME'] = 'localhost';
        }
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

        // Configure test Wayforpay credentials
        give_update_option('wayforpay_test_merchant_account', self::TEST_MERCHANT_ACCOUNT);
        give_update_option('wayforpay_test_secret_key', self::TEST_MERCHANT_SECRET);
        give_update_option('wayforpay_test_merchant_password', 'test_password');
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * Helper to get a mock gateway instance with test credentials.
     *
     * @return \WayforpayGateway
     */
    protected function createGateway(): \WayforpayGateway
    {
        return new \WayforpayGateway();
    }
}

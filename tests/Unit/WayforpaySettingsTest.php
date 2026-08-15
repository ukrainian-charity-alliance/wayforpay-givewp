<?php

namespace WayforpayGiveWP\Tests\Unit;

use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;
use WayforpayGiveWP\Tests\TestCase;
use WayForPay\SDK\Credential\AccountSecretCredential;
use WayForPay\SDK\Credential\AccountPasswordCredential;

/**
 * Tests for WayforpaySettings credential accessors.
 */
class WayforpaySettingsTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // Exercise the test-mode credential set, which parent::setUp() populates.
        give_update_option('test_mode', 'enabled');
    }

    public function testGetCredentialsReturnsConfiguredTestCredentials(): void
    {
        $creds = \WayforpayGiveWP\WayforpaySettings::getCredentials();

        $this->assertInstanceOf(AccountSecretCredential::class, $creds);
        $this->assertEquals(self::TEST_MERCHANT_ACCOUNT, $creds->getAccount());
        $this->assertEquals(self::TEST_MERCHANT_SECRET, $creds->getSecret());
    }

    public function testGetCredentialsThrowsWhenNotConfigured(): void
    {
        give_update_option('wayforpay_test_merchant_account', '');
        give_update_option('wayforpay_test_secret_key', '');

        $this->expectException(PaymentGatewayException::class);
        $this->expectExceptionMessage('Wayforpay is not configured');

        \WayforpayGiveWP\WayforpaySettings::getCredentials();
    }

    public function testGetPasswordCredentialsReturnsConfiguredTestCredentials(): void
    {
        $creds = \WayforpayGiveWP\WayforpaySettings::getPasswordCredentials();

        $this->assertInstanceOf(AccountPasswordCredential::class, $creds);
        $this->assertEquals(self::TEST_MERCHANT_ACCOUNT, $creds->getAccount());
    }

    public function testGetPasswordCredentialsThrowsWhenNotConfigured(): void
    {
        give_update_option('wayforpay_test_merchant_password', '');

        $this->expectException(PaymentGatewayException::class);
        $this->expectExceptionMessage('Wayforpay is not configured');

        \WayforpayGiveWP\WayforpaySettings::getPasswordCredentials();
    }
}

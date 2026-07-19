<?php

namespace WayforpayGiveWP\Tests;

use Give\Campaigns\Models\Campaign;
use Give\Campaigns\ValueObjects\CampaignGoalType;
use Give\Campaigns\ValueObjects\CampaignStatus;
use Give\Campaigns\ValueObjects\CampaignType;
use Give\Donations\Models\Donation;
use Give\Donations\ValueObjects\DonationMode;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Donations\ValueObjects\DonationType;
use Give\Donors\Models\Donor;
use Give\Framework\Support\ValueObjects\Money;
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

    /**
     * Create a test campaign with sensible defaults.
     */
    protected function createTestCampaign(): Campaign
    {
        return Campaign::create([
            'type' => CampaignType::CORE(),
            'title' => 'Test Campaign',
            'shortDescription' => 'Test description',
            'logo' => '',
            'image' => '',
            'primaryColor' => '#000000',
            'secondaryColor' => '#ffffff',
            'status' => CampaignStatus::ACTIVE(),
            'goalType' => CampaignGoalType::AMOUNT(),
            'goal' => 10000,
        ]);
    }

    /**
     * Create a test donor with a unique email.
     */
    protected function createTestDonor(): Donor
    {
        return Donor::create([
            'name' => 'Test Donor',
            'firstName' => 'Test',
            'lastName' => 'Donor',
            'email' => 'test' . uniqid() . '@example.com',
        ]);
    }

    /**
     * Create a test donation with all required dependencies. Any field can be
     * overridden via $overrides (e.g. ['status' => DonationStatus::COMPLETE()]).
     */
    protected function createTestDonation(array $overrides = []): Donation
    {
        $campaign = $this->createTestCampaign();
        $donor = $this->createTestDonor();

        return Donation::create(array_merge([
            'status' => DonationStatus::PENDING(),
            'gatewayId' => 'wayforpay-gateway',
            'mode' => DonationMode::TEST(),
            'type' => DonationType::SINGLE(),
            'amount' => new Money(1000, 'USD'),
            'donorId' => $donor->id,
            'firstName' => $donor->firstName,
            'lastName' => $donor->lastName,
            'email' => $donor->email,
            'campaignId' => $campaign->id,
            'formId' => 1,
            'formTitle' => 'Test Form',
        ], $overrides));
    }
}

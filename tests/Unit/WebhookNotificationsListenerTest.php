<?php

namespace WayforpayGiveWP\Tests\Unit;

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
use Give\Subscriptions\Models\Subscription;
use Give\Subscriptions\ValueObjects\SubscriptionMode;
use Give\Subscriptions\ValueObjects\SubscriptionPeriod;
use Give\Subscriptions\ValueObjects\SubscriptionStatus;
use WayforpayGiveWP\Tests\TestCase;
use WayForPay\SDK\Domain\Reason;
use WayForPay\SDK\Helper\SignatureHelper;

/**
 * Tests for WayforpayGateway::webhookNotificationsListener()
 */
class WebhookNotificationsListenerTest extends TestCase
{
    private \WayforpayGateway $gateway;

    public function setUp(): void
    {
        parent::setUp();
        $this->gateway = $this->createGateway();
        $_GET = [];
    }

    public function tearDown(): void
    {
        $_GET = [];
        parent::tearDown();
    }

    public function testInvalidPostBody(): void
    {
        $_GET = ['donation-id' => '123'];

        $this->expectException(\WPDieException::class);
        $this->callListenerWithInput('this is not valid json');
    }

    public function testDonationIdMissing(): void
    {
        $_GET = [];

        $this->expectException(\WPDieException::class);
        $this->expectExceptionMessage('Donation ID missing');
        $this->callListenerWithInput($this->buildSignedPayload('Approved'));
    }

    public function testDonationNotFound(): void
    {
        $_GET = ['donation-id' => '999999'];

        $this->expectException(\WPDieException::class);
        $this->expectExceptionMessage('Donation not found');
        $this->callListenerWithInput($this->buildSignedPayload('Approved'));
    }

    public function testApprovedPaymentUpdatesDonationToComplete(): void
    {
        $donation = $this->createPendingDonation();
        $orderRef = 'order-' . $donation->id;
        $_GET = ['donation-id' => (string) $donation->id];

        $this->expectException(\WPDieException::class);
        ob_start();
        try {
            $this->callListenerWithInput($this->buildSignedPayload('Approved', $orderRef));
        } catch (\WPDieException $e) {
            $output = ob_get_clean();
            // Output may contain PHP warnings; extract the JSON portion.
            $json = $this->extractJson($output);
            $this->assertNotNull($json, 'Expected JSON in output');
            $ack = json_decode($json, true);
            $this->assertEquals($orderRef, $ack['orderReference']);
            $this->assertEquals('accept', $ack['status']);

            // Verify donation was updated.
            $updatedDonation = Donation::find($donation->id);
            $this->assertTrue($updatedDonation->status->isComplete());
            $this->assertEquals($orderRef, $updatedDonation->gatewayTransactionId);

            throw $e;
        }
        ob_end_clean();
    }

    public function testApprovedPaymentIsIdempotent(): void
    {
        $donation = $this->createPendingDonation();
        $donation->status = DonationStatus::COMPLETE();
        $donation->gatewayTransactionId = 'already-set';
        $donation->save();

        $_GET = ['donation-id' => (string) $donation->id];

        $this->expectException(\WPDieException::class);
        ob_start();
        try {
            $this->callListenerWithInput($this->buildSignedPayload('Approved', 'order-new'));
        } catch (\WPDieException $e) {
            ob_get_clean();

            // Donation should remain unchanged — original transactionId preserved.
            $updatedDonation = Donation::find($donation->id);
            $this->assertTrue($updatedDonation->status->isComplete());
            $this->assertEquals('already-set', $updatedDonation->gatewayTransactionId);

            throw $e;
        }
        ob_end_clean();
    }

    public function testDeclinedPaymentUpdatesDonationToFailed(): void
    {
        $donation = $this->createPendingDonation();
        $_GET = ['donation-id' => (string) $donation->id];

        $this->expectException(\WPDieException::class);
        ob_start();
        try {
            $this->callListenerWithInput($this->buildSignedPayload(
                'Declined',
                'order-declined',
                Reason::CODE_DECLINED_TO_CARD_ISSUER,
                'Declined to card issuer'
            ));
        } catch (\WPDieException $e) {
            ob_get_clean();

            $updatedDonation = Donation::find($donation->id);
            $this->assertTrue($updatedDonation->status->isFailed());

            throw $e;
        }
        ob_end_clean();
    }

    public function testExpiredPaymentUpdatesDonationToFailed(): void
    {
        $donation = $this->createPendingDonation();
        $_GET = ['donation-id' => (string) $donation->id];

        $this->expectException(\WPDieException::class);
        ob_start();
        try {
            $this->callListenerWithInput($this->buildSignedPayload(
                'Expired',
                'order-expired',
                Reason::CODE_DECLINED_TO_CARD_ISSUER,
                'Expired'
            ));
        } catch (\WPDieException $e) {
            ob_get_clean();

            $updatedDonation = Donation::find($donation->id);
            $this->assertTrue($updatedDonation->status->isFailed());

            throw $e;
        }
        ob_end_clean();
    }

    public function testUnknownStatusCreatesDonationNote(): void
    {
        $donation = $this->createPendingDonation();
        $_GET = ['donation-id' => (string) $donation->id];

        $this->expectException(\WPDieException::class);
        ob_start();
        try {
            $this->callListenerWithInput($this->buildSignedPayload('Pending', 'order-pending'));
        } catch (\WPDieException $e) {
            ob_get_clean();

            // Donation status should NOT change (still pending).
            $updatedDonation = Donation::find($donation->id);
            $this->assertTrue($updatedDonation->status->isPending());

            throw $e;
        }
        ob_end_clean();
    }

    public function testSubscriptionNotFound(): void
    {
        $donation = $this->createPendingDonation();
        $_GET = [
            'donation-id' => (string) $donation->id,
            'subscription-id' => '999999',
        ];

        $this->expectException(\WPDieException::class);
        $this->expectExceptionMessage('Subscription not found');
        $this->callListenerWithInput($this->buildSignedPayload('Approved'));
    }

    public function testRenewalIdempotency(): void
    {
        // 1. Create a completed initial donation with a subscription.
        $donation = $this->createPendingDonation();
        $subscription = $this->createSubscriptionForDonation($donation);

        // Link the initial donation to the subscription and mark as complete.
        $donation->type = DonationType::SUBSCRIPTION();
        $donation->subscriptionId = $subscription->id;
        $donation->status = DonationStatus::COMPLETE();
        $donation->gatewayTransactionId = 'initial-order';
        $donation->save();

        // 2. Simulate a prior renewal by creating a donation with the same orderReference.
        $existingOrderRef = 'renewal-order-123';
        Donation::create([
            'status' => DonationStatus::COMPLETE(),
            'gatewayId' => 'wayforpay-gateway',
            'mode' => DonationMode::TEST(),
            'type' => DonationType::RENEWAL(),
            'amount' => $donation->amount,
            'donorId' => $donation->donorId,
            'firstName' => $donation->firstName,
            'lastName' => $donation->lastName,
            'email' => $donation->email,
            'campaignId' => $donation->campaignId,
            'formId' => 1,
            'formTitle' => 'Test Form',
            'subscriptionId' => $subscription->id,
            'gatewayTransactionId' => $existingOrderRef,
        ]);

        // Count donations before webhook call.
        $donationCountBefore = $subscription->donations()->count();

        $_GET = [
            'donation-id' => (string) $donation->id,
            'subscription-id' => (string) $subscription->id,
        ];

        // 3. Call the webhook with the SAME orderReference as the existing renewal.
        $this->expectException(\WPDieException::class);
        ob_start();
        try {
            $this->callListenerWithInput($this->buildSignedPayload('Approved', $existingOrderRef));
        } catch (\WPDieException $e) {
            ob_get_clean();

            // 4. Assert no new donations were created.
            $donationCountAfter = $subscription->donations()->count();
            $this->assertEquals($donationCountBefore, $donationCountAfter, 'No duplicate renewal should be created');

            throw $e;
        }
        ob_end_clean();
    }

    private function extractJson(string $output): ?string
    {
        $start = strpos($output, '{');
        $end = strrpos($output, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $candidate = substr($output, $start, $end - $start + 1);
        return json_decode($candidate) !== null ? $candidate : null;
    }

    /**
     * Call webhookNotificationsListener with faked php://input content.
     *
     * Registers a custom stream wrapper for the 'php' protocol ONLY for the duration
     * of the call, then restores the original wrapper immediately, even if an exception
     * is thrown. This ensures the DB connection (which may use php://temp) is not broken.
     */
    private function callListenerWithInput(string $inputContent): void
    {
        PhpInputStreamWrapper::$content = $inputContent;
        PhpInputStreamWrapper::$position = 0;

        stream_wrapper_unregister('php');
        stream_wrapper_register('php', PhpInputStreamWrapper::class);
        try {
            $this->gateway->webhookNotificationsListener();
        } finally {
            stream_wrapper_unregister('php');
            stream_wrapper_restore('php');
        }
    }

    private function buildSignedPayload(
        string $transactionStatus,
        string $orderReference = 'test-order-123',
        int $reasonCode = Reason::CODE_OK,
        string $reason = 'OK',
        float $amount = 10.00,
        string $currency = 'USD'
    ): string {
        $authCode = '123456';
        $cardPan = '4111****1111';
        $now = time();
        $signature = SignatureHelper::calculateSignature(
            [
                self::TEST_MERCHANT_ACCOUNT,
                $orderReference,
                $amount,
                $currency,
                $authCode,
                $cardPan,
                $transactionStatus,
                $reasonCode,
            ],
            self::TEST_MERCHANT_SECRET
        );
        return json_encode([
            'merchantAccount' => self::TEST_MERCHANT_ACCOUNT,
            'orderReference' => $orderReference,
            'merchantSignature' => $signature,
            'amount' => $amount,
            'currency' => $currency,
            'authCode' => $authCode,
            'cardPan' => $cardPan,
            'transactionStatus' => $transactionStatus,
            'reasonCode' => $reasonCode,
            'reason' => $reason,
            'createdDate' => $now,
            'processingDate' => $now,
        ]);
    }

    /**
     * Create a test donation in PENDING status with all required dependencies.
     */
    private function createPendingDonation(): Donation
    {
        $campaign = Campaign::create([
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
        $email = 'test' . uniqid() . '@example.com';
        $donor = Donor::create([
            'name' => 'Test Donor',
            'firstName' => 'Test',
            'lastName' => 'Donor',
            'email' => $email,
        ]);
        return Donation::create([
            'status' => DonationStatus::PENDING(),
            'gatewayId' => 'wayforpay-gateway',
            'mode' => DonationMode::TEST(),
            'type' => DonationType::SINGLE(),
            'amount' => new Money(1000, 'USD'),
            'donorId' => $donor->id,
            'firstName' => 'Test',
            'lastName' => 'Donor',
            'email' => $email,
            'campaignId' => $campaign->id,
            'formId' => 1,
            'formTitle' => 'Test Form',
        ]);
    }

    /**
     * Create a test subscription linked to an existing donation.
     */
    private function createSubscriptionForDonation(Donation $donation): Subscription
    {
        return Subscription::create([
            'donationFormId' => $donation->formId,
            'campaignId' => $donation->campaignId,
            'period' => SubscriptionPeriod::MONTH(),
            'frequency' => 1,
            'donorId' => $donation->donorId,
            'installments' => 0,
            'amount' => $donation->amount,
            'status' => SubscriptionStatus::ACTIVE(),
            'mode' => SubscriptionMode::TEST(),
            'gatewayId' => 'wayforpay-gateway',
        ]);
    }
}

class PhpInputStreamWrapper
{
    /** @var string Content to serve as php://input */
    public static string $content = '';

    /** @var int Current read position */
    public static int $position = 0;

    /** @var resource|null Stream context */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        self::$position = 0;
        return true;
    }

    public function stream_read(int $count): string
    {
        $data = substr(self::$content, self::$position, $count);
        self::$position += strlen($data);
        return $data;
    }

    public function stream_eof(): bool
    {
        return self::$position >= strlen(self::$content);
    }

    public function stream_stat(): array
    {
        return [];
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return true;
    }
}

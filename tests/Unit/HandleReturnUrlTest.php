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
use WayforpayGiveWP\Tests\TestCase;
use WayForPay\SDK\Domain\Reason;
use WayForPay\SDK\Helper\SignatureHelper;

/**
 * Tests for WayforpayGateway::handleReturnUrl()
 */
class HandleReturnUrlTest extends TestCase
{
    private \WayforpayGateway $gateway;

    public function setUp(): void
    {
        parent::setUp();
        $this->gateway = $this->createGateway();
    }

    public function testThrowsExceptionWhenDonationIdMissing(): void
    {
        $_POST = ['some' => 'data'];

        $this->expectException(\Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException::class);
        $this->expectExceptionMessage('no donation-id parameter received from Wayforpay');

        $this->invokeHandleReturnUrl([]);
    }

    public function testThrowsExceptionWhenPostDataEmpty(): void
    {
        $_POST = [];

        $this->expectException(\Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException::class);
        $this->expectExceptionMessage('no data received from Wayforpay');

        $this->invokeHandleReturnUrl(['donation-id' => 123]);
    }

    public function testPaymentSuccessful(): void
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
        $donation = Donation::create([
            'status' => DonationStatus::PENDING(),
            'gatewayId' => 'test-gateway',
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

        $_POST = [
            'orderReference' => 'test',
            'transactionStatus' => 'Approved',
            'reasonCode' => Reason::CODE_OK,
            'reason' => 'OK',
            'amount' => 1000,
            'currency' => 'USD',
            'createdDate' => time(),
            'processingDate' => time(),
        ];
        $response = $this->invokeHandleReturnUrl(['donation-id' => $donation->id]);

        $this->assertInstanceOf(\Give\Framework\Http\Response\Types\RedirectResponse::class, $response);
        $this->assertEquals(give_get_success_page_uri(), $response->getTargetUrl());
    }

    public function testPaymentFailed(): void
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
        $donation = Donation::create([
            'status' => DonationStatus::PENDING(),
            'gatewayId' => 'test-gateway',
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

        $_POST = [
            'orderReference' => 'test',
            'transactionStatus' => 'Declined',
            'reasonCode' => Reason::CODE_DECLINED_TO_CARD_ISSUER,
            'reason' => 'Declined to card issuer',
            'amount' => 1000,
            'currency' => 'USD',
            'createdDate' => time(),
            'processingDate' => time(),
        ];
        $response = $this->invokeHandleReturnUrl(['donation-id' => $donation->id]);

        $this->assertInstanceOf(\Give\Framework\Http\Response\Types\RedirectResponse::class, $response);
        $redirectUrl = $response->getTargetUrl();
        $this->assertStringStartsWith(give_get_failed_transaction_uri(), $redirectUrl);
        $this->assertStringContainsString('gateway-error=', $redirectUrl);
        $this->assertStringContainsString('Declined+by+card+issuer', $redirectUrl);
    }

    public function testPendingPaymentRedirectsToReceiptPageNotFailure(): void
    {
        // A card that isn't approved immediately bounces the donor back here while still processing.
        // This is not a failure — the webhook is authoritative — so the donor should see the receipt page.
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
        $donation = Donation::create([
            'status' => DonationStatus::PENDING(),
            'gatewayId' => 'test-gateway',
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

        $_POST = [
            'orderReference' => 'test',
            'transactionStatus' => 'Pending',
            'reasonCode' => Reason::CODE_TRANSACTION_PENDING,
            'reason' => 'Transaction pending',
            'amount' => 1000,
            'currency' => 'USD',
            'createdDate' => time(),
            'processingDate' => time(),
        ];
        $response = $this->invokeHandleReturnUrl(['donation-id' => $donation->id]);

        $this->assertInstanceOf(\Give\Framework\Http\Response\Types\RedirectResponse::class, $response);
        $redirectUrl = $response->getTargetUrl();
        $this->assertStringStartsWith(give_get_success_page_uri(), $redirectUrl);
        // The receipt page can read this param to show a "payment is processing" notice.
        $this->assertStringContainsString('gateway-status=pending', $redirectUrl);
    }

    public function testCancelledPaymentRedirectsToFailurePageEvenWhenStatusIsInFlight(): void
    {
        // A cancellation can come back with an in-flight status (e.g. Pending) but the cardholder-cancelled
        // reason code. It must go to the failure page, not be mistaken for a still-processing payment.
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
        $donation = Donation::create([
            'status' => DonationStatus::PENDING(),
            'gatewayId' => 'test-gateway',
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

        $_POST = [
            'orderReference' => 'test',
            'transactionStatus' => 'Pending',
            'reasonCode' => Reason::CODE_CARDHOLDER_CANCELLED_REQUEST,
            'reason' => 'Cardholder cancelled request',
            'amount' => 1000,
            'currency' => 'USD',
            'createdDate' => time(),
            'processingDate' => time(),
        ];
        $response = $this->invokeHandleReturnUrl(['donation-id' => $donation->id]);

        $this->assertInstanceOf(\Give\Framework\Http\Response\Types\RedirectResponse::class, $response);
        $redirectUrl = $response->getTargetUrl();
        $this->assertStringStartsWith(give_get_failed_transaction_uri(), $redirectUrl);
        $this->assertStringContainsString('gateway-error=Payment+cancelled', $redirectUrl);
    }

    public function testRedirectsToFailedPageWhenUserCancelled(): void
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
        $donation = Donation::create([
            'status' => DonationStatus::PENDING(),
            'gatewayId' => 'test-gateway',
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

        $_POST = ['orderReference' => 'test'];
        $response = $this->invokeHandleReturnUrl(['donation-id' => $donation->id]);

        $this->assertInstanceOf(\Give\Framework\Http\Response\Types\RedirectResponse::class, $response);

        $redirectUrl = $response->getTargetUrl();
        $this->assertStringStartsWith(give_get_failed_transaction_uri(), $redirectUrl);
        $this->assertStringContainsString('gateway-error=Payment+cancelled', $redirectUrl);
    }

    public function testSignedApprovedResponseRedirectsToSuccessPage(): void
    {
        // When Wayforpay includes a merchantSignature, handleReturnUrl verifies it via
        // ServiceUrlHandler before deciding the redirect. A valid signature + Approved
        // status should reach the success page.
        $donation = $this->createTestDonation();

        $_POST = $this->buildSignedPost('Approved', Reason::CODE_OK, 'OK');
        $response = $this->invokeHandleReturnUrl(['donation-id' => $donation->id]);

        $this->assertInstanceOf(\Give\Framework\Http\Response\Types\RedirectResponse::class, $response);
        $this->assertEquals(give_get_success_page_uri(), $response->getTargetUrl());
    }

    public function testInvalidSignatureThrows(): void
    {
        // A merchantSignature that doesn't match the payload must be rejected.
        $donation = $this->createTestDonation();

        $data = $this->buildSignedPost('Approved', Reason::CODE_OK, 'OK');
        $data['merchantSignature'] = 'deadbeefinvalidsignature';
        $_POST = $data;

        $this->expectException(\Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException::class);
        $this->expectExceptionMessage('invalid signature received from Wayforpay');

        $this->invokeHandleReturnUrl(['donation-id' => $donation->id]);
    }

    /**
     * Build a POST payload signed with the test merchant secret, mirroring what
     * Wayforpay sends to the returnUrl when a signature is included.
     */
    private function buildSignedPost(string $transactionStatus, int $reasonCode, string $reason): array
    {
        $orderReference = 'test-order-123';
        $amount = 1000;
        $currency = 'USD';
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

        return [
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
        ];
    }

    /**
     * @param array $queryParams
     * @return \Give\Framework\Http\Response\Types\RedirectResponse
     */
    private function invokeHandleReturnUrl(array $queryParams): \Give\Framework\Http\Response\Types\RedirectResponse
    {
        $reflection = new \ReflectionClass($this->gateway);
        $method = $reflection->getMethod('handleReturnUrl');
        $method->setAccessible(true);

        return $method->invoke($this->gateway, $queryParams);
    }
}


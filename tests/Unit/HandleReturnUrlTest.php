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


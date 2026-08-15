<?php

namespace WayforpayGiveWP\Tests\Unit;

use Give\Subscriptions\Models\Subscription;
use Give\Subscriptions\ValueObjects\SubscriptionMode;
use Give\Subscriptions\ValueObjects\SubscriptionPeriod;
use Give\Subscriptions\ValueObjects\SubscriptionStatus;
use WayforpayGiveWP\Tests\TestCase;

/**
 * Tests for WayforpayGateway::cancelSubscription()
 */
class CancelSubscriptionTest extends TestCase
{
    private \WayforpayGiveWP\WayforpayGateway $gateway;

    public function setUp(): void
    {
        parent::setUp();
        $this->gateway = $this->createGateway();
    }

    /**
     * When a subscription has no gatewaySubscriptionId (order reference), there is nothing
     * to remove on Wayforpay's side. It should be marked cancelled locally without any
     * outbound request.
     */
    public function testCancelsLocallyWhenNoOrderReference(): void
    {
        $subscription = $this->createSubscription();
        // No gatewaySubscriptionId set.

        $this->gateway->cancelSubscription($subscription);

        $updated = Subscription::find($subscription->id);
        $this->assertTrue($updated->status->isCancelled());
    }

    private function createSubscription(): Subscription
    {
        $donation = $this->createTestDonation();

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

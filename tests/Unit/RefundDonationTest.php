<?php

namespace WayforpayGiveWP\Tests\Unit;

use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;
use WayforpayGiveWP\Tests\TestCase;

/**
 * Tests for WayforpayGateway::refundDonation()
 */
class RefundDonationTest extends TestCase
{
    private \WayforpayGateway $gateway;

    public function setUp(): void
    {
        parent::setUp();
        $this->gateway = $this->createGateway();
    }

    /**
     * A donation that never completed has no gatewayTransactionId (order reference),
     * so the refund must be rejected before any request to Wayforpay is attempted.
     */
    public function testThrowsWhenOrderReferenceMissing(): void
    {
        $donation = $this->createTestDonation();
        // No gatewayTransactionId set — refund has nothing to reference.

        $this->expectException(PaymentGatewayException::class);
        $this->expectExceptionMessage('cannot process refund: required transaction data is missing.');

        $this->gateway->refundDonation($donation);
    }
}

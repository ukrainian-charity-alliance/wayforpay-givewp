<?php

namespace WayforpayGiveWP\Tests\Unit;

use WayforpayGiveWP\Tests\TestCase;
use WayForPay\SDK\Domain\Reason;

/**
 * General tests for WayforpayGateway
 */
class WayforpayGatewayTest extends TestCase
{
    private \WayforpayGateway $gateway;

    public function setUp(): void
    {
        parent::setUp();
        $this->gateway = $this->createGateway();
    }

    public function testGatewayId(): void
    {
        $this->assertEquals('wayforpay-gateway', $this->gateway->getId());
    }

    public function testStaticId(): void
    {
        $this->assertEquals('wayforpay-gateway', \WayforpayGateway::id());
    }

    public function testGatewayName(): void
    {
        $name = $this->gateway->getName();
        $this->assertIsString($name);
        $this->assertNotEmpty($name);
    }

    public function testPaymentMethodLabel(): void
    {
        $label = $this->gateway->getPaymentMethodLabel();
        $this->assertIsString($label);
        $this->assertNotEmpty($label);
    }

    public function testSupportsSubscriptions(): void
    {
        $this->assertTrue($this->gateway->supportsSubscriptions());
    }

    /**
     * @dataProvider nextDateProvider
     */
    public function testCalculateNextDate(string $period, int $frequency): void
    {
        $reflection = new \ReflectionClass($this->gateway);
        $method = $reflection->getMethod('calculateNextDate');
        $method->setAccessible(true);

        $result = $method->invoke($this->gateway, $period, $frequency);

        $date = new \DateTime($result);
        $now = new \DateTime();

        $this->assertGreaterThan($now, $date, "Next date should be in the future");
    }

    public static function nextDateProvider(): array
    {
        return [
            'daily' => ['day', 1],
            'weekly' => ['week', 1],
            'monthly' => ['month', 1],
            'quarterly' => ['quarter', 1],
            'yearly' => ['year', 1],
            'bi-weekly' => ['week', 2],
            'bi-monthly' => ['month', 2],
        ];
    }

    /**
     * @dataProvider displayErrorMessageProvider
     */
    public function testGetDisplayErrorMessage(int $code, string $message, string $expected): void
    {
        $reflection = new \ReflectionClass($this->gateway);
        $method = $reflection->getMethod('getDisplayErrorMessage');
        $method->setAccessible(true);

        $result = $method->invoke($this->gateway, new Reason($code, $message));

        $this->assertEquals($expected, $result);
    }

    public static function displayErrorMessageProvider(): array
    {
        return [
            // Known codes map to friendly, translatable strings (raw Wayforpay message ignored).
            'declined by issuer' => [Reason::CODE_DECLINED_TO_CARD_ISSUER, 'raw', 'Declined by card issuer'],
            'bad cvv' => [Reason::CODE_BAD_CVV2, 'raw', 'Invalid CVV code'],
            'expired card' => [Reason::CODE_EXPIRED_CARD, 'raw', 'Card expired'],
            'insufficient funds' => [Reason::CODE_INSUFFICIENT_FUNDS, 'raw', 'Insufficient funds'],
            '3ds fail' => [Reason::CODE_3DS_FAIL, 'raw', '3D Secure verification failed'],
            // Unknown code falls back to the raw Wayforpay message when present.
            'unknown with message' => [999999, 'Some raw reason', 'Some raw reason'],
            // Unknown code with no message falls back to the generic default.
            'unknown without message' => [999999, '', 'Payment declined'],
        ];
    }
}


<?php

namespace WayforpayGiveWP\Tests\Unit;

use WayforpayGiveWP\Tests\TestCase;

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
}


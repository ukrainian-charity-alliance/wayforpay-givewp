<?php

namespace WayforpayGiveWP\Tests\Unit;

use Give\Donations\Models\Donation;
use Give\Framework\PaymentGateways\Commands\RedirectOffsite;
use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;
use WayforpayGiveWP\Tests\TestCase;
use WpOrg\Requests\Utility\CaseInsensitiveDictionary;

/**
 * Tests for the server-side request that obtains the Wayforpay payment page URL.
 */
class RedirectToWayforpayTest extends TestCase
{
    private \WayforpayGiveWP\WayforpayGateway $gateway;

    public function setUp(): void
    {
        parent::setUp();
        $this->gateway = $this->createGateway();
    }

    /**
     * Answer the gateway's wp_remote_post() with a canned response. Headers are a
     * CaseInsensitiveDictionary, as they are for a real response.
     */
    private function mockWayforpayResponse(int $code, array $headers, string $body = ''): void
    {
        add_filter('pre_http_request', static fn () => [
            'headers' => new CaseInsensitiveDictionary($headers),
            'body' => $body,
            'response' => ['code' => $code, 'message' => ''],
            'cookies' => [],
            'filename' => null,
        ]);
    }

    private function getNotesContent(Donation $donation): string
    {
        return implode("\n", array_map(static fn ($note) => $note->content, $donation->notes()->getAll()));
    }

    public function testRedirectsDonorToLocationProvidedByWayforpay(): void
    {
        $this->mockWayforpayResponse(302, ['location' => 'https://secure.wayforpay.com/page?vkh=abc']);
        $donation = $this->createTestDonation();

        $result = $this->gateway->createPayment($donation, []);

        $this->assertInstanceOf(RedirectOffsite::class, $result);
        $this->assertSame('https://secure.wayforpay.com/page?vkh=abc', $result->redirectUrl);
    }

    public function testNonRedirectResponseLogsDiagnosticHeaders(): void
    {
        // What Cloudflare in front of Wayforpay returns when it rate limits or challenges the request.
        $this->mockWayforpayResponse(
            429,
            [
                'server' => 'cloudflare',
                'content-type' => 'text/html; charset=UTF-8',
                'cf-ray' => 'a45691823c500215-ZRH',
                'cf-mitigated' => 'challenge',
                'retry-after' => '60',
                'set-cookie' => 'PHPSESSID=donor-session',
            ],
            '<!DOCTYPE html><html lang="en-US"><head><title>Just a moment...</title>'
                . '<script>' . str_repeat('x', 5000) . '</script></head><body>Enable JavaScript and cookies to continue</body></html>'
        );
        $donation = $this->createTestDonation();

        try {
            $this->gateway->createPayment($donation, []);
            $this->fail('Expected PaymentGatewayException');
        } catch (PaymentGatewayException $e) {
            // Expected.
        }

        $notes = $this->getNotesContent($donation);
        $this->assertStringContainsString('Expected Wayforpay to redirect but got HTTP 429', $notes);
        $this->assertStringContainsString('"cf-ray":"a45691823c500215-ZRH"', $notes);
        $this->assertStringContainsString('"cf-mitigated":"challenge"', $notes);
        $this->assertStringContainsString('"retry-after":"60"', $notes);
        $this->assertStringContainsString('"server":"cloudflare"', $notes);
        // The page is reduced to a short plain-text excerpt; scripts and cookies stay out of the note.
        $this->assertStringContainsString('Just a moment...', $notes);
        $this->assertStringNotContainsString('<script>', $notes);
        $this->assertStringNotContainsString('xxxxx', $notes);
        $this->assertStringNotContainsString('donor-session', $notes);
    }

    public function testRedirectWithoutLocationLogsHeadersInsteadOfCrashing(): void
    {
        $this->mockWayforpayResponse(302, ['server' => 'cloudflare', 'cf-ray' => 'a45691823c500215-ZRH']);
        $donation = $this->createTestDonation();

        try {
            $this->gateway->createPayment($donation, []);
            $this->fail('Expected PaymentGatewayException');
        } catch (PaymentGatewayException $e) {
            // Expected.
        }

        $notes = $this->getNotesContent($donation);
        $this->assertStringContainsString('Wayforpay did not provide a Location in headers', $notes);
        $this->assertStringContainsString('"cf-ray":"a45691823c500215-ZRH"', $notes);
    }
}

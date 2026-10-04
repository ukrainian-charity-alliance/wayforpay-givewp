<?php

namespace WayforpayGiveWP\Tests\Unit;

use Give\Donations\Models\Donation;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Donations\ValueObjects\DonationType;
use Give\Framework\Http\Response\Types\RedirectResponse;
use Give\Framework\PaymentGateways\Commands\RedirectOffsite;
use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;
use Give\Subscriptions\Models\Subscription;
use Give\Subscriptions\ValueObjects\SubscriptionMode;
use Give\Subscriptions\ValueObjects\SubscriptionPeriod;
use Give\Subscriptions\ValueObjects\SubscriptionStatus;
use WayForPay\SDK\Helper\SignatureHelper;
use WayforpayGiveWP\Tests\TestCase;

/**
 * Tests for sending the donor to Wayforpay: the redirect from the donation form, then the page that posts to Wayforpay.
 */
class RedirectToWayforpayTest extends TestCase
{
    private \WayforpayGiveWP\WayforpayGateway $gateway;

    public function setUp(): void
    {
        parent::setUp();
        $this->gateway = $this->createGateway();

        // The donor's browser posts to Wayforpay; the site itself must not.
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (str_contains($url, 'wayforpay.com')) {
                $this->fail("Unexpected server-side request to $url");
            }
            return $pre;
        }, 10, 3);
    }

    public function tearDown(): void
    {
        unset($_GET['give-route-signature-id']);
        parent::tearDown();
    }

    /**
     * Call the payment page route as GiveWP does once it has verified the signed donation ID.
     *
     * @return array{0: ?RedirectResponse, 1: string} The route's response and the page it rendered.
     */
    private function callPaymentRedirect(Donation $donation, array $queryParams = []): array
    {
        $_GET['give-route-signature-id'] = (string) $donation->id;
        ob_start();
        try {
            $response = $this->gateway->callRouteMethod('handlePaymentRedirect', $queryParams);
        } finally {
            $html = ob_get_clean();
        }
        return [$response, $html];
    }

    /**
     * The fields a browser posts when it submits the rendered form.
     */
    private function getPostedFields(string $html): array
    {
        $doc = new \DOMDocument();
        $doc->loadHTML($html, LIBXML_NOERROR);
        $fields = [];
        foreach ($doc->getElementsByTagName('input') as $input) {
            $name = $input->getAttribute('name');
            if (str_ends_with($name, '[]')) {
                $fields[substr($name, 0, -2)][] = $input->getAttribute('value');
            } elseif ($name !== '') {
                $fields[$name] = $input->getAttribute('value');
            }
        }
        return $fields;
    }

    /**
     * The signature Wayforpay expects for the posted fields.
     */
    private function expectedSignature(array $fields): string
    {
        return SignatureHelper::calculateSignature(
            [
                $fields['merchantAccount'],
                $fields['merchantDomainName'],
                $fields['orderReference'],
                $fields['orderDate'],
                $fields['amount'],
                $fields['currency'],
                $fields['productName'],
                $fields['productCount'],
                $fields['productPrice'],
            ],
            self::TEST_MERCHANT_SECRET
        );
    }

    private function getNotesContent(Donation $donation): string
    {
        return implode("\n", array_map(static fn ($note) => $note->content, $donation->notes()->getAll()));
    }

    public function testCreatePaymentRedirectsToSignedPaymentPage(): void
    {
        $donation = $this->createTestDonation();

        $result = $this->gateway->createPayment($donation, []);

        $this->assertInstanceOf(RedirectOffsite::class, $result);
        parse_str((string) wp_parse_url($result->redirectUrl, PHP_URL_QUERY), $query);
        $this->assertSame('handlePaymentRedirect', $query['give-gateway-method']);
        $this->assertSame((string) $donation->id, $query['give-route-signature-id']);
        $this->assertNotEmpty($query['give-route-signature']);
    }

    public function testCreatePaymentFailsOnDonationFormWhenCampaignMissing(): void
    {
        $donation = $this->createTestDonation(['campaignId' => 999999]);

        $this->expectException(PaymentGatewayException::class);
        $this->gateway->createPayment($donation, []);
    }

    public function testPaymentPagePostsSignedFormToWayforpay(): void
    {
        $donation = $this->createTestDonation();

        [$response, $html] = $this->callPaymentRedirect($donation);

        $this->assertNull($response);
        $this->assertStringContainsString('action="https://secure.wayforpay.com/pay"', $html);
        $this->assertStringContainsString('document.forms[0].submit()', $html);
        $this->assertStringContainsString('<base target="_top">', $html);
        $fields = $this->getPostedFields($html);
        $this->assertSame(self::TEST_MERCHANT_ACCOUNT, $fields['merchantAccount']);
        $this->assertStringStartsWith($donation->id . '-', $fields['orderReference']);
        $this->assertSame(['Test Campaign'], $fields['productName']);
        $this->assertSame($this->expectedSignature($fields), $fields['merchantSignature']);
        $this->assertStringContainsString('Redirecting donor to Wayforpay', $this->getNotesContent($donation));
    }

    public function testPaymentPageSignatureSurvivesHtmlEntitiesInValues(): void
    {
        $donation = $this->createTestDonation();
        $campaign = $donation->campaign()->get();
        $campaign->title = 'Food &amp; "Water"';
        $campaign->save();

        [, $html] = $this->callPaymentRedirect($donation);

        $fields = $this->getPostedFields($html);
        $this->assertSame(['Food &amp; "Water"'], $fields['productName']);
        $this->assertSame($this->expectedSignature($fields), $fields['merchantSignature']);
    }

    public function testPaymentPageUsesSignedDonationIdNotQueryArgs(): void
    {
        $donation = $this->createTestDonation();
        $other = $this->createTestDonation();

        [, $html] = $this->callPaymentRedirect($donation, ['donation-id' => $other->id]);

        $this->assertStringStartsWith($donation->id . '-', $this->getPostedFields($html)['orderReference']);
    }

    public function testPaymentPageSendsCompletedDonationToSuccessPage(): void
    {
        $donation = $this->createTestDonation(['status' => DonationStatus::COMPLETE()]);

        [$response, $html] = $this->callPaymentRedirect($donation);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(give_get_success_page_uri(), $response->getTargetUrl());
        $this->assertSame('', $html);
    }

    public function testPaymentPageIncludesRecurringPaymentForSubscription(): void
    {
        $donation = $this->createTestDonation();
        $subscription = Subscription::create([
            'donationFormId' => $donation->formId,
            'campaignId' => $donation->campaignId,
            'period' => SubscriptionPeriod::MONTH(),
            'frequency' => 1,
            'donorId' => $donation->donorId,
            'installments' => 0,
            'amount' => $donation->amount,
            'status' => SubscriptionStatus::PENDING(),
            'mode' => SubscriptionMode::TEST(),
            'gatewayId' => 'wayforpay-gateway',
        ]);
        $donation->type = DonationType::SUBSCRIPTION();
        $donation->subscriptionId = $subscription->id;
        $donation->save();

        [, $html] = $this->callPaymentRedirect($donation);

        $fields = $this->getPostedFields($html);
        $this->assertSame('monthly', $fields['regularMode']);
        $this->assertStringContainsString('subscription-id=' . $subscription->id, $fields['serviceUrl']);
        $this->assertStringContainsString('(recurring)', $this->getNotesContent($donation));
    }
}

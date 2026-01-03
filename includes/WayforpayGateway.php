<?php

use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Framework\Exceptions\Primitives\Exception;
use Give\Framework\Http\Response\Types\RedirectResponse;
use Give\Framework\Http\Response\Types\JsonResponse;
use Give\Framework\PaymentGateways\Commands\PaymentRefunded;
use Give\Framework\PaymentGateways\Commands\RedirectOffsite;
use Give\Framework\PaymentGateways\PaymentGateway;
use Give\Framework\PaymentGateways\Contracts\PaymentGatewayRefundable;
use Give\Framework\PaymentGateways\Contracts\WebhookNotificationsListener;
use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;
use Give\Subscriptions\Models\Subscription;
use Give\Subscriptions\Models\SubscriptionNote;
use Give\Subscriptions\ValueObjects\SubscriptionStatus;

/**
 * @inheritDoc
 */
class WayforpayGateway extends PaymentGateway implements WebhookNotificationsListener, PaymentGatewayRefundable
{
    private const WAYFORPAY_PAY_URL = 'https://secure.wayforpay.com/pay';
    private const WAYFORPAY_API_URL = 'https://api.wayforpay.com/api';
    private const WAYFORPAY_RECURRING_API_URL = 'https://api.wayforpay.com/regularApi';

    /**
     * @inheritDoc
     */
    public $routeMethods = [
        'handleReturnUrl',
        'webhookNotificationsListener',
    ];

    /**
     * @inheritDoc
     * 
     * Note: secureRouteMethods cannot yet be used. Wayforpay allows max 256 chars for returnUrl/serviceUrl.
     * The addition of additional signature params in the URLs surpasses these 256 chars.
     * It is likely that a feature request to Wayforpay will be needed.
     */
    public $secureRouteMethods = [];

    /**
     * @inheritDoc
     */
    public static function id(): string
    {
        return 'wayforpay-gateway';
    }

    /**
     * @inheritDoc
     */
    public function getId(): string
    {
        return self::id();
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return __('Wayforpay Gateway', 'wayforpay-givewp');
    }

    /**
     * @inheritDoc
     */
    public function getPaymentMethodLabel(): string
    {
        return __('Wayforpay', 'wayforpay-givewp');
    }

    /**
     * Register a js file to display gateway fields for v3 donation forms
     */
    public function enqueueScript(int $formId)
    {
        // Support for forms built with the Visual Form Builder.
        wp_enqueue_script(
            'wayforpay-gateway-fe',
            WAYFORPAY_GIVEWP_PLUGIN_URL . 'js/fe.js',
            ['react', 'wp-element'],
            WAYFORPAY_GIVEWP_VERSION,
            true
        );
    }

    public function formSettings(int $formId): array
    {
        // The form settings to send to the JS counterpart.
        return [
            'message' => __('You will be sent to the secure Wayforpay platform to complete your donation.', 'wayforpay-givewp'),
            'iconUrl' => WAYFORPAY_GIVEWP_PLUGIN_URL . 'assets/wayforpay-logo.svg',
        ];
    }

    /**
     * @inheritDoc
     */
    public function getLegacyFormFieldMarkup(int $formId, array $args): string
    {
        // For legacy forms, show a simple help text as a fallback.
        return "<div class='wayforpay-gateway-help-text'>
                    <p>" . esc_html__(this->formSettings($formId)['message'], 'wayforpay-givewp') . "</p>
                </div>";
    }

    /**
     * @inheritDoc
     */
    public function createPayment(Donation $donation, $gatewayData): RedirectOffsite
    {
        $serviceUrlParams = ['donation-id' => $donation->id];
        return $this->redirectToWayforpay($donation, $serviceUrlParams);
    }

    /**
     * Redirects donor to an offsite Wayforpay payment page.
     */
    public function redirectToWayforpay(
        Donation $donation,
        array $serviceUrlParams = [],
        array $extraWayforpayArgs = []
    ): RedirectOffsite {
        $merchantAccount = WayforpaySettings::getMerchantAccount();
        $secretKey = WayforpaySettings::getSecretKey();
        if (empty($merchantAccount) || empty($secretKey)) {
            throw new PaymentGatewayException('Wayforpay is not configured');
        }

        $returnUrl = $this->generateGatewayRouteUrl(
            'handleReturnUrl',
            ['donation-id' => $donation->id]
        );
        $serviceUrl = $this->webhook->getNotificationUrl($serviceUrlParams);
        $amount = $donation->amount->formatToDecimal();
        $currency = strtoupper($donation->amount->getCurrency()->getCode());
        // A Donation may have multiple payment attempts; orderReference should differ from donationId.
        $orderReference = $donation->id . '-' . time();

        $campaign = $donation->campaign()->get();
        $campaignTitle = $campaign?->title ?? null;
        if (empty($campaignTitle)) {
            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf('Missing title from Campaign: %s', print_r($campaign, true))
            ]);
            throw new PaymentGatewayException('missing campaign');
        }

        $wayforpayArgs = [
            'merchantAccount' => $merchantAccount,
            'merchantAuthType' => 'simpleSignature',
            'merchantDomainName' => wp_parse_url(home_url(), PHP_URL_HOST),
            'merchantTransactionSecureType' => 'AUTO',

            'orderReference' => $orderReference,
            'orderDate' => $donation->createdAt->getTimestamp(), // TODO: consider making this time() to support recurring better.
            'currency' => $currency,
            'amount' => $amount,
            'returnUrl' => $returnUrl,
            'serviceUrl' => $serviceUrl,
            'language' => substr(get_bloginfo('language'), 0, 2),
            'productName' => [$campaignTitle],
            'productPrice' => [$amount],
            'productCount' => [1],
        ];
        // Wayforpay supports sending optional client metadata. This can help with analytics on the Wayforpay side.
        if (!empty($donation->firstName)) {
            $wayforpayArgs['clientFirstName'] = $donation->firstName;
        }
        if (!empty($donation->lastName)) {
            $wayforpayArgs['clientLastName'] = $donation->lastName;
        }
        if (!empty($donation->email)) {
            $wayforpayArgs['clientEmail'] = $donation->email;
        }
        // Additional functionality (i.e. recurring payments) should be passed in as args by the caller.
        $wayforpayArgs = array_merge($wayforpayArgs, $extraWayforpayArgs);
        $wayforpayArgs['merchantSignature'] = $this->paymentSignature($wayforpayArgs, $secretKey);

        DonationNote::create([
            'donationId' => $donation->id,
            'content' => sprintf(
                'Redirecting donor to Wayforpay. Order: %s, Amount: %s %s',
                $wayforpayArgs['orderReference'],
                $wayforpayArgs['amount'],
                $wayforpayArgs['currency']
            )
        ]);

        try {
            $wayforpayRequest = [
                'timeout'     => 10,
                'headers'     => [
                    'Content-Type'  => 'application/x-www-form-urlencoded; charset=utf-8',
                ],
                // Uses form encoding for the arguments within the body.
                'body'        => http_build_query($wayforpayArgs),
                // The server should not redirect to Wayforpay's provided redirect URL.
                // Instead, the server will pass the redirect location to the browser.
                'redirection' => 0,
            ];
            $wayforpayResponse = wp_remote_post(self::WAYFORPAY_PAY_URL, $wayforpayRequest);

            $responseBody = wp_remote_retrieve_body($wayforpayResponse);
            $responseHeaders = wp_remote_retrieve_headers($wayforpayResponse);
            if (is_wp_error($wayforpayResponse)) {
                DonationNote::create([
                    'donationId' => $donation->id,
                    'content' => sprintf(
                        'Payment failed: Could not connect to Wayforpay. Error: %s',
                        $wayforpayResponse->get_error_message()
                    )
                ]);
                throw new PaymentGatewayException('could not connect to Wayforpay');
            }

            $httpCode = wp_remote_retrieve_response_code($wayforpayResponse);
            $redirectCodes = [301, 302, 303, 307, 308];
            if (!in_array($httpCode, $redirectCodes)) {
                DonationNote::create([
                    'donationId' => $donation->id,
                    'content' => sprintf(
                        'Payment failed: Expected Wayforpay to redirect but got HTTP %d. Response: %s',
                        $httpCode, $responseBody)
                ]);
                throw new PaymentGatewayException('no redirect HTTP code provided');
            }

            $wayforPayRedirect = $responseHeaders['Location'];
            if (empty($wayforPayRedirect)) {
                DonationNote::create([
                    'donationId' => $donation->id,
                    'content' => sprintf('Payment failed: Wayforpay did not provide a Location in headers: %s', $responseHeaders)
                ]);
                throw new PaymentGatewayException('no redirect URL provided');
            }

            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf('Sending user to payment URL: %s', $wayforPayRedirect)
            ]);
            return new RedirectOffsite($wayforPayRedirect);
        } catch (Exception $e) {
            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf('Payment failed: unknown error %s.', $e->getMessage())
            ]);
            throw new PaymentGatewayException('unknown error');
        }
    }

    /**
     * Handle redirection from Wayforpay back to the site after payment.
     * This is purely UX; any donation updating logic should be handled via webhooks to serviceUrl.
     */
    protected function handleReturnUrl(array $queryParams): RedirectResponse
    {
        // WayForPay may POST transaction data here, but we don't rely on it for status updates.
        // The serviceUrl webhook is the authoritative source for updating payment status for GiveWP.
        $data = stripslashes_deep($_POST);

        $donationId = isset($queryParams['donation-id']) ? (int) $queryParams['donation-id'] : null;
        if (empty($donationId)) {
            throw new PaymentGatewayException('no donation-id parameter received from Wayforpay');
        }

        if (empty($data)) {
            throw new PaymentGatewayException('no data received from Wayforpay');
        }

        // If the returnUrl is registered in secureRouteMethods, a signature will be sent for verification.
        // If in routeMethods, it's okay to continue because there are no sensitive operations done via returnUrl.
        $gotSignature = $data['merchantSignature'] ?? null;
        if (!empty($gotSignature)) {
            $expectedSignature = $this->serviceUrlSignature($data, WayforpaySettings::getSecretKey());
            if ($gotSignature !== $expectedSignature) {
                throw new PaymentGatewayException('invalid signature received from Wayforpay.');
            }
        }

        $reasonCode = isset($data['reasonCode']) ? (int) $data['reasonCode'] : null;
        switch ($reasonCode) {
            case 1100: // OK
                DonationNote::create([
                    'donationId' => $donationId,
                    'content' => sprintf(
                        'Payment successful: redirecting user to success page. Query params: %s, POST data: %s',
                        print_r($queryParams, true),
                        print_r($data, true)
                    )
                ]);
                return new RedirectResponse(give_get_success_page_uri());
            case 5103: // "Wait For Keep" - User likely clicked Cancel in the Wayforpay payment page.
                // Redirect to the Donor Dashboard page so users can at least see the status.
                // TODO: consider adding a URL param to show the in progress state or a link back to the Wayforpay page.
                // TODO: perhaps with a Hidden Field in the Donation, we can store the Wayforpay redirect URL and use it here.
                DonationNote::create([
                    'donationId' => $donationId,
                    'content' => sprintf(
                        'Payment still pending: redirecting user to history page. Query params: %s, POST data: %s',
                        print_r($queryParams, true),
                        print_r($data, true)
                    )
                ]);
                return new RedirectResponse(give_get_history_page_uri());
            default:
                DonationNote::create([
                    'donationId' => $donationId,
                    'content' => sprintf(
                        'Payment failed: redirecting user to failure page. Query params: %s, POST data: %s',
                        print_r($queryParams, true),
                        print_r($data, true)
                    )
                ]);
                return new RedirectResponse(give_get_failed_transaction_uri());
        }
    }

    /**
     * Handles serviceUrl webhook calls from Wayforpay.
     * Allows updating donation status even if user closes the browser on the Wayforpay site.
     * WayForPay will retry this endpoint periodically until it receives a valid acknowledgment.
     */
    public function webhookNotificationsListener(): void
    {
        $requestData = give_clean($_REQUEST);
        // Wayforpay sends webhook data as JSON in the request body
        $rawBody = file_get_contents('php://input');
        $data = json_decode($rawBody, true);

        $secretKey = WayforpaySettings::getSecretKey();
        $orderReference = $data['orderReference'] ?? null;

        $sendAckResponse = function (string $status, int $httpCode) use ($orderReference, $secretKey) {
            $time = time();
            wp_send_json([
                'orderReference' => $orderReference,
                'status' => $status,
                'time' => $time,
                'signature' => $this->ackSignature([
                    'orderReference' => $orderReference,
                    'status' => $status,
                    'time' => $time
                ], $secretKey)
            ], $httpCode);
        };

        if (empty($data)) {
            $sendAckResponse('decline', 400);
        }

        $gotSignature = $data['merchantSignature'] ?? null;
        $expectedSignature = $this->serviceUrlSignature($data, $secretKey);
        if ($gotSignature !== $expectedSignature) {
            $sendAckResponse('decline', 403);
        }

        // Handle subscription renewal webhooks.
        // If subscription-id is present, it's a renewal webhook.
        $subscriptionId = $requestData['subscription-id'] ?? null;
        if (!empty($subscriptionId)) {
            $subscription = Subscription::find($subscriptionId);
            if (empty($subscription)) {
                $sendAckResponse('decline', 404);
            }
            $this->handleRenewal($data, $subscription);
            $sendAckResponse('accept', 200);
        }

        $donationId = isset($queryParams['donation-id']) ? (int) $queryParams['donation-id'] : null;
        if (empty($donationId)) {
            $sendAckResponse('decline', 404);
        }
        $donation = Donation::find($donationId);
        if (empty($donation)) {
            $sendAckResponse('decline', 404);
        }

        $transactionStatus = $data['transactionStatus'] ?? null;
        if ($transactionStatus === 'Approved') {
            if (!$donation->status->isComplete()) {
                $donation->status = DonationStatus::COMPLETE();
                $donation->gatewayTransactionId = $data['orderReference'];
                $donation->save();

                if (!empty($subscriptionId)) {
                    $subscription = Subscription::find($subscriptionId);
                    if (!empty($subscription) && empty($subscription->gatewaySubscriptionId)) {
                        $subscription->gatewaySubscriptionId = $data['orderReference'];
                        $subscription->save();
                    }
                }

                DonationNote::create([
                    'donationId' => $donation->id,
                    'content' => sprintf(
                        'Payment successful. Card: %s, Authorization Code: %s',,
                        $data['cardPan'] ?? null, $data['authCode'] ?? null
                    )
                ]);
            }
        } elseif ($transactionStatus === 'Declined' || $transactionStatus === 'Expired') {
            // Only update if not already in a terminal state
            if (!$donation->status->isFailed()) {
                $donation->status = DonationStatus::FAILED();
                $donation->save();

                DonationNote::create([
                    'donationId' => $donation->id,
                    'content' => sprintf(
                        'Payment declined. Status: %s. Reason code: %s',
                        $transactionStatus, $data['reasonCode'] ?? null
                    )
                ]);
            }
        } else {
            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf('Wayforpay sent a status update: %s. Awaiting final confirmation.', $transactionStatus)
            ]);
        }

        // Accepting the response tells Wayforpay to stop sending transaction updates for this order.
        $sendAckResponse('accept', 200);
    }

    /**
     * @inheritDoc
     */
    public function refundDonation(Donation $donation): PaymentRefunded
    {
        $merchantAccount = WayforpaySettings::getMerchantAccount();
        $secretKey = WayforpaySettings::getSecretKey();
        if (empty($merchantAccount) || empty($secretKey)) {
            throw new PaymentGatewayException('Wayforpay is not configured');
        }

        $orderReference = $donation->gatewayTransactionId;
        $amount = $donation->amount->formatToDecimal();
        $currency = strtoupper($donation->amount->getCurrency()->getCode());
        if (empty($orderReference) || empty($amount) || empty($currency)) {
            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf(
                    'Refund failed: Missing required data. Order: %s, Amount: %s %s',
                    $orderReference,
                    $amount,
                    $currency
                )
            ]);
            throw new PaymentGatewayException('cannot process refund: required transaction data is missing.');
        }

        DonationNote::create([
            'donationId' => $donation->id,
            'content' => sprintf(
                'Attempting refund of order %s via Wayforpay for amount %s %s',,
                $orderReference,
                $amount,
                $currency
            )
        ]);

        // Build refund request per Wayforpay API:
        // https://github.com/wayforpay/php-sdk/blob/master/src/Request/RefundRequest.php
        $refundArgs = [
            'transactionType' => 'REFUND',
            'merchantAccount' => $merchantAccount,
            'orderReference' => $orderReference,
            'amount' => $amount,
            'currency' => $currency,
            'comment' => 'Refund initiated from GiveWP',
            'apiVersion' => 1,
        ];
        $refundArgs['merchantSignature'] = $this->refundSignature($refundArgs, $secretKey);
        $response = wp_remote_post(self::WAYFORPAY_API_URL, [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json; charset=utf-8',
            ],
            'body' => wp_json_encode($refundArgs),
        ]);

        if (is_wp_error($response)) {
            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf('Refund failed: Could not connect to Wayforpay. Error: %s', $response->get_error_message())
            ]);
            throw new PaymentGatewayException('could not connect to Wayforpay');
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!$data) {
            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf('Refund failed: Invalid JSON body response from Wayforpay: %s', print_r($data, true)),
            ]);
            throw new PaymentGatewayException('invalid response from Wayforpay');
        }

        $reason = $data['reason'] ?? null;
        $reasonCode = $data['reasonCode'] ?? null;
        $transactionStatus = $data['transactionStatus'] ?? null;
        if ($reasonCode === 1100 || $transactionStatus === 'Refunded') { // reasonCode 1100 = OK
            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf(
                    'Refund successful. Status: %s, Reason Code: %s, Reason: %s',
                    $transactionStatus,
                    $reasonCode,
                    $reason
                )
            ]);
            return new PaymentRefunded();
        }

        DonationNote::create([
            'donationId' => $donation->id,
            'content' => sprintf(
                'Refund failed: Status: %s, Reason Code: %s, Reason: %s',
                $transactionStatus,
                $reasonCode,
                $reason
            )
        ]);
        throw new PaymentGatewayException(sprintf('Refund failed: %s (code: %s)', $reason, $reasonCode));
    }

    /**
     * HMAC-MD5 signature for making requests to the Wayforpay payment API.
     * @see https://wiki.wayforpay.com/en/view/852102
     */
    private function paymentSignature(array $args, string $secretKey): string
    {
        $signatureKeys = [
            'merchantAccount',
            'merchantDomainName',
            'orderReference',
            'orderDate',
            'amount',
            'currency',
            'productName',
            'productCount',
            'productPrice',
        ];
        return $this->createSignature($args, $signatureKeys, $secretKey);
    }

    /**
     * HMAC-MD5 signature for Wayforpay requests to serviceUrl.
     * @see https://wiki.wayforpay.com/en/view/852102
     */
    private function serviceUrlSignature(array $args, string $secretKey): string
    {
         $signatureKeys = [
            'merchantAccount',
            'orderReference',
            'amount',
            'currency',
            'authCode',
            'cardPan',
            'transactionStatus',
            'reasonCode'
        ];
        return $this->createSignature($args, $signatureKeys, $secretKey);
    }

    /**
     * HMAC-MD5 signature for acknowledgment responses to Wayforpay.
     * @see https://wiki.wayforpay.com/en/view/852102
     */
    private function ackSignature(array $args, string $secretKey): string
    {
        $signatureKeys = [
            'orderReference',
            'status',
            'time',
        ];
        return $this->createSignature($args, $signatureKeys, $secretKey);
    }

    /**
     * HMAC-MD5 signature for refund requests to the Wayforpay payment API.
     * @see https://github.com/wayforpay/php-sdk/blob/master/src/Request/RefundRequest.php
     */
    private function refundSignature(array $args, string $secretKey): string
    {
        $signatureKeys = [
            'merchantAccount',
            'orderReference',
            'amount',
            'currency',
        ];
        return $this->createSignature($args, $signatureKeys, $secretKey);
    }

    private function createSignature(array $data, array $keys, string $secretKey): string
    {
        $hashFields = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            if (is_array($data[$key])) {
                foreach ($data[$key] as $value) {
                     $hashFields[] = $value;
                }
            } else {
                $hashFields[] = $data[$key];
            }
        }
        return hash_hmac('md5', implode(';', $hashFields), $secretKey);
    }

    // ==================== Support for recurring payments ====================

    public function supportsSubscriptions(): bool
    {
        return true;
    }

    public function createSubscription(
        Donation $donation,
        Subscription $subscription,
        $gatewayData
    ): RedirectOffsite {
        $periodMap = [
            'day' => 'daily',
            'week' => 'weekly',
            'month' => 'monthly',
            'quarter' => 'quarterly',
            'year' => 'yearly',
        ];
        $period = $subscription->period->getValue();
        $regularMode = $periodMap[$period] ?? null;
        if (empty($regularMode)) {
            throw new PaymentGatewayException(sprintf('unsupported subscription period: %s', $period));
        }

        $amount = $donation->amount->formatToDecimal();
        $dateNext = $this->calculateNextDate($period, $subscription->frequency);
        $recurringArgs = [
            'regularMode' => $regularMode,
            'regularAmount' => $amount,
            'regularBehavior' => 'preset',
            'regularOn' => 1,
            'dateNext' => $dateNext,
        ];
        if ($subscription->installments > 0) {
            $recurringArgs['regularCount'] = $subscription->installments - 1;
        }

        return $this->redirectToWayforpay(
            $donation,
            ['donation-id' => $donation->id, 'subscription-id' => $subscription->id],
            $recurringArgs
        );
    }

    public function cancelSubscription(Subscription $subscription): void
    {
        $merchantAccount = WayforpaySettings::getMerchantAccount();
        $merchantPassword = WayforpaySettings::getMerchantPassword();
        if (empty($merchantAccount) || empty($merchantPassword)) {
            throw new PaymentGatewayException('Wayforpay is not configured');
        }

        $orderReference = $subscription->gatewaySubscriptionId;
        if (empty($orderReference)) {
            $subscription->status = SubscriptionStatus::CANCELLED();
            $subscription->save();
            return;
        }

        $response = wp_remote_post(self::WAYFORPAY_RECURRING_API_URL, [
            'timeout' => 30,
            'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
            'body' => wp_json_encode([
                'requestType' => 'REMOVE',
                'merchantAccount' => $merchantAccount,
                'merchantPassword' => $merchantPassword,
                'orderReference' => $orderReference,
            ]),
        ]);
        if (is_wp_error($response)) {
            throw new PaymentGatewayException('failed to connect to Wayforpay');
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $reasonCode = $data['reasonCode'] ?? null;
        // Reason code 1100 = OK (success)
        // Reason code 1151 = Regular payment not found (already cancelled or doesn't exist)
        if ($reasonCode !== 1100 && $reasonCode !== 1151) {
            throw new PaymentGatewayException(
                sprintf('cancellation failed: %s (code: %s)', $data['reason'] ?? null, $reasonCode)
            );
        }

        $subscription->status = SubscriptionStatus::CANCELLED();
        $subscription->save();
    }

    private function handleRenewal(array $data, Subscription $subscription): void
    {
        $transactionStatus = $data['transactionStatus'] ?? null;
        if ($transactionStatus === 'Approved') {
            $donation = $subscription->createRenewal([
                'gatewayTransactionId' => $data['orderReference'] ?? null,
            ]);
            $donation->status = DonationStatus::COMPLETE();
            $donation->save();
            $subscription->bumpRenewalDate();
            $subscription->save();

            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf(
                    'Recurring payment successful! Card: %s, Authorization Code: %s',
                    $data['cardPan'] ?? null, $data['authCode'] ?? null
                )
            ]);
        } elseif ($transactionStatus === 'Declined' || $transactionStatus === 'Expired') {
            SubscriptionNote::create([
                'subscriptionId' => $subscription->id,
                'content' => sprintf(
                    'Recurring payment failed. Status: %s, Reason: %s',
                    $transactionStatus, $data['reasonCode'] ?? null
                )
            ]);
        }
    }

    private function calculateNextDate(string $period, int $frequency): string
    {
        $now = new \DateTimeImmutable();
        $modifier = match ($period) {
            'day' => "+{$frequency} days",
            'week' => "+{$frequency} weeks",
            'month' => "+{$frequency} months",
            'quarter' => "+" . ($frequency * 3) . " months",
            'year' => "+{$frequency} years",
            default => "+0 days",
        };
        return $now->modify($modifier)->format('d.m.Y');
    }
}

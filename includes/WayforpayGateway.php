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

/**
 * @inheritDoc
 */
class WayforpayGateway extends PaymentGateway implements WebhookNotificationsListener, PaymentGatewayRefundable
{
    private const WAYFORPAY_PAY_URL = 'https://secure.wayforpay.com/pay';
    private const WAYFORPAY_API_URL = 'https://api.wayforpay.com/api';

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
     * Note: secureRouteMethods is not used yet, because Wayforpay allows only 256 characters in returnUrl
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
        // For an offsite gateway, this is just help text that displays on the form. 
        return "<div class='wayforpay-gateway-help-text'>
                    <p>" . esc_html__('You will be sent to the secure Wayforpay platform to complete your donation.', 'wayforpay-givewp') . "</p>
                </div>";
    }

    /**
     * @inheritDoc
     */
    public function createPayment(Donation $donation, $gatewayData): RedirectOffsite
    {
        $returnUrl = $this->generateGatewayRouteUrl(
            'handleReturnUrl',
            [
                'donation-id' => $donation->id,
            ]
        );

        // Use GiveWP's native webhook system for server-to-server callbacks
        $serviceUrl = $this->webhook->getNotificationUrl([
            'donation-id' => $donation->id,
        ]);

        // Wayforpay argument documentation: https://wiki.wayforpay.com/en/view/852102
        // GiveWP Donation API documentation: https://givewp.com/documentation/developers/give-api-reference/#donations-query
        // GiveWP Donation object: https://github.com/impress-org/givewp/blob/develop/src/Donations/Models/Donation.php
        // error_log('DONATION TO WAYFORPAY:');
        // error_log(print_r($donation, true));

        $amount = $donation->amount->formatToDecimal();
        $campaign = $donation->campaign;
        $merchantAccount = WayforpaySettings::getMerchantAccount();
        $secretKey = WayforpaySettings::getSecretKey();
        if (empty($merchantAccount) || empty($secretKey)) {
            throw new PaymentGatewayException(
                __('Wayforpay gateway is not configured. Please set your Merchant Account and Secret Key in Donations → Settings → Payment Gateways → Wayforpay.', 'wayforpay-givewp')
            );
        }

        $wayforpayArgs = [
            'merchantAccount' => $merchantAccount,
            'merchantAuthType' => 'simpleSignature',
            'merchantDomainName' => wp_parse_url(home_url(), PHP_URL_HOST),
            'merchantTransactionSecureType' => 'AUTO',

            'orderReference' => $donation->id . '-' . time(),
            'orderDate' => $donation->createdAt->getTimestamp(),
            'currency' => strtoupper($donation->amount->getCurrency()->getCode()),
            'amount' => $amount,
            'returnUrl' => $returnUrl,
            'serviceUrl' => $serviceUrl,
            'language' => substr(get_bloginfo('language'), 0, 2),
            'productName' => [$campaign?->title ?? __('Donation', 'wayforpay-givewp')],
            'productPrice' => [$amount],
            'productCount' => [1],
        ];
        // If available, send additional client metadata from the GiveWP form to the Payment Gateway.
        if (!empty($donation->firstName)) {
            $wayforpayArgs['clientFirstName'] = $donation->firstName;
        }
        if (!empty($donation->lastName)) {
            $wayforpayArgs['clientLastName'] = $donation->lastName;
        }
        if (!empty($donation->email)) {
            $wayforpayArgs['clientEmail'] = $donation->email;
        }
        $wayforpayArgs['merchantSignature'] = $this->paymentSignature($wayforpayArgs, $secretKey);

        DonationNote::create([
            'donationId' => $donation->id,
            'content' => sprintf(
                __('Redirecting donor to Wayforpay for payment. Order: %s, Amount: %s %s', 'wayforpay-givewp'),
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
                        __('Payment failed: Could not connect to Wayforpay. Error: %s', 'wayforpay-givewp'),
                        $wayforpayResponse->get_error_message()
                    )
                ]);
                throw new PaymentGatewayException('WP_Error: ' . $wayforpayResponse->get_error_message());
            }

            $httpCode = wp_remote_retrieve_response_code($wayforpayResponse);
            $redirectCodes = [301, 302, 303, 307, 308];
            if (!in_array($httpCode, $redirectCodes)) {
                DonationNote::create([
                    'donationId' => $donation->id,
                    'content' => sprintf(
                        __('Payment failed: Expected Wayforpay to redirect but got HTTP %d. Response: %s', 'wayforpay-givewp'),
                        $httpCode,
                        $responseBody
                    )
                ]);
                throw new PaymentGatewayException('Wayforpay did not provide a redirect, instead ' . $httpCode . '. Response: ' . $responseBody);
            }

            $wayforPayRedirect = $responseHeaders['Location'];
            if (empty($wayforPayRedirect)) {
                DonationNote::create([
                    'donationId' => $donation->id,
                    'content' => __('Payment failed: Wayforpay did not provide a payment page URL.', 'wayforpay-givewp')
                ]);
                throw new PaymentGatewayException('Wayforpay header has no location');
            }

            return new RedirectOffsite($wayforPayRedirect);
        } catch (Exception $e) {
            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf(
                    __('Payment failed due to an unexpected error: %s.', 'wayforpay-givewp'),
                    $e->getMessage()
                )
            ]);
            throw new PaymentGatewayException('Unknown error ocurred: ' . $e->getMessage());
        }
    }

    /**
     * Handle redirection from Wayforpay back to the site after payment.
     * This is purely UX; donation updating logic is handled via webhooks in handleServiceCallback.
     */
    protected function handleReturnUrl(array $queryParams): RedirectResponse
    {
        $donationId = $queryParams['donation-id'] ?? null;

        // WayForPay may POST data here, but we don't rely on it for status updates.
        // The serviceUrl webhook is the authoritative source for payment status.
        $data = stripslashes_deep($_POST);
        
        if (!empty($data)) {
            // Optionally verify signature if data is present
            $secretKey = WayforpaySettings::getSecretKey();
            if ($this->serviceUrlSignature($data, $secretKey) === ($data['merchantSignature'] ?? '')) {
                $transactionStatus = $data['transactionStatus'] ?? $data['status'] ?? '';
                
                // If payment failed, redirect to failure page
                if ($transactionStatus === 'Declined' || $transactionStatus === 'Expired') {
                    return new RedirectResponse(give_get_failed_transaction_uri());
                }
            }
        }

        // Redirect user to success page
        // Note: The donation status may not be updated yet if the serviceUrl webhook hasn't fired.
        return new RedirectResponse(give_get_success_page_uri());
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

        $gotSignature = $data['merchantSignature'] ?? '';
        $expectedSignature = $this->serviceUrlSignature($data, $secretKey);
        if ($gotSignature !== $expectedSignature) {
            $sendAckResponse('decline', 403);
        }

        $donationId = $requestData['donation-id'] ?? null;
        if (!$donationId) {
            $sendAckResponse('decline', 404);
        }
        $donation = Donation::find($donationId);
        if (!$donation) {
            $sendAckResponse('decline', 404);
        }

        $transactionStatus = $data['transactionStatus'] ?? $data['status'] ?? '';
        if ($transactionStatus === 'Approved') {
            // Check status before updating to prevent race conditions
            // The webhook might arrive before or after the returnUrl redirect
            if (!$donation->status->isComplete()) {
                $donation->status = DonationStatus::COMPLETE();
                $donation->gatewayTransactionId = $data['orderReference'];
                $donation->save();

                DonationNote::create([
                    'donationId' => $donation->id,
                    'content' => sprintf(
                        __('Payment successful! Confirmed by Wayforpay. Card: %s, Authorization Code: %s', 'wayforpay-givewp'),
                        $data['cardPan'] ?? __('N/A', 'wayforpay-givewp'),
                        $data['authCode'] ?? __('N/A', 'wayforpay-givewp')
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
                        __('Payment declined by Wayforpay. Status: %s. Reason code: %s. The donor\'s card may have been declined or expired.', 'wayforpay-givewp'),
                        $transactionStatus,
                        $data['reasonCode'] ?? __('Not provided', 'wayforpay-givewp')
                    )
                ]);
            }
        } else {
            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf(
                    __('Wayforpay sent a status update: %s. Awaiting final confirmation.', 'wayforpay-givewp'),
                    $transactionStatus ?: __('Unknown', 'wayforpay-givewp')
                )
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
            throw new PaymentGatewayException(
                __('Wayforpay gateway is not configured. Cannot process refund.', 'wayforpay-givewp')
            );
        }

        $orderReference = $donation->gatewayTransactionId;
        $amount = $donation->amount->formatToDecimal();
        $currency = strtoupper($donation->amount->getCurrency()->getCode());
        if (empty($orderReference) || empty($amount) || empty($currency)) {
            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf(
                    __('Refund failed: Missing required data. Order: %s, Amount: %s %s', 'wayforpay-givewp'),
                    $orderReference,
                    $amount,
                    $currency
                )
            ]);
            throw new PaymentGatewayException(
                __('Cannot process refund: Required transaction data is missing.', 'wayforpay-givewp')
            );
        }

        DonationNote::create([
            'donationId' => $donation->id,
            'content' => sprintf(
                __('Attempting refund of order %s via Wayforpay for amount %s %s', 'wayforpay-givewp'),
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
            'comment' => __('Refund initiated from GiveWP', 'wayforpay-givewp'),
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
                'content' => sprintf(
                    __('Refund failed: Could not connect to Wayforpay. Error: %s', 'wayforpay-givewp'),
                    $response->get_error_message()
                )
            ]);
            throw new PaymentGatewayException(
                'Wayforpay refund request failed: ' . $response->get_error_message()
            );
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!$data) {
            DonationNote::create([
                'donationId' => $donation->id,
                'content' => __('Refund failed: Invalid response from Wayforpay.', 'wayforpay-givewp')
            ]);
            throw new PaymentGatewayException('Invalid JSON response from Wayforpay refund API');
        }

        $reason = $data['reason'] ?? null;
        $reasonCode = $data['reasonCode'] ?? null;
        $transactionStatus = $data['transactionStatus'] ?? null;
        if ($reasonCode === 1100 || $transactionStatus === 'Refunded') { // reasonCode 1100 = OK
            return new PaymentRefunded();
        }

        DonationNote::create([
            'donationId' => $donation->id,
            'content' => sprintf(
                __('Refund failed via Wayforpay. Status: %s, Reason Code: %s, Reason: %s', 'wayforpay-givewp'),
                $transactionStatus,
                $reasonCode,
                $reason
            )
        ]);
        throw new PaymentGatewayException(
            sprintf('Wayforpay refund failed: %s (code: %s)', $reason, $reasonCode)
        );
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
}
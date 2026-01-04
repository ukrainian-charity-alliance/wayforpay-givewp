<?php

use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Framework\Exceptions\Primitives\Exception;
use Give\Framework\Http\Response\Types\RedirectResponse;
use Give\Framework\PaymentGateways\Commands\PaymentRefunded;
use Give\Framework\PaymentGateways\Commands\RedirectOffsite;
use Give\Framework\PaymentGateways\PaymentGateway;
use Give\Framework\PaymentGateways\Contracts\PaymentGatewayRefundable;
use Give\Framework\PaymentGateways\Contracts\WebhookNotificationsListener;
use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;
use Give\Subscriptions\Models\Subscription;
use Give\Subscriptions\Models\SubscriptionNote;
use Give\Subscriptions\ValueObjects\SubscriptionStatus;
use WayForPay\SDK\Collection\ProductCollection;
use WayForPay\SDK\Credential\AccountSecretCredential;
use WayForPay\SDK\Domain\Client;
use WayForPay\SDK\Domain\Product;
use WayForPay\SDK\Domain\TransactionService;
use WayForPay\SDK\Client\CurlRequestTransformer;
use WayForPay\SDK\Contract\EndpointInterface;
use WayForPay\SDK\Contract\RequestInterface;
use WayForPay\SDK\Endpoint\ApiRegularEndpoint;
use WayForPay\SDK\Handler\ServiceUrlHandler;
use WayForPay\SDK\Response\ServiceResponse;
use WayForPay\SDK\Wizard\PurchaseWizard;
use WayForPay\SDK\Wizard\RefundWizard;

/**
 * @inheritDoc
 */
class WayforpayGateway extends PaymentGateway implements WebhookNotificationsListener, PaymentGatewayRefundable
{
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
                    <p>" . esc_html__($this->formSettings($formId)['message'], 'wayforpay-givewp') . "</p>
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
        $creds = new AccountSecretCredential($merchantAccount, $secretKey);

        $returnUrl = $this->generateGatewayRouteUrl(
            'handleReturnUrl',
            ['donation-id' => $donation->id]
        );
        $serviceUrl = $this->webhook->getNotificationUrl($serviceUrlParams);
        $amount = $donation->amount->formatToDecimal();
        $currency = strtoupper($donation->amount->getCurrency()->getCode());
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
        // Wayforpay supports sending optional client metadata. This can help with analytics on the Wayforpay side.
        $client = new Client(
            nameFirst: $donation->firstName,
            nameLast: $donation->lastName,
            email: $donation->email,
        );

        try {
            $wizard = PurchaseWizard::get($creds)
                ->setOrderReference($orderReference)
                ->setAmount($amount)
                ->setCurrency($currency)
                ->setOrderDate(new \DateTime('@' . $donation->createdAt->getTimestamp()))
                ->setMerchantDomainName(wp_parse_url(home_url(), PHP_URL_HOST))
                ->setClient($client)
                ->setProducts(new ProductCollection([new Product($campaignTitle, $amount, 1)]))
                ->setReturnUrl($returnUrl)
                ->setServiceUrl($serviceUrl)
                ->setLanguage(substr(get_bloginfo('language'), 0, 2))
                ->setMerchantTransactionSecureType('AUTO'); // Default as per previous code

            $form = $wizard->getForm();
            $wayforpayArgs = array_filter($form->getData()); // array_filter to exclude null values from URL params.
            // Additional functionality (i.e. recurring payments) should be passed in as args by the caller.
            $wayforpayArgs = array_merge($wayforpayArgs, $extraWayforpayArgs);

            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf(
                    'Redirecting donor to Wayforpay. Order: %s, Amount: %s %s',
                    $wayforpayArgs['orderReference'],
                    $wayforpayArgs['amount'],
                    $wayforpayArgs['currency']
                )
            ]);

            $wayforpayRequest = [
                'timeout' => 10,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
                ],
                // Uses form encoding for the arguments within the body.
                'body' => http_build_query($wayforpayArgs),
                // The server should not redirect to Wayforpay's provided redirect URL.
                // Instead, the server will pass the redirect location to the browser.
                'redirection' => 0,
            ];
            $wayforpayResponse = wp_remote_post($form->getEndpoint()->getUrl(), $wayforpayRequest);

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
                        $httpCode,
                        $responseBody
                    )
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
        } catch (\Exception $e) {
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
        $merchantAccount = WayforpaySettings::getMerchantAccount();
        $secretKey = WayforpaySettings::getSecretKey();
        if (empty($merchantAccount) || empty($secretKey)) {
            throw new PaymentGatewayException('Wayforpay is not configured');
        }
        $creds = new AccountSecretCredential($merchantAccount, $secretKey);

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
            try {
                $handler = new ServiceUrlHandler($creds);
                $handler->parseRequestFromArray($data);
            } catch (\Exception $e) {
                throw new PaymentGatewayException('invalid signature received from Wayforpay: ' . $e->getMessage());
            }
        }

        $reasonCode = isset($data['reasonCode']) ? (int) $data['reasonCode'] : null;
        switch ($reasonCode) {
            case 1100: // OK
                DonationNote::create([
                    'donationId' => $donationId,
                    'content' => sprintf(
                        // TODO: remove the debug POST data after testing.
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
     * Handles incoming serviceUrl webhook calls from Wayforpay.
     * Allows updating donation status even if user closes the browser on the Wayforpay site.
     * WayForPay will retry this endpoint periodically until it receives a valid acknowledgment.
     */
    public function webhookNotificationsListener(): void
    {
        $merchantAccount = WayforpaySettings::getMerchantAccount();
        $secretKey = WayforpaySettings::getSecretKey();
        if (empty($merchantAccount) || empty($secretKey)) {
            throw new PaymentGatewayException('Wayforpay is not configured');
        }
        $creds = new AccountSecretCredential($merchantAccount, $secretKey);

        $handler = new ServiceUrlHandler($creds);
        try {
            /** @var ServiceResponse $response */
            $response = $handler->parseRequestFromPostRaw();
        } catch (\Exception $e) {
            error_log(sprintf('Wayforpay webhook error: %s', $e->getMessage()));
            status_header(403);
            exit('Error: Unable to process request');
        }

        $queryParams = give_clean($_GET);
        $donationId = isset($queryParams['donation-id']) ? (int) $queryParams['donation-id'] : null;
        if (empty($donationId)) {
            status_header(404);
            exit('Donation ID missing');
        }
        $donation = Donation::find($donationId);
        if (empty($donation)) {
            status_header(404);
            exit('Donation not found');
        }

        $transaction = $response->getTransaction();
        $orderReference = $transaction->getOrderReference();
        $status = $transaction->getStatus();
        $sendAckResponse = function () use ($handler, $transaction) {
            // send ack receipt to Wayforpay.
            echo $handler->getSuccessResponse($transaction);
            exit;
        };

        // Handle subscription renewal webhooks.
        // If subscription-id is present, it's a renewal webhook.
        $subscriptionId = $queryParams['subscription-id'] ?? null;
        if (!empty($subscriptionId)) {
            $subscription = Subscription::find($subscriptionId);
            if (empty($subscription)) {
                status_header(404);
                exit('Subscription not found');
            }
            $this->handleRenewal($transaction, $subscription);
            $sendAckResponse();
        }

        if ($transaction->isStatusApproved()) {
            if (!$donation->status->isComplete()) {
                $donation->status = DonationStatus::COMPLETE();
                $donation->gatewayTransactionId = $orderReference;
                $donation->save();

                DonationNote::create([
                    'donationId' => $donation->id,
                    'content' => sprintf(
                        'Payment successful. Card: %s, Auth Code: %s',
                        $transaction->getCardPan(),
                        $transaction->getAuthCode()
                    )
                ]);
            }
        } elseif ($transaction->isStatusDeclined() || $transaction->isStatusExpired()) {
            if (!$donation->status->isFailed()) {
                $donation->status = DonationStatus::FAILED();
                $donation->save();

                DonationNote::create([
                    'donationId' => $donation->id,
                    'content' => sprintf(
                        'Payment failed. Status: %s. Reason: %s',
                        $status,
                        $response->getReason()->getMessage()
                    )
                ]);
            }
        } else {
            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf('Wayforpay sent a status update: %s.', $status)
            ]);
        }

        $sendAckResponse();
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
        $creds = new AccountSecretCredential($merchantAccount, $secretKey);

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
                'Attempting refund of order %s via Wayforpay for amount %s %s',
                $orderReference,
                $amount,
                $currency
            )
        ]);

        try {
            $response = RefundWizard::get($creds)
                ->setOrderReference($orderReference)
                ->setAmount($amount)
                ->setCurrency($currency)
                ->setComment('Refund initiated from GiveWP')
                ->getRequest()
                ->send();
        } catch (\Exception $e) {
            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf('Refund failed: Could not connect to Wayforpay. Error: %s', $e->getMessage())
            ]);
            throw new PaymentGatewayException('could not connect to Wayforpay');
        }

        if ($response->getReason()->isOK()) {
            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf(
                    'Refund approved. Status: %s. Order: %s',
                    $response->getTransactionStatus(),
                    $response->getOrderReference()
                )
            ]);
            return new PaymentRefunded();
        }

        DonationNote::create([
            'donationId' => $donation->id,
            'content' => sprintf(
                'Refund declined. Status: %s. Reason: %s',
                $response->getTransactionStatus(),
                $response->getReason()->getMessage()
            )
        ]);
        throw new PaymentGatewayException(sprintf(
            'Refund declined: %s (Code: %s)',
            $response->getTransactionStatus(),
            $response->getReason()->getCode()
        ));
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

        // PHP SDK for Wayforpay doesn't have a REMOVE requestType for the regularApi; define a custom request for it.
        $request = new class ($merchantAccount, $merchantPassword, $orderReference) implements RequestInterface {
            private $account;
            private $password;
            private $orderReference;

            public function __construct($account, $password, $orderReference)
            {
                $this->account = $account;
                $this->password = $password;
                $this->orderReference = $orderReference;
            }

            public function getTransactionData()
            {
                return [
                    'requestType' => 'REMOVE',
                    'merchantAccount' => $this->account,
                    'merchantPassword' => $this->password,
                    'orderReference' => $this->orderReference,
                ];
            }

            public function getTransactionType()
            {
                return 'REMOVE';
            }

            public function getEndpoint()
            {
                return new ApiRegularEndpoint();
            }

            public function setEndpoint(EndpointInterface $endpoint)
            {
                return $this; // No-op.
            }

            public function getResponse(array $data)
            {
                return new ServiceResponse($data);
            }
        };

        try {
            $transformer = new CurlRequestTransformer();
            /** @var ServiceResponse $response */
            $response = $transformer->transform($request);
        } catch (\Exception $e) {
            throw new PaymentGatewayException('failed to connect to Wayforpay: ' . $e->getMessage());
        }

        $reason = $response->getReason();
        if (!$reason->isOK()) {
            throw new PaymentGatewayException(
                sprintf('cancellation failed: %s (code: %s)', $reason->getMessage(), $reason->getCode())
            );
        }

        $subscription->status = SubscriptionStatus::CANCELLED();
        $subscription->save();
    }

    private function handleRenewal(TransactionService $transaction, Subscription $subscription): void
    {
        if ($transaction->isStatusApproved()) {
            $donation = $subscription->createRenewal([
                'gatewayTransactionId' => $transaction->getOrderReference(),
            ]);
            $donation->status = DonationStatus::COMPLETE();
            $donation->save();
            $subscription->bumpRenewalDate();
            $subscription->save();

            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf(
                    'Recurring payment successful! Card: %s, Authorization Code: %s',
                    $transaction->getCardPan(),
                    $transaction->getAuthCode()
                )
            ]);
        } else {
            SubscriptionNote::create([
                'subscriptionId' => $subscription->id,
                'content' => sprintf(
                    'Recurring payment was not approved. Status: %s, Reason: %s',
                    $transaction->getStatus(),
                    $transaction->getReason()->getCode()
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

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
use WayForPay\SDK\Domain\Client;
use WayForPay\SDK\Domain\Product;
use WayForPay\SDK\Domain\Regular;
use WayForPay\SDK\Domain\Reason;
use WayForPay\SDK\Domain\TransactionService;
use WayForPay\SDK\Client\CurlRequestTransformer;
use WayForPay\SDK\Handler\ServiceUrlHandler;
use WayForPay\SDK\Response\ServiceResponse;
use WayForPay\SDK\Wizard\PurchaseWizard;
use WayForPay\SDK\Wizard\RefundWizard;

class WayforpayGateway extends PaymentGateway implements WebhookNotificationsListener, PaymentGatewayRefundable {

	public $routeMethods = array(
		'handleReturnUrl',
		'webhookNotificationsListener',
	);

	/**
	 * Note: secureRouteMethods cannot yet be used. Wayforpay allows max 256 chars for returnUrl/serviceUrl.
	 * The addition of additional signature params in the URLs surpasses these 256 chars.
	 * It is likely that a feature request to Wayforpay will be needed.
	 */
	public $secureRouteMethods = array();

	#[\Override]
	public static function id(): string {
		return 'wayforpay-gateway';
	}

	#[\Override]
	public function getId(): string {
		return self::id();
	}

	#[\Override]
	public function getName(): string {
		return __( 'Wayforpay Gateway', 'wayforpay-givewp' );
	}

	#[\Override]
	public function getPaymentMethodLabel(): string {
		return __( 'Wayforpay', 'wayforpay-givewp' );
	}

	#[\Override]
	public function enqueueScript( int $formId ): void {
		// Support for forms built with the Visual Form Builder.
		wp_enqueue_script(
			'wayforpay-gateway-fe',
			WAYFORPAY_GIVEWP_PLUGIN_URL . 'js/fe.js',
			array( 'react', 'wp-element' ),
			WAYFORPAY_GIVEWP_VERSION,
			true
		);
	}

	#[\Override]
	public function formSettings( int $formId ): array {
		// The form settings to send to the JS counterpart. Used for Forms built using the Visual Form Builder.
		return array(
			'message' => __( 'You will be redirected to Wayforpay, a secure payment platform where you can pay by credit card, Apple Pay, or Google Pay.', 'wayforpay-givewp' ),
			'iconUrl' => WAYFORPAY_GIVEWP_PLUGIN_URL . 'assets/wayforpay-logo.svg',
		);
	}

	public function getLegacyFormFieldMarkup( int $formId, array $args ): string {
		// For consistency with the Visual Form Builder, display the same message and icon.
		$settings = $this->formSettings( $formId );
		return "<div class='wayforpay-gateway-help-text'>
                    <img src='" . esc_url( $settings['iconUrl'] ) . "' alt='Wayforpay' style='max-width: 160px; height: auto;' />
                    <p>" . esc_html__( $settings['message'], 'wayforpay-givewp' ) . '</p>
                </div>';
	}

	#[\Override]
	public function createPayment( Donation $donation, $gatewayData ): RedirectOffsite {
		$serviceUrlParams = array( 'donation-id' => $donation->id );
		return $this->redirectToWayforpay( $donation, $serviceUrlParams );
	}

	/**
	 * Redirects donor to an offsite Wayforpay payment page.
	 */
	public function redirectToWayforpay(
		Donation $donation,
		array $serviceUrlParams = array(),
		?Regular $recurringPayment = null
	): RedirectOffsite {
		$creds = WayforpaySettings::getCredentials();

		$returnUrl      = $this->generateGatewayRouteUrl(
			'handleReturnUrl',
			array( 'donation-id' => $donation->id )
		);
		$serviceUrl     = $this->webhook->getNotificationUrl( $serviceUrlParams );
		$amount         = $donation->amount->formatToDecimal();
		$currency       = strtoupper( $donation->amount->getCurrency()->getCode() );
		$orderReference = $donation->id . '-' . time();

		$campaign      = $donation->campaign()->get();
		$campaignTitle = $campaign?->title ?? null;
		if ( empty( $campaignTitle ) ) {
			DonationNote::create(
				array(
					'donationId' => $donation->id,
					'content'    => sprintf( 'Missing title from Campaign: %s', print_r( $campaign, true ) ),
				)
			);
			throw new PaymentGatewayException( 'missing campaign' );
		}
		// Wayforpay supports sending optional client metadata. This can help with analytics on the Wayforpay side.
		$client = new Client(
			nameFirst: $donation->firstName,
			nameLast: $donation->lastName,
			email: $donation->email,
			phone: $donation->phone,
			country: $donation->billingAddress->country,
			address: $donation->billingAddress->address1,
			city: $donation->billingAddress->city,
			state: $donation->billingAddress->state,
			zip: $donation->billingAddress->zip
		);

		try {
			$wizard = PurchaseWizard::get( $creds )
				->setOrderReference( $orderReference )
				->setAmount( $amount )
				->setCurrency( $currency )
				->setOrderDate( new \DateTime( '@' . $donation->createdAt->getTimestamp() ) )
				->setMerchantDomainName( wp_parse_url( home_url(), PHP_URL_HOST ) )
				->setClient( $client )
				->setProducts( new ProductCollection( array( new Product( $campaignTitle, $amount, 1 ) ) ) )
				->setReturnUrl( $returnUrl )
				->setServiceUrl( $serviceUrl )
				->setLanguage( substr( get_bloginfo( 'language' ), 0, 2 ) )
				->setMerchantTransactionSecureType( 'AUTO' ); // Default as per previous code
			if ( $recurringPayment ) {
				$wizard->setRegular( $recurringPayment );
			}

			// Note: we do not use the standard Wayforpay SDK for sending the request. We want to control the redirect on the server side.
			$form          = $wizard->getForm();
			$wayforpayArgs = array_filter( $form->getData() ); // array_filter to exclude unset values from URL params.

			DonationNote::create(
				array(
					'donationId' => $donation->id,
					'content'    => sprintf(
						'Redirecting donor to Wayforpay. Order: %s, Amount: %s %s%s',
						$wayforpayArgs['orderReference'],
						$wayforpayArgs['amount'],
						$wayforpayArgs['currency'],
						$recurringPayment ? ' (recurring)' : ''
					),
				)
			);

			$wayforpayRequest = array(
				'timeout'     => 10,
				'headers'     => array(
					'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
				),
				// Uses form encoding for the arguments within the body.
				'body'        => http_build_query( $wayforpayArgs ),
				// The server should not redirect to Wayforpay's provided redirect URL.
				// Instead, the server will pass the redirect location to the browser.
				'redirection' => 0,
			);
			$wayforpayResponse = wp_remote_post( $form->getEndpoint()->getUrl(), $wayforpayRequest );

			$responseBody    = wp_remote_retrieve_body( $wayforpayResponse );
			$responseHeaders = wp_remote_retrieve_headers( $wayforpayResponse );
			if ( is_wp_error( $wayforpayResponse ) ) {
				DonationNote::create(
					array(
						'donationId' => $donation->id,
						'content'    => sprintf(
							'Payment failed: Could not connect to Wayforpay. Error: %s',
							$wayforpayResponse->get_error_message()
						),
					)
				);
				throw new PaymentGatewayException( 'could not connect to Wayforpay' );
			}

			$httpCode      = wp_remote_retrieve_response_code( $wayforpayResponse );
			$redirectCodes = array( 301, 302, 303, 307, 308 );
			if ( ! in_array( $httpCode, $redirectCodes ) ) {
				DonationNote::create(
					array(
						'donationId' => $donation->id,
						'content'    => sprintf(
							'Payment failed: Expected Wayforpay to redirect but got HTTP %d. Response: %s',
							$httpCode,
							$responseBody
						),
					)
				);
				throw new PaymentGatewayException( 'no redirect HTTP code provided' );
			}

			$wayforPayRedirect = $responseHeaders['Location'];
			if ( empty( $wayforPayRedirect ) ) {
				DonationNote::create(
					array(
						'donationId' => $donation->id,
						'content'    => sprintf( 'Payment failed: Wayforpay did not provide a Location in headers: %s', $responseHeaders ),
					)
				);
				throw new PaymentGatewayException( 'no redirect URL provided' );
			}

			DonationNote::create(
				array(
					'donationId' => $donation->id,
					'content'    => sprintf( 'Sending user to payment URL: %s', $wayforPayRedirect ),
				)
			);
			return new RedirectOffsite( $wayforPayRedirect );
		} catch ( \Exception $e ) {
			DonationNote::create(
				array(
					'donationId' => $donation->id,
					'content'    => sprintf( 'Payment failed: unknown error %s.', $e->getMessage() ),
				)
			);
			throw new PaymentGatewayException( 'unknown error' );
		}
	}

	/**
	 * Handle redirection from Wayforpay back to the site after payment.
	 * This is purely UX; any donation updating logic should be handled via webhooks to serviceUrl.
	 */
	protected function handleReturnUrl( array $queryParams ): RedirectResponse {
		$creds = WayforpaySettings::getCredentials();

		// WayForPay may POST transaction data here, but we don't rely on it for status updates.
		// The serviceUrl webhook is the authoritative source for updating payment status for GiveWP.
		$data = stripslashes_deep( $_POST );

		$donationId = isset( $queryParams['donation-id'] ) ? (int) $queryParams['donation-id'] : null;
		if ( empty( $donationId ) ) {
			throw new PaymentGatewayException( 'no donation-id parameter received from Wayforpay' );
		}

		if ( empty( $data ) ) {
			throw new PaymentGatewayException( 'no data received from Wayforpay' );
		}

		// If Wayforpay doesn't include a transactionStatus, the user likely cancelled on the payment page.
		// Attempt redirect to the most relevant page on the original site: Campaign > Form > Homepage
		if ( empty( $data['transactionStatus'] ) ) {
			DonationNote::create(
				array(
					'donationId' => $donationId,
					'content'    => sprintf(
						'User cancelled on Wayforpay. Redirecting to failure page. Query params: %s, POST data: %s',
						print_r( $queryParams, true ),
						print_r( $data, true )
					),
				)
			);
			return new RedirectResponse(
				give_get_failed_transaction_uri(
					'gateway-error=' . urlencode( __( 'Payment cancelled', 'wayforpay-givewp' ) )
				)
			);
		}

		// If the returnUrl is registered in secureRouteMethods, a signature will be sent for verification.
		// If in routeMethods, it's okay to continue because there are no sensitive operations done via returnUrl.
		if ( ! empty( $data['merchantSignature'] ) ) {
			try {
				$handler  = new ServiceUrlHandler( $creds );
				$response = $handler->parseRequestFromArray( $data );
			} catch ( \Exception $e ) {
				throw new PaymentGatewayException( 'invalid signature received from Wayforpay: ' . $e->getMessage() );
			}
		} else {
			try {
				$response = new ServiceResponse( $data );
			} catch ( \Exception $e ) {
				throw new PaymentGatewayException( 'invalid response data from Wayforpay: ' . $e->getMessage() );
			}
		}

		// Decide which page to show, mirroring the webhook's status-based logic. Wayforpay may bounce the
		// donor's browser back here while the transaction is still being authorized (Created / InProcessing /
		// WaitingAuthComplete / Pending). Those are NOT failures — the card can still clear moments later, at
		// which point the serviceUrl webhook marks the donation complete.
		$transaction = $response->getTransaction();
		$reason      = $response->getReason();

		// The donor explicitly cancelled on the Wayforpay page. This can arrive alongside an in-flight status,
		// so it's checked separately and must NOT be treated as "still processing".
		$cancelled = $reason->getCode() === Reason::CODE_CARDHOLDER_CANCELLED_REQUEST;

		// Statuses that mean "still being authorized" — the only ones safe to send to the receipt page.
		// Everything else (Declined, Expired, Voided, or anything unexpected) is treated as a failure.
		$stillProcessing = $transaction->isStatusCreated()
			|| $transaction->isStatusInProcessing()
			|| $transaction->isStatusWaitAuthComplete()
			|| $transaction->isStatusPending();

		if ( $transaction->isStatusApproved() ) {
			DonationNote::create(
				array(
					'donationId' => $donationId,
					'content'    => sprintf(
						'Payment successful: redirecting user to success page. Query params: %s, POST data: %s',
						print_r( $queryParams, true ),
						print_r( $data, true )
					),
				)
			);
			return new RedirectResponse( give_get_success_page_uri() );
		} elseif ( $stillProcessing && ! $cancelled ) {
			// Don't show a failure page — the webhook is the authoritative source of truth and will finalize
			// the donation. Send the donor to the receipt page, which reflects the pending status.
			// A `gateway-status=pending` query param (mirroring `gateway-error` on the failure page below)
			// lets the receipt page differentiate this in-flight case (e.g. show a "your payment is being
			// processed" notice) from a fully-confirmed payment.
			DonationNote::create(
				array(
					'donationId' => $donationId,
					'content'    => sprintf(
						'Payment pending at return (status: %s): redirecting user to receipt page; awaiting confirmation. Query params: %s, POST data: %s',
						$transaction->getStatus(),
						print_r( $queryParams, true ),
						print_r( $data, true )
					),
				)
			);
			return new RedirectResponse( add_query_arg( 'gateway-status', 'pending', give_get_success_page_uri() ) );
		} else {
			$errorMessage = $cancelled
				? __( 'Payment cancelled', 'wayforpay-givewp' )
				: $this->getDisplayErrorMessage( $reason );
			DonationNote::create(
				array(
					'donationId' => $donationId,
					'content'    => sprintf(
						'Payment %s: redirecting user to failure page. Query params: %s, POST data: %s',
						$cancelled ? 'cancelled' : 'failed',
						print_r( $queryParams, true ),
						print_r( $data, true )
					),
				)
			);
			return new RedirectResponse(
				give_get_failed_transaction_uri(
					'gateway-error=' . urlencode( $errorMessage )
				)
			);
		}
	}

	/**
	 * Handles incoming serviceUrl webhook calls from Wayforpay.
	 * Allows updating donation status even if user closes the browser on the Wayforpay site.
	 * WayForPay will retry this endpoint periodically until it receives a valid acknowledgment.
	 */
	#[\Override]
	public function webhookNotificationsListener(): void {
		$creds = WayforpaySettings::getCredentials();

		$handler = new ServiceUrlHandler( $creds );
		try {
			/** @var ServiceResponse $response */
			$response = $handler->parseRequestFromPostRaw();
		} catch ( \Exception $e ) {
			error_log( sprintf( 'Wayforpay webhook error: %s', $e->getMessage() ) );
			wp_die( 'Error: Unable to process request', '', array( 'response' => 403 ) );
		}

		$queryParams = give_clean( $_GET );
		$donationId  = isset( $queryParams['donation-id'] ) ? (int) $queryParams['donation-id'] : null;
		if ( empty( $donationId ) ) {
			wp_die( 'Donation ID missing', '', array( 'response' => 404 ) );
		}
		$donation = Donation::find( $donationId );
		if ( empty( $donation ) ) {
			wp_die( 'Donation not found', '', array( 'response' => 404 ) );
		}

		$transaction     = $response->getTransaction();
		$orderReference  = $transaction->getOrderReference();
		$status          = $transaction->getStatus();
		$sendAckResponse = function () use ( $handler, $transaction ) {
			// send ack receipt to Wayforpay.
			if ( ! headers_sent() ) {
				header( 'Content-Type: application/json; charset=utf-8' );
			}
			echo $handler->getSuccessResponse( $transaction );
			if ( class_exists( '\WPDieException' ) ) { // For unit testing.
				throw new \WPDieException( '', 200 );
			}
			exit;
		};

		// Handle subscription renewal webhooks.
		// If subscription-id is present, it may be a renewal or the initial subscription payment.
		$subscription   = null;
		$subscriptionId = $queryParams['subscription-id'] ?? null;
		if ( ! empty( $subscriptionId ) ) {
			$subscription = Subscription::find( $subscriptionId );
			if ( empty( $subscription ) ) {
				wp_die( 'Subscription not found', '', array( 'response' => 404 ) );
			}
			// Only handle as renewal if it's not the initial payment (donation already complete).
			if ( $donation->status->isComplete() ) {
				$this->handleRenewal( $transaction, $subscription );
				$sendAckResponse();
			}
			// For initial subscription payments, fall through to update the donation status below.
		}

		if ( $transaction->isStatusApproved() ) {
			if ( ! $donation->status->isComplete() ) {
				$donation->status               = DonationStatus::COMPLETE();
				$donation->gatewayTransactionId = $orderReference;
				$donation->save();

				// If this payment is part of a Subscription, link the order reference to the subscription.
				// This allows sending cancellation requests to Wayforpay from the GiveWP side.
				if ( ! empty( $subscription ) && empty( $subscription->gatewaySubscriptionId ) ) {
					$subscription->gatewaySubscriptionId = $orderReference;
					$subscription->save();
				}

				DonationNote::create(
					array(
						'donationId' => $donation->id,
						'content'    => sprintf(
							'Payment successful. Order reference: %s',
							$orderReference
						),
					)
				);
			}
		} elseif ( $transaction->isStatusDeclined() || $transaction->isStatusExpired() ) {
			if ( ! $donation->status->isFailed() ) {
				$donation->status = DonationStatus::FAILED();
				$donation->save();

				DonationNote::create(
					array(
						'donationId' => $donation->id,
						'content'    => sprintf(
							'Payment failed. Order reference: %s. Status: %s. Reason: %s',
							$orderReference,
							$status,
							$response->getReason()->getMessage()
						),
					)
				);
			}
		} else {
			DonationNote::create(
				array(
					'donationId' => $donation->id,
					'content'    => sprintf( 'Wayforpay sent a status update: %s.', $status ),
				)
			);
		}

		$sendAckResponse();
	}

	#[\Override]
	public function refundDonation( Donation $donation ): PaymentRefunded {
		$creds = WayforpaySettings::getCredentials();

		$orderReference = $donation->gatewayTransactionId;
		$amount         = $donation->amount->formatToDecimal();
		$currency       = strtoupper( $donation->amount->getCurrency()->getCode() );
		if ( empty( $orderReference ) || empty( $amount ) || empty( $currency ) ) {
			DonationNote::create(
				array(
					'donationId' => $donation->id,
					'content'    => sprintf(
						'Refund failed: Missing required data. Order: %s, Amount: %s %s',
						$orderReference,
						$amount,
						$currency
					),
				)
			);
			throw new PaymentGatewayException( 'cannot process refund: required transaction data is missing.' );
		}

		DonationNote::create(
			array(
				'donationId' => $donation->id,
				'content'    => sprintf(
					'Attempting refund of order %s via Wayforpay for amount %s %s',
					$orderReference,
					$amount,
					$currency
				),
			)
		);

		try {
			$response = RefundWizard::get( $creds )
				->setOrderReference( $orderReference )
				->setAmount( $amount )
				->setCurrency( $currency )
				->setComment( 'Refund initiated from GiveWP' )
				->getRequest()
				->send();
		} catch ( \Exception $e ) {
			DonationNote::create(
				array(
					'donationId' => $donation->id,
					'content'    => sprintf( 'Refund failed: Could not connect to Wayforpay. Error: %s', $e->getMessage() ),
				)
			);
			throw new PaymentGatewayException( 'could not connect to Wayforpay' );
		}

		if ( $response->getReason()->isOK() ) {
			DonationNote::create(
				array(
					'donationId' => $donation->id,
					'content'    => sprintf(
						'Refund approved. Status: %s. Order: %s',
						$response->getTransactionStatus(),
						$response->getOrderReference()
					),
				)
			);
			return new PaymentRefunded();
		}

		DonationNote::create(
			array(
				'donationId' => $donation->id,
				'content'    => sprintf(
					'Refund declined. Status: %s. Reason: %s',
					$response->getTransactionStatus(),
					$response->getReason()->getMessage()
				),
			)
		);
		throw new PaymentGatewayException(
			sprintf(
				'Refund declined: %s (Code: %s)',
				$response->getTransactionStatus(),
				$response->getReason()->getCode()
			)
		);
	}


	// ==================== Support for recurring payments ====================

	#[\Override]
	public function supportsSubscriptions(): bool {
		return true;
	}

	#[\Override]
	public function createSubscription(
		Donation $donation,
		Subscription $subscription,
		$gatewayData
	): RedirectOffsite {
		$periodMap   = array( // GiveWP subscription periods => Wayforpay regular modes.
			'day'     => Regular::MODE_DAYLY,
			'week'    => Regular::MODE_WEEKLY,
			'month'   => Regular::MODE_MONTHLY,
			'quarter' => Regular::MODE_QUARTERLY,
			'year'    => Regular::MODE_YEARLY,
		);
		$period      = $subscription->period->getValue();
		$regularMode = $periodMap[ $period ] ?? null;
		if ( empty( $regularMode ) ) {
			throw new PaymentGatewayException( sprintf( 'unsupported subscription period: %s', $period ) );
		}

		$amount   = $donation->amount->formatToDecimal();
		$dateNext = new \DateTime( $this->calculateNextDate( $period, $subscription->frequency ) );
		// Indefinite subscription; Wayforpay doesn't have an explicit indefinite mode, so use a far in the future date.
		$dateEnd = $subscription->installments === 0 ? new \DateTime( '+100 years' ) : null;
		// Fixed installments; subtract one payment because the initial payment is made too.
		$count            = $subscription->installments > 0 ? $subscription->installments - 1 : null;
		$recurringPayment = new Regular(
			modes: array( $regularMode ),
			amount: $amount,
			dateNext: $dateNext,
			dateEnd: $dateEnd,
			count: $count,
			on: true,
			behavior: Regular::BEHAVIOR_PRESET
		);

		$serviceUrlParams = array(
			'donation-id'     => $donation->id,
			'subscription-id' => $subscription->id,
		);
		return $this->redirectToWayforpay(
			$donation,
			$serviceUrlParams,
			$recurringPayment
		);
	}

	#[\Override]
	public function cancelSubscription( Subscription $subscription ): void {
		$orderReference = $subscription->gatewaySubscriptionId;
		if ( empty( $orderReference ) ) {
			SubscriptionNote::create(
				array(
					'subscriptionId' => $subscription->id,
					'content'        => 'Subscription cancelled. No order reference; no cancellation necessary with Wayforpay.',
				)
			);
			$subscription->status = SubscriptionStatus::CANCELLED();
			$subscription->save();
			return;
		}

		SubscriptionNote::create(
			array(
				'subscriptionId' => $subscription->id,
				'content'        => sprintf( 'Attempting to remove subscription %s in Wayforpay.', $orderReference ),
			)
		);

		$passwordCreds = WayforpaySettings::getPasswordCredentials();
		$request       = new RemoveSubscriptionRequest( $passwordCreds->getAccount(), $passwordCreds->getPassword(), $orderReference );
		try {
			$transformer = new CurlRequestTransformer();
			/** @var \WayForPay\SDK\Response\Response $response */
			$response = $transformer->transform( $request );
		} catch ( \Exception $e ) {
			SubscriptionNote::create(
				array(
					'subscriptionId' => $subscription->id,
					'content'        => sprintf( 'Failed to connect to Wayforpay: %s', $e->getMessage() ),
				)
			);
			throw new PaymentGatewayException( 'failed to connect to Wayforpay: ' . $e->getMessage() );
		}

		$reason = $response->getReason();
		if ( ! $reason->isOK() && $reason->getCode() !== Reason::CODE_ORDER_NOT_FOUND ) {
			SubscriptionNote::create(
				array(
					'subscriptionId' => $subscription->id,
					'content'        => sprintf(
						'Failed to remove subscription in Wayforpay: %s (code: %s)',
						$reason->getMessage(),
						$reason->getCode()
					),
				)
			);
			throw new PaymentGatewayException(
				sprintf( 'cancellation failed: %s (code: %s)', $reason->getMessage(), $reason->getCode() )
			);
		}

		if ( $reason->isOK() ) {
			SubscriptionNote::create(
				array(
					'subscriptionId' => $subscription->id,
					'content'        => 'Successfully removed subscription in Wayforpay.',
				)
			);
		} else {
			SubscriptionNote::create(
				array(
					'subscriptionId' => $subscription->id,
					'content'        => sprintf(
						'Wayforpay did not find the subscription (code: %s). Locally marking Subscription as cancelled.',
						$reason->getCode()
					),
				)
			);
		}

		$subscription->status = SubscriptionStatus::CANCELLED();
		$subscription->save();
	}

	private function handleRenewal( TransactionService $transaction, Subscription $subscription ): void {
		$orderReference = $transaction->getOrderReference();
		if ( $transaction->isStatusApproved() ) {
			// Idempotency: skip if a donation with this GatewayTransactionId already exists.
			$existingDonation = give()->donations->getByGatewayTransactionId( $orderReference );
			if ( $existingDonation ) {
				SubscriptionNote::create(
					array(
						'subscriptionId' => $subscription->id,
						'content'        => sprintf(
							'Skipped renewal attempt. Order reference: %s already linked to Donation #%d.',
							$orderReference,
							$existingDonation->id
						),
					)
				);
				return;
			}

			$donation = $subscription->createRenewal(
				array(
					'gatewayTransactionId' => $orderReference,
				)
			);
			DonationNote::create(
				array(
					'donationId' => $donation->id,
					'content'    => sprintf(
						'Recurring payment successful! Order reference: %s',
						$orderReference
					),
				)
			);
		} else {
			SubscriptionNote::create(
				array(
					'subscriptionId' => $subscription->id,
					'content'        => sprintf(
						'Recurring payment was not approved. Order reference: %s, Status: %s, Reason: %s',
						$orderReference,
						$transaction->getStatus(),
						$transaction->getReason()->getCode()
					),
				)
			);
		}
	}

	/**
	 * User-friendly, translatable strings that can be shown in the frontend.
	 *
	 * @see https://wiki.wayforpay.com/en/view/852131
	 */
	private function getDisplayErrorMessage( Reason $reason ): string {
		return match ( $reason->getCode() ) {
			Reason::CODE_DECLINED_TO_CARD_ISSUER => __( 'Declined by card issuer', 'wayforpay-givewp' ),
			Reason::CODE_BAD_CVV2 => __( 'Invalid CVV code', 'wayforpay-givewp' ),
			Reason::CODE_EXPIRED_CARD => __( 'Card expired', 'wayforpay-givewp' ),
			Reason::CODE_INSUFFICIENT_FUNDS => __( 'Insufficient funds', 'wayforpay-givewp' ),
			Reason::CODE_INVALID_CARD => __( 'Invalid card number', 'wayforpay-givewp' ),
			Reason::CODE_EXCEED_WITHDRAWAL_FREQUENCY => __( 'Withdrawal frequency exceeded', 'wayforpay-givewp' ),
			Reason::CODE_3DS_FAIL => __( '3D Secure verification failed', 'wayforpay-givewp' ),
			Reason::CODE_INVALID_CURRENCY => __( 'Invalid currency', 'wayforpay-givewp' ),
			Reason::CODE_FRAUD => __( 'Transaction declined', 'wayforpay-givewp' ),
			Reason::CODE_GATE_DECLINED => __( 'Transaction declined', 'wayforpay-givewp' ),
			Reason::CODE_CARDHOLDER_SESSION_EXPIRED => __( 'Card payment session expired', 'wayforpay-givewp' ),
			Reason::CODE_RESTRICTED_CARD => __( 'Card is restricted', 'wayforpay-givewp' ),
			Reason::CODE_CARD_LIMITS_FAILED => __( 'Card limit exceeded', 'wayforpay-givewp' ),
			default => $reason->getMessage() ?: __( 'Payment declined', 'wayforpay-givewp' ),
		};
	}

	private function calculateNextDate( string $period, int $frequency ): string {
		$now      = new \DateTimeImmutable();
		$modifier = match ( $period ) {
			'day' => "+{$frequency} days",
			'week' => "+{$frequency} weeks",
			'month' => "+{$frequency} months",
			'quarter' => '+' . ( $frequency * 3 ) . ' months',
			'year' => "+{$frequency} years",
			default => '+0 days',
		};
		return $now->modify( $modifier )->format( 'd.m.Y' );
	}
}

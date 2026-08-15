<?php

namespace WayforpayGiveWP;

use WayForPay\SDK\Contract\EndpointInterface;
use WayForPay\SDK\Contract\RequestInterface;
use WayForPay\SDK\Endpoint\ApiRegularEndpoint;

/**
 * Custom request for removing a subscription from Wayforpay.
 * The Wayforpay SDK doesn't provide this request type, so we implement it here.
 */
class WayforpayRemoveSubscriptionRequest implements RequestInterface {

	private string $account;
	private string $password;
	private string $orderReference;

	public function __construct( string $account, string $password, string $orderReference ) {
		$this->account        = $account;
		$this->password       = $password;
		$this->orderReference = $orderReference;
	}

	#[\Override]
	public function getTransactionData(): array {
		return array(
			'requestType'      => 'REMOVE',
			'merchantAccount'  => $this->account,
			'merchantPassword' => $this->password,
			'orderReference'   => $this->orderReference,
		);
	}

	#[\Override]
	public function getTransactionType(): string {
		return 'REMOVE';
	}

	#[\Override]
	public function getEndpoint(): EndpointInterface {
		return new ApiRegularEndpoint();
	}

	#[\Override]
	public function setEndpoint( EndpointInterface $endpoint ): self {
		return $this; // No-op, endpoint is fixed.
	}

	#[\Override]
	public function getResponse( array $data ): \WayForPay\SDK\Response\Response {
		return new \WayForPay\SDK\Response\Response( $data );
	}
}

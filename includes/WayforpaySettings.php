<?php

/**
 * Wayforpay Gateway Settings
 *
 * Registers settings in GiveWP's admin under:
 * Donations → Settings → Payment Gateways → Wayforpay
 *
 * @package WayforpayGiveWP
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;
use WayForPay\SDK\Credential\AccountSecretCredential;
use WayForPay\SDK\Credential\AccountPasswordCredential;

/**
 * Class WayforpaySettings
 *
 * Handles admin settings registration, rendering, and credential access for Wayforpay gateway.
 */
class WayforpaySettings {

	/**
	 * Register the settings section and fields with GiveWP
	 *
	 * @return void
	 */
	public static function register(): void {
		// Add new UI elements to the Payment Gateways tab in GiveWP.
		add_filter( 'give_get_sections_gateways', array( self::class, 'addSection' ) );
		add_filter( 'give_get_settings_gateways', array( self::class, 'addSettings' ) );
	}

	/**
	 * Add Wayforpay section to the gateways tab
	 *
	 * @param array $sections Existing gateway sections
	 * @return array Modified sections array
	 */
	public static function addSection( array $sections ): array {
		$sections['wayforpay'] = __( 'Wayforpay', 'wayforpay-givewp' );
		return $sections;
	}

	/**
	 * Add settings fields for the Wayforpay section
	 *
	 * @param array $settings Existing gateway settings
	 * @return array Modified settings array
	 */
	public static function addSettings( array $settings ): array {
		$currentSection = give_get_current_setting_section();
		if ( $currentSection !== 'wayforpay' ) {
			return $settings;
		}

		return array(
			array(
				'id'   => 'give_title_wayforpay',
				'type' => 'title',
			),
			array(
				'name' => __( 'Wayforpay Settings', 'wayforpay-givewp' ),
				'desc' => __( 'Configure your Wayforpay payment gateway credentials. You can find these in your Wayforpay merchant dashboard.', 'wayforpay-givewp' ),
				'type' => 'give_title',
				'id'   => 'wayforpay_settings_header',
			),
			array(
				'name'    => __( 'Merchant Account', 'wayforpay-givewp' ),
				'desc'    => __( 'Enter your Wayforpay merchant account identifier (e.g., www_example_com). This is provided in your Wayforpay dashboard.', 'wayforpay-givewp' ),
				'id'      => 'wayforpay_merchant_account',
				'type'    => 'text',
				'default' => '',
			),
			array(
				'name'    => __( 'Secret Key', 'wayforpay-givewp' ),
				'desc'    => __( 'Enter your Wayforpay secret key used for signing API requests. Keep this secure and never share it publicly.', 'wayforpay-givewp' ),
				'id'      => 'wayforpay_secret_key',
				'type'    => 'api_key',
				'default' => '',
			),
			array(
				'name'    => __( 'Merchant Password', 'wayforpay-givewp' ),
				'desc'    => __( 'Your Wayforpay merchant password. Only required for subscription cancellation functionality.', 'wayforpay-givewp' ),
				'id'      => 'wayforpay_merchant_password',
				'type'    => 'api_key',
				'default' => '',
			),
			array(
				'id'   => 'give_title_wayforpay',
				'type' => 'sectionend',
			),

			// Test Mode Settings. Used when GiveWP Test Mode is enabled.
			// See: https://wiki.wayforpay.com/en/view/852472 for test credentials.
			array(
				'id'   => 'give_title_wayforpay_test',
				'type' => 'title',
			),
			array(
				'name' => __( 'Test Mode Settings', 'wayforpay-givewp' ),
				'desc' => __( 'Configure credentials for the Wayforpay test environment.', 'wayforpay-givewp' ),
				'type' => 'give_title',
				'id'   => 'wayforpay_test_settings_header',
			),
			array(
				'name'    => __( 'Test Merchant Account', 'wayforpay-givewp' ),
				'desc'    => __( 'Wayforpay test merchant account. Used when GiveWP Test Mode is enabled. See: https://wiki.wayforpay.com/en/view/852472', 'wayforpay-givewp' ),
				'id'      => 'wayforpay_test_merchant_account',
				'type'    => 'text',
				'default' => '',
			),
			array(
				'name'    => __( 'Test Secret Key', 'wayforpay-givewp' ),
				'desc'    => __( 'Wayforpay test secret key. Used when GiveWP Test Mode is enabled. See: https://wiki.wayforpay.com/en/view/852472', 'wayforpay-givewp' ),
				'id'      => 'wayforpay_test_secret_key',
				'type'    => 'text',
				'default' => '',
			),
			array(
				'name'    => __( 'Test Merchant Password', 'wayforpay-givewp' ),
				'desc'    => __( 'Wayforpay test merchant password. Used when GiveWP Test Mode is enabled. See: https://wiki.wayforpay.com/en/view/852521', 'wayforpay-givewp' ),
				'id'      => 'wayforpay_test_merchant_password',
				'type'    => 'text',
				'default' => '',
			),
			array(
				'id'   => 'give_title_wayforpay_test',
				'type' => 'sectionend',
			),
		);
	}

	private static function getMerchantAccount(): string {
		if ( self::isTestMode() ) {
			return give_get_option( 'wayforpay_test_merchant_account', '' );
		}
		return give_get_option( 'wayforpay_merchant_account', '' );
	}

	private static function getSecretKey(): string {
		if ( self::isTestMode() ) {
			return give_get_option( 'wayforpay_test_secret_key', '' );
		}
		return give_get_option( 'wayforpay_secret_key', '' );
	}

	private static function getMerchantPassword(): string {
		if ( self::isTestMode() ) {
			return give_get_option( 'wayforpay_test_merchant_password', '' );
		}
		return give_get_option( 'wayforpay_merchant_password', '' );
	}

	private static function isTestMode(): bool {
		return give_is_test_mode(); // Reuse the GiveWP test mode setting.
	}

	/**
	 * For API requests that use signature validation.
	 *
	 * @throws PaymentGatewayException If credentials are not configured
	 */
	public static function getCredentials(): AccountSecretCredential {
		$account = self::getMerchantAccount();
		$secret  = self::getSecretKey();
		if ( empty( $account ) || empty( $secret ) ) {
			throw new PaymentGatewayException( 'Wayforpay is not configured' );
		}
		return new AccountSecretCredential( $account, $secret );
	}

	/**
	 * For API requests that use password authentication.
	 *
	 * @throws PaymentGatewayException If credentials are not configured
	 */
	public static function getPasswordCredentials(): AccountPasswordCredential {
		$account  = self::getMerchantAccount();
		$password = self::getMerchantPassword();
		if ( empty( $account ) || empty( $password ) ) {
			throw new PaymentGatewayException( 'Wayforpay is not configured' );
		}
		return new AccountPasswordCredential( $account, $password );
	}
}

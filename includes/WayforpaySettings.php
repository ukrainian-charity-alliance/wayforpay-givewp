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
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WayforpaySettings
 *
 * Handles admin settings registration and rendering for Wayforpay gateway.
 */
class WayforpaySettings
{
    /**
     * Register the settings section and fields with GiveWP
     *
     * @return void
     */
    public static function register(): void
    {
        // Add new UI elements to the Payment Gateways tab in GiveWP.
        add_filter('give_get_sections_gateways', [self::class, 'addSection']);
        add_filter('give_get_settings_gateways', [self::class, 'addSettings']);
    }

    /**
     * Add Wayforpay section to the gateways tab
     *
     * @param array $sections Existing gateway sections
     * @return array Modified sections array
     */
    public static function addSection(array $sections): array
    {
        $sections['wayforpay'] = __('Wayforpay', 'wayforpay-givewp');
        return $sections;
    }

    /**
     * Add settings fields for the Wayforpay section
     *
     * @param array $settings Existing gateway settings
     * @return array Modified settings array
     */
    public static function addSettings(array $settings): array
    {
        $currentSection = give_get_current_setting_section();
        if ($currentSection !== 'wayforpay') {
            return $settings;
        }

        return [
            [
                'id' => 'give_title_wayforpay',
                'type' => 'title',
            ],
            [
                'name' => __('Wayforpay Settings', 'wayforpay-givewp'),
                'desc' => __('Configure your Wayforpay payment gateway credentials. You can find these in your Wayforpay merchant dashboard.', 'wayforpay-givewp'),
                'type' => 'give_title',
                'id' => 'wayforpay_settings_header',
            ],
            [
                'name' => __('Merchant Account', 'wayforpay-givewp'),
                'desc' => __('Enter your Wayforpay merchant account identifier (e.g., www_example_com). This is provided in your Wayforpay dashboard.', 'wayforpay-givewp'),
                'id' => 'wayforpay_merchant_account',
                'type' => 'text',
                'default' => '',
            ],
            [
                'name' => __('Secret Key', 'wayforpay-givewp'),
                'desc' => __('Enter your Wayforpay secret key used for signing API requests. Keep this secure and never share it publicly.', 'wayforpay-givewp'),
                'id' => 'wayforpay_secret_key',
                'type' => 'api_key',
                'default' => '',
            ],
            [
                'name' => __('Merchant Password', 'wayforpay-givewp'),
                'desc' => __('Your Wayforpay merchant password. Only required for subscription cancellation functionality.', 'wayforpay-givewp'),
                'id' => 'wayforpay_merchant_password',
                'type' => 'api_key',
                'default' => '',
            ],
            [
                'id' => 'give_title_wayforpay',
                'type' => 'sectionend',
            ],

            // Test Mode Settings. Used when GiveWP Test Mode is enabled.
            // See: https://wiki.wayforpay.com/en/view/852472 for test credentials.
            [
                'id' => 'give_title_wayforpay_test',
                'type' => 'title',
            ],
            [
                'name' => __('Test Mode Settings', 'wayforpay-givewp'),
                'desc' => __('Configure credentials for the Wayforpay test environment.', 'wayforpay-givewp'),
                'type' => 'give_title',
                'id' => 'wayforpay_test_settings_header',
            ],
            [
                'name' => __('Test Merchant Account', 'wayforpay-givewp'),
                'desc' => __('Wayforpay test merchant account. Used when GiveWP Test Mode is enabled. See: https://wiki.wayforpay.com/en/view/852472', 'wayforpay-givewp'),
                'id' => 'wayforpay_test_merchant_account',
                'type' => 'text',
                'default' => '',
            ],
            [
                'name' => __('Test Secret Key', 'wayforpay-givewp'),
                'desc' => __('Wayforpay test secret key. Used when GiveWP Test Mode is enabled. See: https://wiki.wayforpay.com/en/view/852472', 'wayforpay-givewp'),
                'id' => 'wayforpay_test_secret_key',
                'type' => 'text',
                'default' => '',
            ],
            [
                'name' => __('Test Merchant Password', 'wayforpay-givewp'),
                'desc' => __('Wayforpay test merchant password. Used when GiveWP Test Mode is enabled. See: https://wiki.wayforpay.com/en/view/852521', 'wayforpay-givewp'),
                'id' => 'wayforpay_test_merchant_password',
                'type' => 'text',
                'default' => '',
            ],
            [
                'id' => 'give_title_wayforpay_test',
                'type' => 'sectionend',
            ],
        ];
    }

    public static function getMerchantAccount(): string
    {
        if (self::isTestMode()) {
            return give_get_option('wayforpay_test_merchant_account', '');
        }
        return give_get_option('wayforpay_merchant_account', '');
    }

    public static function getSecretKey(): string
    {
        if (self::isTestMode()) {
            return give_get_option('wayforpay_test_secret_key', '');
        }
        return give_get_option('wayforpay_secret_key', '');
    }

    public static function getMerchantPassword(): string
    {
        if (self::isTestMode()) {
            return give_get_option('wayforpay_test_merchant_password', '');
        }
        return give_get_option('wayforpay_merchant_password', '');
    }

    public static function isTestMode(): bool
    {
        return give_is_test_mode(); // Reuse the GiveWP test mode setting.
    }
}

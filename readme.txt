=== Wayforpay Gateway for GiveWP ===
Contributors: radion
Donate link: https://uba.com.ua
Tags: givewp, wayforpay, payment gateway, donations, ukraine
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: trunk
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Adds Wayforpay as an off-site payment gateway for GiveWP.

== Description ==

Registers [Wayforpay](https://wayforpay.com/) as a custom payment gateway for [GiveWP](https://wordpress.org/plugins/give/).
Donors pay on Wayforpay's hosted payment page; the plugin does not handle card data on your site.

Payment status is set by a server-to-server webhook from Wayforpay, whose signature is verified before any donation is updated.

### Supported

* One-time donations.
* Recurring donations (subscriptions), including renewals and cancellation.
* Refunds from the GiveWP donation screen.
* GiveWP Test Mode, with separate live and test credentials.

### Requirements

* WordPress 6.6 or later.
* PHP 8.3 or later.
* GiveWP.
* A Wayforpay merchant account.

== Installation ==

1. Install and activate GiveWP.
2. Install and activate this plugin.
3. Go to **Donations > Settings > Payment Gateways** and enable Wayforpay.
4. Enter your Wayforpay Merchant Account and Secret Key.

== Frequently Asked Questions ==

= Do I need a Wayforpay account? =

Yes. A merchant account with Wayforpay is required to accept payments.

= Are card details entered on my site? =

No. Donors are redirected to Wayforpay's hosted page to pay.

== Screenshots ==

1. Donation form: amount selection with an option to make the donation monthly.
2. Donor details and donation summary, with the Wayforpay redirect notice before payment.

== Changelog ==

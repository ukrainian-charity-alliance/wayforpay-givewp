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

== External services ==

This plugin relies on Wayforpay (https://wayforpay.com/), a third-party payment
provider, to process donations. Payment cannot work without it.

Donors are redirected to Wayforpay's hosted payment page to pay, so no card
details are entered on or handled by your site.

The plugin sends data to Wayforpay in these situations:

* **When a donor submits a donation.** The donation is registered with Wayforpay
  and the donor is redirected to its payment page. Sent: the donor's first and
  last name, email address, phone number, and billing address (country, street,
  city, state, postal code); the donation amount, currency, date, and an order
  reference; the campaign title as the item being paid for; and your site's
  domain, language, and the two callback URLs Wayforpay uses to report the
  result.
* **When a donation is refunded** from the GiveWP donation screen. Sent: the
  stored transaction reference, amount, and currency.
* **When a recurring donation is cancelled.** Sent: the stored transaction
  reference.

Wayforpay also sends payment results back to your site server-to-server. Those
requests are signature-verified before any donation is updated.

Your Wayforpay merchant credentials are stored in your site's settings and are
used to sign these requests.

Use of this service is subject to Wayforpay's [terms of service](https://wayforpay.com/en/terms) 
and [privacy policy](https://help.wayforpay.com/view/755229227).

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

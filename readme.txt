=== UCA Payment Gateway with WayForPay for GiveWP ===
Contributors: radion
Donate link: https://en.uba.com.ua/support-us/
Tags: givewp, wayforpay, payment gateway, donations, ukraine
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: trunk
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Adds WayForPay as an off-site payment gateway for GiveWP donation forms.

== Description ==

Registers [WayForPay](https://wayforpay.com/) as a custom payment gateway for [GiveWP](https://wordpress.org/plugins/give/).
Donors pay on WayForPay's hosted payment page; the plugin does not handle card data on your site.

Payment status is set by a server-to-server webhook from WayForPay, whose signature is verified before any donation is updated.

The plugin is built and maintained by the Ukrainian Charity Alliance (UCA), which is not affiliated with,
endorsed by, or sponsored by WayForPay or GiveWP. "WayForPay" and "GiveWP" are the trademarks of their
respective owners and are used here only to describe what this plugin connects to.

### Supported

* One-time donations.
* Recurring donations (subscriptions), including renewals and cancellation.
* Refunds from the GiveWP donation screen.
* GiveWP Test Mode, with separate live and test credentials.

### Requirements

* WordPress 6.6 or later.
* PHP 8.3 or later.
* GiveWP.
* A WayForPay merchant account.

== External services ==

This plugin relies on WayForPay (https://wayforpay.com/), a third-party payment
provider, to process donations. Payment cannot work without it.

Donors are redirected to WayForPay's hosted payment page to pay, so no card
details are entered on or handled by your site.

The plugin sends data to WayForPay in these situations:

* **When a donor submits a donation.** The donation is registered with WayForPay
  and the donor is redirected to its payment page. Sent: the donor's first and
  last name, email address, phone number, and billing address (country, street,
  city, state, postal code); the donation amount, currency, date, and an order
  reference; the campaign title as the item being paid for; and your site's
  domain, language, and the two callback URLs WayForPay uses to report the
  result.
* **When a donation is refunded** from the GiveWP donation screen. Sent: the
  stored transaction reference, amount, and currency.
* **When a recurring donation is cancelled.** Sent: the stored transaction
  reference.

WayForPay also sends payment results back to your site server-to-server. Those
requests are signature-verified before any donation is updated.

Your WayForPay merchant credentials are stored in your site's settings and are
used to sign these requests.

Use of this service is subject to WayForPay's [terms of service](https://wayforpay.com/en/terms) 
and [privacy policy](https://help.wayforpay.com/view/755229227).

== Installation ==

1. Install and activate GiveWP.
2. Install and activate this plugin.
3. Go to **Donations > Settings > Payment Gateways** and enable WayForPay.
4. Enter your WayForPay Merchant Account and Secret Key.

== Frequently Asked Questions ==

= Do I need a WayForPay account? =

Yes. A merchant account with WayForPay is required to accept payments.

= Are card details entered on my site? =

No. Donors are redirected to WayForPay's hosted page to pay.

== Screenshots ==

1. Donation form: amount selection with an option to make the donation monthly.
2. Donor details and donation summary, with the WayForPay redirect notice before payment.

== Changelog ==

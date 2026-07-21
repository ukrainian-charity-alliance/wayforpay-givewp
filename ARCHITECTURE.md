# Architecture

How the Wayforpay gateway for GiveWP is put together. For the contribution
workflow see [CONTRIBUTING.md](CONTRIBUTING.md); for AI-agent guidance see
[AGENTS.md](AGENTS.md).

## What this is

A WordPress plugin that adds **Wayforpay** (a Ukrainian bank payment provider) as a
custom payment gateway for **GiveWP**, the WordPress donation plugin. Built by the
Ukrainian Charity Alliance. It supports one-time donations, recurring
donations (subscriptions), and refunds, all via Wayforpay's off-site hosted
payment page.

- Requires WordPress 6.6+, PHP 8.3+, and the GiveWP plugin (`Requires Plugins: give`).
- Depends on the official `wayforpay/php-sdk` (Composer) for request building,
  signature generation, and response/signature verification.

## Layout

| Path | Role |
| --- | --- |
| [wayforpay-givewp.php](wayforpay-givewp.php) | Plugin bootstrap. Defines constants, loads the autoloader, hooks into GiveWP to register settings (`give_init`) and the gateway (`givewp_register_payment_gateway`). |
| [includes/WayforpayGateway.php](includes/WayforpayGateway.php) | The gateway. Extends GiveWP's `PaymentGateway`; implements `WebhookNotificationsListener` and `PaymentGatewayRefundable`. Also holds the small `RemoveSubscriptionRequest` SDK request class at the bottom. |
| [includes/WayforpaySettings.php](includes/WayforpaySettings.php) | Admin settings under *Give → Settings → Payment Gateways → Wayforpay*. Registers fields and exposes credential accessors that switch between live/test based on GiveWP test mode. |
| [js/fe.js](js/fe.js) | Frontend field component for GiveWP v3 Visual Form Builder. Registers via `window.givewp.gateways.register` and renders an info message + logo (no card fields — payment happens off-site). |
| [assets/wayforpay-logo.svg](assets/) | Gateway logo shown on the donation form. |
| [tests/](tests/) | PHPUnit + WordPress test suite (see Testing). |

## Payment flow

The gateway uses Wayforpay's **off-site redirect** model. The donor never enters
card details on the WordPress site.

1. **Create payment** — `createPayment()` (or `createSubscription()` for recurring)
   calls `redirectToWayforpay()`.
   - Builds a Wayforpay `PurchaseWizard` from the donation: order reference
     (`{donationId}-{timestamp}`), amount, currency, campaign title as the single
     product, donor client metadata, and `returnUrl` + `serviceUrl`.
   - **Server-side redirect indirection**: instead of letting the SDK render an
     auto-submitting form, the plugin POSTs the signed form data to Wayforpay via
     `wp_remote_post` with `redirection => 0`, then extracts the `Location` header
     and returns it as a `RedirectOffsite`. This keeps the redirect on the server
     and avoids a self-submitting HTML form in the browser.
   - Extensive `DonationNote::create()` logging at each step (and on every failure
     path) — donation notes are the primary audit/debug trail.

2. **Return URL** (`handleReturnUrl`) — where Wayforpay sends the donor's browser
   back. **UX only.** It decides which page to show (success / failure /
   "payment cancelled") but does **not** update donation status. If a
   `merchantSignature` is present it is verified via `ServiceUrlHandler`; otherwise
   a plain `ServiceResponse` is parsed.

3. **Service URL webhook** (`webhookNotificationsListener`) — the **authoritative**
   source of truth for payment status. Wayforpay POSTs here server-to-server and
   retries until it receives a valid signed acknowledgment.
   - Verifies the request signature via `ServiceUrlHandler::parseRequestFromPostRaw()`
     (403 on failure).
   - Loads the donation via the `donation-id` query param; updates status to
     `COMPLETE` / `FAILED` based on the transaction status; stores
     `gatewayTransactionId = orderReference`.
   - Always replies with `$handler->getSuccessResponse($transaction)` (the signed
     ack) so Wayforpay stops retrying.

### Why returnUrl and serviceUrl are non-secure routes

`secureRouteMethods` is intentionally **empty** (see the comment in the gateway).
Wayforpay limits `returnUrl`/`serviceUrl` to 256 chars, and GiveWP's secure-route
signature params push the URLs past that limit. So these are registered as plain
`routeMethods`. This is safe because status changes only happen in the webhook,
which independently verifies Wayforpay's own signature.

## Subscriptions (recurring)

- `supportsSubscriptions()` returns true. `createSubscription()` maps GiveWP periods
  (day/week/month/quarter/year) to Wayforpay `Regular::MODE_*`, computes the next
  charge date and installment count, and redirects with an extra
  `subscription-id` service-URL param.
- **Renewals** arrive on the same webhook. When `subscription-id` is present and the
  original donation is already complete, `handleRenewal()` runs — it creates a GiveWP
  renewal donation, guarded by idempotency (skips if a donation with that
  `orderReference` already exists).
- **Cancellation** (`cancelSubscription()`) sends a custom `REMOVE` request
  (`RemoveSubscriptionRequest`) using **password** credentials. Treats
  "order not found" on Wayforpay's side as success and still marks the local
  subscription cancelled.

## Refunds

`refundDonation()` uses the SDK `RefundWizard` (signature/secret credentials) with
the stored `gatewayTransactionId`. Returns `PaymentRefunded` on approval; throws
`PaymentGatewayException` otherwise.

## Credentials & settings

Two credential types, both switching to test values when GiveWP test mode is on
(`give_is_test_mode()`):

- `AccountSecretCredential` (merchant account + secret key) — signs/verifies most
  requests. Via `WayforpaySettings::getCredentials()`.
- `AccountPasswordCredential` (merchant account + password) — only used for
  subscription removal. Via `WayforpaySettings::getPasswordCredentials()`.

Both accessors throw `PaymentGatewayException('Wayforpay is not configured')` when
values are missing. Live and test have separate option sets
(`wayforpay_*` vs `wayforpay_test_*`).

## Conventions

- **Donation notes are the log.** Every notable step and every failure branch writes
  a `DonationNote` / `SubscriptionNote`. Preserve this when adding logic.
- **All user-facing strings** use `__()` / `esc_html__()` with the
  `wayforpay-givewp` text domain.
- **`#[\Override]`** is used on all GiveWP interface/parent overrides (PHP 8.3).
- Failure paths generally throw `PaymentGatewayException` with a terse message;
  webhook failures use `wp_die()` with an HTTP status so Wayforpay retries.

## Testing

The suite runs PHPUnit against a real WordPress test install with GiveWP, backed by
a Dockerized MySQL database. See the [README](README.md) for how to run it. Tests
live in [tests/Unit/](tests/Unit/).

One architectural detail worth knowing: `webhookNotificationsListener` throws
`\WPDieException` (instead of calling `exit`) when that class exists, so the signed
acknowledgment path can be asserted in tests.

## Building a release

`composer zip` installs prod-only dependencies, produces `wayforpay-givewp.zip`
(excluding dev/test/tooling files), then restores dev dependencies. See the
[README](README.md) for the full release process.

# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Add entries to the `[Unreleased]` section as you work. On release, the tooling
moves them into a versioned section and syncs them into `readme.txt`
(the WordPress-facing changelog) automatically — do not edit `readme.txt`'s
changelog by hand.

## [Unreleased]

### Fixed

- The changelog now lists all released versions, not only the latest.
- Long changelog entries are no longer cut off mid-sentence.

## [1.1.0] - 2026-09-07

### Changed

- Renamed plugin to "UCA Payment Gateway with WayForPay for GiveWP", with a
  matching `uca-payment-gateway-with-wayforpay-for-givewp` slug.
- `readme.txt` now states that the plugin is not affiliated with WayForPay or
  GiveWP.

### Security

- Sanitize callback data before calls to WayForPay SDK.
- Check for donation-id existence in return URL logic.
- Don't record all fields in Donation Note.

### Fixed

- Webhook errors are only written to the error log when `WP_DEBUG` is enabled.

## [1.0.2] - 2026-08-15

### Fixed

- The release zip no longer ships development files from the Composer
  dependencies.

## [1.0.1] - 2026-08-15

### Added

- "External services" section in `readme.txt` documenting what donor data is
  sent to Wayforpay and when, per WordPress.org plugin guidelines.

## [1.0.0] - 2026-07-22

### Added

- Wayforpay off-site payment gateway for GiveWP (hosted payment page; no card data handled on-site).
- One-time donations.
- Recurring donations (subscriptions), including renewals and cancellation.
- Refunds from the GiveWP donation screen.
- GiveWP Test Mode with separate live and test credentials.

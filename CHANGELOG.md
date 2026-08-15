# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Add entries to the `[Unreleased]` section as you work. On release, the tooling
moves them into a versioned section and syncs them into `readme.txt`
(the WordPress-facing changelog) automatically — do not edit `readme.txt`'s
changelog by hand.

## [Unreleased]

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

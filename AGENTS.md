# AGENTS.md

Guidance for AI coding agents working in this repository. (Human contributors:
see [CONTRIBUTING.md](CONTRIBUTING.md).)

This is a WordPress plugin that adds **Wayforpay** as an off-site payment gateway
for **GiveWP**. Read [ARCHITECTURE.md](ARCHITECTURE.md) first — it covers the
payment flow, subscriptions, refunds, credentials, and the conventions to
preserve when editing.

When making changes:

- Follow the conventions documented in
  [ARCHITECTURE.md](ARCHITECTURE.md#conventions) — most importantly, keep the
  `DonationNote` / `SubscriptionNote` logging on every notable step and failure
  branch, use the `wayforpay-givewp` text domain for user-facing strings, and
  keep `#[\Override]` on GiveWP interface/parent overrides.
- Record user-facing changes under `## [Unreleased]` in
  [CHANGELOG.md](CHANGELOG.md). Do not hand-edit the changelog in `readme.txt`.
- Before finishing, run `composer lint` and `composer test`.

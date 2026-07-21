# Security Policy

This plugin is a **payment gateway**: it verifies cryptographic signatures on
Wayforpay callbacks and changes donation payment status based on them. We take
security reports seriously and appreciate responsible disclosure.

## Reporting a Vulnerability

**Please do not report security vulnerabilities through public GitHub issues,
pull requests, or discussions.**

Instead, report them privately through GitHub Security Advisories:

1. Go to the [Security tab](https://github.com/ukrainian-charity-alliance/wayforpay-givewp/security)
   of this repository.
2. Click **Report a vulnerability**.
3. Fill in the advisory form with as much detail as you can.

If you are unable to use GitHub advisories, you may open a regular issue that
contains **no technical details** and simply asks a maintainer to make contact.

### What to include

- A description of the vulnerability and its impact.
- The plugin version, and the WordPress, PHP, and GiveWP versions in use.
- Steps to reproduce, ideally with a minimal proof of concept.
- Any relevant configuration (for example, live vs. test mode, subscriptions).

### What to expect

- We aim to acknowledge your report within **5 business days**.
- We will keep you informed as we investigate and work on a fix.
- Once a fix is released, we are happy to credit you in the advisory and
  changelog unless you prefer to remain anonymous.

Please give us a reasonable opportunity to release a fix before any public
disclosure.

## Scope

Because payment happens on Wayforpay's off-site hosted page, this plugin never
handles or stores card data. Security reports are most relevant when they
concern, for example:

- Bypassing or forging the Wayforpay callback signature verification.
- Causing a donation, subscription, or refund status change without a valid
  signed request.
- Leaking merchant credentials (account, secret key, or password) or other
  sensitive data through logs, notes, or responses.
- Any other issue that could lead to financial loss, unauthorized access, or
  disclosure of sensitive data.

Issues in WordPress core, GiveWP, the `wayforpay/php-sdk` dependency, or the
Wayforpay service itself should be reported to their respective maintainers.

## Supported Versions

Security fixes are provided for the **latest released version** of the plugin.
Please make sure you are running the newest release before reporting an issue.

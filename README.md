# Wayforpay Gateway for GiveWP

[![Requires PHP](https://img.shields.io/badge/PHP-8.3+-blue.svg)](https://php.net)
[![Requires WordPress](https://img.shields.io/badge/WordPress-6.6+-blue.svg)](https://wordpress.org)
[![Requires GiveWP](https://img.shields.io/badge/GiveWP-Required-green.svg)](https://wordpress.org/plugins/give/)
[![License](https://img.shields.io/badge/License-GPLv3-blue.svg)](LICENSE)

Wayforpay Payment Gateway integration for the [GiveWP](https://wordpress.org/plugins/give/) WordPress Plugin.

## Installation

Currently, this plugin must be installed manually.

1. Clone this repo.
2. Run `composer zip` to generate a release zip-file.
3. Upload the plugin to your WordPress installation via the WordPress admin panel.

## Testing

The plugin uses PHPUnit with the WordPress test suite. A Docker container provides the MySQL test database.

### Prerequisites

- Docker installed and running
- Composer dependencies installed (`composer install`)

### Running Tests

Use the Composer scripts defined in `composer.json`:

```bash
# Run everything in one command:
# brings the test database up, runs PHPUnit, then tears it back down
composer test
```

If you want to manage the test database manually:

```bash
# Start the test database
composer docker:up

# Run PHPUnit against the running database
./vendor/bin/phpunit --colors

# Stop the test database
composer docker:down
```

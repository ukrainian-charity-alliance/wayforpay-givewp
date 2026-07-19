# Wayforpay Gateway for GiveWP

Wayforpay Payment Gateway integration for the [GiveWP](https://wordpress.org/plugins/give/) WordPress Plugin.

## Installation

1. Download the plugin from the [WordPress.org Plugin Directory](https://wordpress.org/plugins/wayforpay-givewp/).
2. Upload the plugin to your WordPress installation via the WordPress admin panel.
3. Activate the plugin from the WordPress admin panel.
4. Configure the plugin in the WordPress admin panel under `Give → Settings → Payment Gateways → Wayforpay`.

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

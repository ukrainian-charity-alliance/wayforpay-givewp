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

```bash
# Start the test database
composer test:up

# Run tests
composer test

# Stop the test database
composer test:down
```

```bash
# Or run everything in one command
composer test:ci
```

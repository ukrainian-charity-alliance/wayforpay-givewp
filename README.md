# Wayforpay Gateway for GiveWP

[![Requires PHP](https://img.shields.io/badge/PHP-8.3+-blue.svg)](https://php.net)
[![Requires WordPress](https://img.shields.io/badge/WordPress-6.6+-blue.svg)](https://wordpress.org)
[![Requires GiveWP](https://img.shields.io/badge/GiveWP-Required-green.svg)](https://wordpress.org/plugins/give/)
[![License](https://img.shields.io/badge/License-GPLv3-blue.svg)](LICENSE)

[Wayforpay](https://wayforpay.com/) Payment Gateway integration for the [GiveWP](https://wordpress.org/plugins/give/) WordPress Plugin.

## Installation

The recommended way to install this plugin is by downloading the pre-built release from GitHub.

**From a Release (Recommended):**
1. Download the latest `wayforpay-givewp.zip` file from the [Releases page](https://github.com/ukrainian-charity-alliance/wayforpay-givewp/releases) on GitHub.
2. In your WordPress admin panel, go to **Plugins → Add New Plugin**.
3. Click **Upload Plugin**, select the downloaded `.zip` file, and click **Install Now**.
4. Activate the plugin.

**From Source:**
If you prefer to build the plugin yourself:
1. Clone this repository.
2. Run `composer install` to install dependencies.
3. Run `composer zip` to generate the production-ready zip file.
4. Upload the generated zip file to your WordPress installation.

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

## Releasing

This repository uses GitHub Actions to automatically build and attach the production `.zip` file whenever a new release tag is pushed.

### Changelog

Record user-facing changes under the `[Unreleased]` heading in [CHANGELOG.md](CHANGELOG.md) as you work, using the [Keep a Changelog](https://keepachangelog.com/) format. **Do not edit the changelog in `readme.txt` by hand** — the release tooling converts the changelog entries into WordPress readme.txt format and stamps the `Stable tag` automatically.

Helper script to tag new releases and trigger the GitHub Actions release workflow:

```bash
# Calculate the next version, tag it, and push to GitHub
composer release patch   # e.g., 1.0.0 -> 1.0.1
composer release minor   # e.g., 1.0.0 -> 1.1.0
composer release major   # e.g., 1.0.0 -> 2.0.0
```

The script moves the `[Unreleased]` entries into a dated `[VERSION]` section in `CHANGELOG.md`, commits that, then tags and pushes. Once pushed, the GitHub Action intercepts the tag, runs the test suite, stamps the new version into the plugin files, syncs the changelog into `readme.txt`, and attaches `wayforpay-givewp.zip` to a new GitHub Release.

Alternatively, you can manually create a release and tag from the GitHub UI (**Releases** → **Draft a new release**). In that case the changelog sync reads whatever is under `[Unreleased]` in `CHANGELOG.md`, so make sure it is up to date before tagging.

## Support Ukrainian Charity Alliance

This plugin is built and maintained by [Ukrainian Charity Alliance](https://en.uba.com.ua/), a nonprofit dedicated to helping the people of Kharkiv. If you find it useful, please consider [supporting our work](https://en.uba.com.ua/support-us/).

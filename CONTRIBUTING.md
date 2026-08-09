# Contributing

Thanks for your interest in improving the Wayforpay gateway for GiveWP! This
plugin is built and maintained by the
[Ukrainian Charity Alliance](https://en.uba.com.ua/). Contributions of all
kinds — bug reports, fixes, features, translations, and docs — are welcome.

## Reporting bugs and requesting features

- Search the [existing issues](https://github.com/ukrainian-charity-alliance/wayforpay-givewp/issues)
  first to avoid duplicates.
- Open a new issue using the appropriate template and include as much detail as
  you can: plugin version, and WordPress / PHP / GiveWP versions.
- **Security vulnerabilities must not be filed as public issues.** Follow
  [SECURITY.md](SECURITY.md) instead.

## Development setup and tests

See the [README](README.md) for how to build from source and run the test suite
(`composer test`, and the Docker-backed database it uses). You'll need PHP 8.3+,
Composer, and Docker.

When you change behavior, please add or update tests. The existing tests live in
[tests/Unit/](tests/Unit/) and are a good model to follow.

## Coding standards

This project follows the WordPress Coding Standards, enforced by PHP_CodeSniffer.

```bash
composer lint       # report violations
composer lint:fix   # auto-fix what can be fixed
```

CI runs the linter and the full test suite on every pull request; both must pass
before a change can be merged.

The plugin is also validated with
[Plugin Check](https://wordpress.org/plugins/plugin-check/), the WordPress.org
review tooling, which covers the plugin directory guidelines rather than the
coding standards — `readme.txt`, the plugin headers, and the code as shipped.
Please run it when you touch any of those:

```bash
composer plugin-check   # requires Docker; see the README
```

A few repository conventions to preserve:

- **Donation notes are the log.** Every notable step and every failure branch
  writes a `DonationNote` / `SubscriptionNote`. Keep this when adding logic.
- **All user-facing strings** use `__()` / `esc_html__()` with the
  `wayforpay-givewp` text domain.
- **`#[\Override]`** is used on all GiveWP interface/parent overrides.

See [ARCHITECTURE.md](ARCHITECTURE.md) for a deeper tour of the architecture
(payment flow, subscriptions, refunds, credentials).

## Changelog

Record user-facing changes under the `## [Unreleased]` heading in
[CHANGELOG.md](CHANGELOG.md), using the
[Keep a Changelog](https://keepachangelog.com/) format. **Do not edit the
changelog in `readme.txt` by hand** — it is generated from `CHANGELOG.md` at
release time.

## Branching model

This project uses **trunk-based development**. `main` is the only long-lived
branch in the official repository, and it is always kept in a releasable state.
Please do **not** push feature, topic, or personal branches to the official
repository.

- **External contributors:** work in your own fork. Create a short-lived topic
  branch there, and open a pull request from it against `main`.
- **Maintainers:** keep changes small and merge them into `main` frequently.
  Any working branch should be short-lived and deleted immediately after merge.
  Long-lived feature branches are not used.

Releases are cut from `main` by tagging (see [README.md](README.md)); there are
no release or maintenance branches.

## Pull request process

1. From your fork, create a short-lived topic branch off `main`.
2. Make your change, with tests and a `CHANGELOG.md` entry where appropriate.
3. Run `composer lint` and `composer test` locally.
4. Open a pull request against `main`, filling in the PR template. Keep it small
   and focused so it can be reviewed and merged quickly.
5. A maintainer will review; please respond to feedback and keep the branch up
   to date. The branch is deleted once the PR is merged.

## License

By contributing, you agree that your contributions will be licensed under the
[GNU General Public License v3.0 or later](LICENSE), the same license as the
project.

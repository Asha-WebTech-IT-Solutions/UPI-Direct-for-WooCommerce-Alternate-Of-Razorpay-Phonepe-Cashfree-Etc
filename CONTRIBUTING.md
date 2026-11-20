# Contributing

Thanks for helping! This project aims to stay small, secure and dependency-free.

## Ground rules

- **Security first.** Never add a code path that completes an order from client input. See [SECURITY.md](SECURITY.md).
- **No tracking, no remote requests, no paid upsells** in the core plugin.
- Keep PHP 7.4 compatibility (no `match`, union types, `str_contains`, nullsafe operator).
- Everything user-facing is translatable (`upi-direct-for-woocommerce`); everything printed is escaped late.

## Setup

```bash
git clone https://github.com/your-org/upi-direct-for-woocommerce.git
cd upi-direct-for-woocommerce
composer install
```

Symlink the folder into a local WordPress `wp-content/plugins/` that has WooCommerce active.

## Before opening a PR

```bash
composer lint
composer phpcs        # use composer phpcbf to auto-fix
composer test
```

Integration tests (WordPress + WooCommerce) run in CI; to run locally follow the *integration* job in `.github/workflows/ci.yml`.

## Pull requests

- One topic per PR; explain the *why*.
- Add or update tests. Anything touching verification or state transitions **needs** a test.
- Update `CHANGELOG.md` under *Unreleased* and docs when behaviour changes.
- Conventional commit messages are appreciated (`fix:`, `feat:`, `docs:`).

## Adding a verification provider

Prefer shipping it as a separate add-on plugin using `UPIDW_Verifier_Interface`. See [docs/DEVELOPER.md](docs/DEVELOPER.md). Providers must authenticate webhooks and report the credited **amount**.

## Reporting bugs

Include WordPress, WooCommerce and PHP versions, checkout type (classic/Blocks), and the WooCommerce log (`upi-direct-for-woocommerce`, with debug logging enabled). Redact UPI IDs and customer data.

## Code of conduct

Be kind and constructive. Harassment is not tolerated.

# VCR — Fiscal Receipts for Armenia (eHDM) — WooCommerce plugin

[![Latest Version](https://img.shields.io/github/v/tag/blob-am/vcr-am-woocommerce?label=version&sort=semver)](https://github.com/blob-am/vcr-am-woocommerce/releases)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](LICENSE)

Official WooCommerce plugin for the [VCR.AM](https://vcr.am) Virtual Cash Register — issue Armenian fiscal receipts (eHDM) directly to the State Revenue Committee from WooCommerce orders.

> **Status:** scaffold only. Real fiscal flow lands in subsequent phases — see [Roadmap](#roadmap). Not yet listed on WordPress.org.

## Why this plugin

Armenian merchants selling online must issue an electronic fiscal receipt (e-HDM) for every sale, refund, and prepayment, per Tax Code Article 380.1 (HO-280-N) and Government Decision 1976-N. This plugin wires that obligation into the standard WooCommerce checkout / refund / order-management flow.

What sets it apart from existing options:

- **Direct SRC integration** — talks to the official VCR.AM gateway, not to a third-party reseller. No per-receipt rake from intermediaries.
- **Asynchronous fiscalization** — uses WooCommerce's Action Scheduler. Customer checkout is never blocked by SRC slowness; failed transmissions retry automatically with exponential backoff.
- **Multi-currency first-class** — orders in USD/EUR/RUB convert to AMD using the Central Bank of Armenia rate at the moment of fiscalization (with cached rates and stale-rate guards).
- **Refund-aware** — `woocommerce_order_refunded` triggers a partial-reversal receipt automatically.
- **Customer-facing receipt** — QR code and public verification URL on the thank-you page and in transactional emails.
- **HPOS + Cart/Checkout Blocks compatible** out of the box.
- **Encrypted credentials at rest** (libsodium).

## Requirements

| | Minimum | Tested up to |
| --- | --- | --- |
| WordPress | 6.7 | 6.9 |
| WooCommerce | 9.4 | 10.7 |
| PHP | 8.2 | 8.4 |

## Installation (development)

```bash
git clone git@github.com:blob-am/vcr-am-woocommerce.git
cd vcr-am-woocommerce
composer install
```

> **Phase 2 will add [Strauss](https://github.com/BrianHenryIE/strauss)** to scope production dependencies into the `BlobSolutions\WooCommerceVcrAm\Vendor\` namespace under `vendor-prefixed/`. Required for WP.org distribution to prevent conflicts with other plugins that bundle the same libraries at different versions. Currently held out of `composer.json` because Strauss's transitive `voku/simple-cache` (psr/simple-cache 1|2) clashes with Pest 4's mutate plugin (psr/simple-cache 3); the standard fix is to install Strauss in an isolated `composer-bin-plugin` context, which we'll add when production dependencies land.

## Repository layout

```
vcr-am-woocommerce/
├── vcr-am-fiscal-receipts.php   ← plugin entry (matches the WP.org slug)
├── composer.json                 ← deps + Strauss config + scripts
├── phpstan.neon.dist             ← static analysis (level max + strict rules)
├── pint.json                     ← code style (Pint, PSR-12 + extras)
├── phpunit.xml.dist              ← test harness
├── readme.txt                    ← WordPress.org plugin directory readme
├── LICENSE                       ← GPL-2.0-or-later (WP.org requirement)
├── src/
│   ├── Plugin.php                ← bootstrap (HPOS / Blocks declarations, WC active guard)
│   └── Settings/
│       └── SettingsPage.php      ← admin UI (placeholder; filled in Phase 2)
└── tests/
    ├── Pest.php
    ├── TestCase.php              ← Brain Monkey set up / tear down
    └── Unit/
        ├── PluginTest.php
        └── PluginEntryFileTest.php
```

## Local development

```bash
composer check          # full local CI: format + phpstan + tests
composer test           # Pest test suite
composer phpstan        # static analysis
composer format         # apply Pint code style fixes
composer format:check   # verify code style without writing
composer strauss        # re-run Strauss (auto-runs after install/update)
```

## Architecture principles

This plugin follows the same conventions as the rest of the VCR.AM ecosystem:

- **Domain over wire.** SRC field names (`adgCode`, `goodCode`, `uniqueCode`) live exclusively inside `blob-solutions/vcr-am-sdk` and below. Plugin-side surfaces — admin UI, customer UI, WC product meta — speak in domain terms (`department`, `unit`, `eMark codes`).
- **Async over sync.** Fiscal API calls never run on the request thread. Action Scheduler queues jobs; SRC outages never block customer checkout.
- **Idempotent over best-effort.** Every fiscal job carries a deterministic `external_id` derived from the WC order ID, so retries and double-fires produce one receipt.
- **Observable over silent.** Every order surfaces fiscal state (`pending` / `queued` / `success` / `failed` / `manual_required`), every transition leaves a WC order note, every failed retry surfaces an admin notice.

## Roadmap

| Phase | Scope | Status |
| --- | --- | --- |
| 1 | Repo scaffold, tooling, plugin shell, HPOS / Blocks declarations | ✅ done |
| 2 | SDK + Guzzle as production deps, Strauss vendor scoping, core fiscal flow (order-status hooks, Action Scheduler queue, idempotency), settings page | planned |
| 3 | FX handling — CBA rate fetcher with cache + stale-rate guards | planned |
| 4 | Refund automation, customer-facing receipt UX (QR + URL + emails) | planned |
| 5 | E2E test suite via wp-env + Playwright; WordPress.org submission | planned |

## Related packages

- **[blob-solutions/vcr-am-sdk](https://packagist.org/packages/blob-solutions/vcr-am-sdk)** — framework-agnostic PHP SDK (this plugin's HTTP backbone).
- **[blob-solutions/laravel-vcr-am](https://packagist.org/packages/blob-solutions/laravel-vcr-am)** — Laravel adapter built on the same SDK.
- **[@blob-solutions/vcr-am-sdk](https://www.npmjs.com/package/@blob-solutions/vcr-am-sdk)** — TypeScript SDK (Node.js).

## License

GPL-2.0-or-later — see [LICENSE](LICENSE). Required by WordPress.org plugin directory and consistent with WooCommerce itself.

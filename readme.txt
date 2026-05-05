=== VCR — Fiscal Receipts for Armenia (eHDM) ===
Contributors: blobsolutions
Tags: woocommerce, armenia, fiscal, receipts, ehdm
Requires at least: 6.7
Tested up to: 6.8
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Issue Armenian fiscal receipts (eHDM) to the State Revenue Committee from WooCommerce orders. Multi-currency, refunds, QR receipts.

== Description ==

The official WooCommerce plugin for the [VCR.AM](https://vcr.am) Virtual Cash Register. Issues Armenian fiscal receipts (e-HDM) to the State Revenue Committee directly from WooCommerce orders, refunds, and prepayments — fulfilling the obligation in Tax Code Article 380.1 (HO-280-N) and Government Decision 1976-N.

= Why this plugin =

* **Direct SRC integration.** Talks to the official VCR.AM gateway, not a third-party reseller.
* **Asynchronous fiscalization.** Uses WooCommerce Action Scheduler — customer checkout is never blocked by SRC slowness; failed transmissions retry automatically.
* **Multi-currency first-class.** Orders in USD/EUR/RUB convert to AMD using the Central Bank of Armenia rate at fiscalization time.
* **Refund-aware.** Issues partial-reversal receipts automatically when WooCommerce refunds happen.
* **Customer-facing receipt.** QR code and verification URL on the thank-you page and in transactional emails.
* **HPOS + Cart/Checkout Blocks compatible.**
* **Encrypted credentials at rest** (libsodium).

= Requirements =

* WordPress 6.7 or newer
* WooCommerce 9.4 or newer
* PHP 8.3 or newer
* A VCR.AM account and API key — sign up at [vcr.am](https://vcr.am)

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install via the WordPress plugin directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **WooCommerce → Settings → VCR** and paste your VCR.AM API key.
4. Configure per-payment-method fiscalization timing (online gateways default to "on payment confirmed"; cash-on-delivery defaults to "on order completed").

== Frequently Asked Questions ==

= Do I need a VCR.AM account? =

Yes. The plugin issues receipts through the VCR.AM gateway, which talks to the State Revenue Committee on your behalf. Sign up at [vcr.am](https://vcr.am).

= Does it support multi-currency stores? =

Yes. Orders in non-AMD currencies are converted to AMD using the Central Bank of Armenia daily rate. The rate is cached and refreshed daily; if the cache is stale (more than 48 hours), the order is flagged for manual attention rather than fiscalized at an outdated rate.

= What happens if SRC is down? =

Nothing customer-facing. The fiscal job is queued via Action Scheduler and retries with exponential backoff. The order is marked as `pending` until the receipt issues. Persistent failures surface in the admin as a notice.

= Is High-Performance Order Storage (HPOS) supported? =

Yes — declared compatible. Works with both the legacy `wp_posts` storage and the new `wc_orders` tables.

= Does it support the new block-based Checkout? =

Yes — declared compatible.

== External services ==

This plugin connects to two external services. Both connections are made server-to-server from your WordPress installation; no third-party JavaScript is loaded into your customers' browsers.

= 1. VCR.AM gateway (vcr.am) =

When an order is paid (or, for cash-on-delivery, marked completed) the plugin transmits the order's fiscal data to the VCR.AM gateway, which forwards a fiscal receipt to the Armenian State Revenue Committee (SRC). When a refund is issued the plugin transmits a corresponding refund record.

**What is sent:** line items (product name, SKU, quantity, unit price, tax), order total, payment method (cash / non-cash split), currency, the configured cashier and department identifiers, and — for refunds — the refund amount and refund-reason text. **No customer name, email, phone number, billing address, or IP address is transmitted.**

**Why it is sent:** to fulfil the merchant's obligation under Armenian Tax Code Article 380.1 (HO-280-N) and Government Decision 1976-N to issue a fiscal receipt for every taxable sale through a registered electronic Cash Register (e-HDM).

**Where data is sent:** `https://vcr.am/api/v1/` (default; configurable in plugin settings).

**Service Terms of Use:** https://vcr.am/terms
**Service Privacy Policy:** https://vcr.am/privacy

= 2. Central Bank of Armenia (cba.am) — exchange rates =

For multi-currency stores (orders in USD, EUR, RUB, etc.), the plugin fetches the official Central Bank of Armenia daily exchange rate so it can convert the order total to AMD before transmitting the receipt.

**What is sent:** an HTTP request for the published daily rates. No order data, no customer data, and no merchant identifiers are sent. The request is identical to a public website hit.

**Where data is sent:** `https://api.cba.am/exchangerates.asmx` (SOAP) and/or `https://www.cba.am/_layouts/rssreader.aspx` (RSS), depending on the response of the primary endpoint. Rates are cached locally for 24 hours; the plugin refuses to fiscalize an order if the cached rate is older than 48 hours, so a CBA outage cannot result in incorrect fiscal data.

**Service Terms of Use:** https://www.cba.am/en/SitePages/copyright.aspx
**Service Privacy Policy:** the CBA endpoints serve a public dataset; CBA's general site policy applies.

= GDPR / data-transfer notes =

* The plugin's data flow constitutes an **EU → Armenia transfer of personal data** *only* under specific configurations. By design the plugin does not transmit customer identifiers to vcr.am — order data is pseudonymous from the SRC side. Where transfer rules apply, they apply between the merchant (controller) and VCR.AM (processor) under Standard Contractual Clauses; merchants should obtain a signed Data Processing Addendum from VCR.AM before activating the plugin in production.
* Fiscal records issued to the SRC are subject to the statutory retention period in Armenian Tax Code Article 56 (typically 5 years). The plugin's GDPR Personal Data Eraser will retain these records on legal-obligation grounds (GDPR Article 17(3)(b)) and emit an explanatory message to the data-protection officer reviewing the request.

== Changelog ==

= 0.1.0 =
* Initial scaffold release. No customer-facing functionality yet — see the project roadmap on GitHub.

<p align="center">
  <img src="assets/images/logo.png" width="140" alt="UPI Direct for WooCommerce logo">
</p>

# UPI Direct for WooCommerce

Accept UPI payments in WooCommerce **directly into your own UPI ID**: no payment-gateway account, no per-transaction fee from this plugin, no tracking, no external services.

Customers pay with Google Pay, PhonePe, Paytm, BHIM or any UPI app via **UPI Intent** (mobile) or a **QR code** (desktop). You confirm each payment against your bank statement, or plug in an automatic bank/PSP verifier.

> **The one rule this plugin is built around:** opening a UPI app, or a customer saying "I paid", is **not** proof of payment. UPI Intent has no trustworthy callback. An order is completed **only** by a verifier you trust: a person with the right capability, or a provider integration that reports the exact amount.

## Features

| Area | What you get |
|---|---|
| Checkout | Classic checkout and **Cart & Checkout Blocks**, **HPOS** compatible. |
| UPI Intent | `upi://pay` deep link plus optional Google Pay / PhonePe / Paytm / BHIM launch buttons, dynamic per order (amount, order number, transaction reference). |
| QR code | Generated **in the browser** from a bundled MIT library. No external QR API, nothing leaves your site. |
| Payment screen | Mobile-first panel: amount, app buttons or QR, copy-UPI-ID, reference (UTR) form, live status that updates itself. Works without JavaScript (form fallback). |
| Verification | Manual verification built in; pluggable verifier API for automatic providers (see [docs/DEVELOPER.md](docs/DEVELOPER.md)). |
| Admin | **WooCommerce → UPI Transactions** list with filters and search, order metabox with confirm / reject, per-order payment history, order notes. |
| Security | Nonces, capability checks, order-key authorisation, escaping, prepared SQL, atomic state transitions, duplicate-UTR protection, attempt limits. |
| Developers | Hooks and filters, REST endpoints, verifier interface, overridable template. |
| Privacy | No telemetry, no remote requests, no cookies. |

## Requirements

- WordPress 6.2+, WooCommerce 8.0+, PHP 7.4+
- Store currency **INR** (UPI is rupee-only; the gateway hides itself otherwise)
- A UPI ID (VPA) that receives money into your business bank account

## Installation

**From a release zip:** *Plugins → Add New → Upload Plugin*, upload `upi-direct-for-woocommerce-x.y.z.zip`, activate.

**From source:**

```bash
cd wp-content/plugins
git clone https://github.com/your-org/upi-direct-for-woocommerce.git
```

Activate it, then go to **WooCommerce → Settings → Payments → UPI Direct**.

## Configuration

1. **UPI ID (VPA)**: e.g. `yourshop@okaxis`. Validated on save; the gateway refuses to enable without one.
2. **Merchant name**: shown to customers as the payee in their app. Match your bank account name.
3. **Store name / payment note**: the note shown in the UPI app. Placeholders: `{order_number}`, `{store_name}`.
4. **Merchant category code** *(optional)*: 4-digit MCC if your bank issued one.
5. **UPI apps / QR**: choose which launch buttons to show; toggle the QR code.
6. **Verification method**: `Manual` by default.
7. **Reference (UTR) format, maximum amount, attempts per order**: sensible defaults (12-digit UTR, ₹1,00,000, 5).

## How a payment works

```
Customer places order ──► order is ON-HOLD, UPI request created (amount, tr reference)
        │
        ▼
Payment screen: app buttons / QR ──► customer pays in their UPI app      (INITIATION: proves nothing)
        │
        ▼
Customer enters UTR ──► stored as "submitted"                            (SUBMISSION: a claim, not proof)
        │
        ▼
You (or a provider) verify against the bank ──► order completes          (VERIFICATION: the only path to "paid")
```

### Verifying payments manually

When a customer submits a reference you get an email (optional) and the transaction shows **Awaiting verification**.

1. Find the credit in your bank statement. Match **amount** and **UTR**.
2. Open the order (or **WooCommerce → UPI Transactions**) and click **Confirm payment received**, or **Reject** with a reason.
3. Confirming completes the order exactly once. Rejecting moves it to *Failed*, frees the UTR and lets the customer retry.

### What the plugin guarantees

- It never calls `payment_complete()` from a browser request or from the customer's UTR.
- A provider "verified" verdict is accepted only if **amount**, **currency** and (when echoed) **transaction reference** match the order. Mismatches are logged and ignored.
- A UTR can be attached to only one active payment (database `UNIQUE` index), even under concurrent requests.
- Confirming twice cannot double-complete an order (atomic state transition).
- A misconfigured automatic verifier falls back to manual; it can never auto-approve.

### What it cannot do

- It cannot detect payments by itself. Without a bank/PSP verifier, **a human must confirm**. This is a property of UPI Intent, not a missing feature.
- Customers can type a UTR that belongs to someone else's payment. That is why manual confirmation means matching **amount + UTR in your statement**, and why duplicate UTRs are blocked.
- Deep links are **unsigned**. A tampered link would only change what the customer's own app displays; you still verify what lands in your bank.

## Compliance notes (read before going live)

- UPI is regulated by NPCI. Use a UPI ID that your bank allows for **business collections**. Receiving commercial payments on a personal VPA can violate your bank's/PSP's terms and may get the account restricted. Ask your bank for a merchant VPA.
- Check your bank's per-transaction and daily limits.
- App-specific schemes (`tez://`, `phonepe://`, `paytmmp://`) are not standardised by NPCI and can change. "Any UPI app" (`upi://pay`) is the portable option and is on by default.
- "UPI" and the UPI logo are trademarks of NPCI; "QR Code" is a trademark of DENSO WAVE; "WooCommerce" is a trademark of Automattic. This project is independent and unaffiliated. The plugin does not display the UPI logo at checkout.

## Documentation

- [Developer guide: hooks, REST API, writing a verifier](docs/DEVELOPER.md)
- [Changelog](CHANGELOG.md) · [Contributing](CONTRIBUTING.md) · [Security policy](SECURITY.md)

## Development

```bash
composer install
composer lint            # php -l on every file
composer phpcs           # WordPress Coding Standards
composer test            # unit tests (no WordPress needed)
composer test:integration # needs the WP test suite + WooCommerce, see CI for setup
bash bin/build-zip.sh    # installable zip in ./build
```

CI runs lint, PHPCS, unit tests on PHP 7.4–8.3, integration tests on a WP/WC matrix, and the WordPress Plugin Check.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE). Bundles [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) (MIT) in `assets/js/vendor/`.

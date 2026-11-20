=== UPI Direct for WooCommerce ===
Contributors: upidirectcontributors
Tags: upi, woocommerce, payment gateway, india, qr code
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept UPI payments straight into your own UPI ID with UPI Intent and QR codes. Secure verification, no middlemen, no tracking.

== Description ==

UPI Direct lets Indian merchants accept UPI payments in WooCommerce without a payment-gateway account. Customers pay from Google Pay, PhonePe, Paytm, BHIM or any UPI app using UPI Intent (mobile) or a QR code (desktop).

**Payments are never trusted from the browser.** Opening a UPI app or typing a reference does not complete an order. You confirm each payment against your bank statement, or add an automatic verifier.

= Features =

* Classic checkout, Cart & Checkout Blocks and HPOS support
* UPI Intent links and browser-generated QR codes, dynamic per order
* Mobile-first payment screen with live status
* Customer UTR submission with duplicate protection and attempt limits
* Manual verification with order notes, metabox and a Transactions screen
* Extensible verifier API, REST endpoints, hooks and filters
* Translation ready. No tracking, no external requests

= Important =

Without a bank or PSP verification add-on, a person must confirm payments. Use a UPI ID your bank allows for business collections. "UPI" is a trademark of NPCI; this plugin is not affiliated with NPCI or Automattic.

== Installation ==

1. Upload the plugin and activate it.
2. Go to WooCommerce → Settings → Payments → UPI Direct.
3. Enter your UPI ID and merchant name, then enable the gateway.

== Frequently Asked Questions ==

= Does it confirm payments automatically? =
Not by itself. UPI Intent provides no trusted callback. By default you confirm payments manually. Developers can register automatic verifiers.

= Does it work with INR only? =
Yes. UPI is rupee-only; the gateway hides itself for other currencies.

= Does it send data anywhere? =
No. QR codes are generated in the browser and the plugin makes no remote requests.

== Changelog ==

= 1.0.0 =
* Initial release.

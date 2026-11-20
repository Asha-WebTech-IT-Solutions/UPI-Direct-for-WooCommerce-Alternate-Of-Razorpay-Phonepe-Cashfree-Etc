# Developer guide

## Architecture

```
UPIDW_Gateway ──process_payment──► UPIDW_Payment_Service ──► UPIDW_Repository (custom tables)
UPIDW_Payment_Panel (UI) ─┐                │
UPIDW_REST_Controller ────┴──► service     └──► UPIDW_Verifier_Registry ──► UPIDW_Verifier_Interface
UPIDW_Admin (metabox, list table) ─────────────► service
```

| Concern | Class | Notes |
|---|---|---|
| Initiation | `UPIDW_Payment_Service::start_payment()`, `get_payment_params()`, `UPIDW_UPI` | Builds the UPI link. Proves nothing. |
| Submission | `submit_reference()` | Customer claim. Validated, rate-limited, duplicate-protected. |
| Verification | `confirm_manually()`, `apply_result()` | **Only** paths that call `payment_complete()`. |

Tables: `{prefix}upidw_transactions` (one row per payment request, `active_reference` is `UNIQUE` and `NULL` unless in use) and `{prefix}upidw_events` (history). Order meta (HPOS-safe): `_upidw_txn_ref`, `_upidw_reference`, `_upidw_status`, `_upidw_verified_by`, `_upidw_verified_at`, `_upidw_vpa`.

Transaction states: `initiated → submitted → verified | rejected`, or `expired` (closed). Transitions are atomic SQL updates guarded by the current status.

## Writing a verifier

```php
class Acme_Bank_Verifier implements UPIDW_Verifier_Interface {
	public function get_id() { return 'acme_bank'; }
	public function get_label() { return 'Acme Bank statement API'; }
	public function is_configured() { return (bool) get_option( 'acme_api_key' ); }
	public function is_automatic() { return true; }

	public function verify( $txn, WC_Order $order ) {
		$credit = acme_find_credit( $txn->customer_reference ); // call the bank
		if ( ! $credit ) {
			return UPIDW_Verification_Result::pending();
		}
		return UPIDW_Verification_Result::verified( $credit['amount'], $credit['rrn'], $txn->txn_ref );
	}

	public function handle_webhook( WP_REST_Request $request ) {
		// 1. AUTHENTICATE FIRST (HMAC over the raw body, constant-time compare).
		if ( ! acme_valid_signature( $request ) ) {
			return new WP_Error( 'acme_bad_signature', 'Invalid signature', array( 'status' => 401 ) );
		}
		$p = $request->get_json_params();
		return UPIDW_Verification_Result::verified( $p['amount'], $p['rrn'], $p['tr'] );
	}
}

add_action( 'upidw_register_verifiers', function ( UPIDW_Verifier_Registry $registry ) {
	$registry->register( new Acme_Bank_Verifier() );
} );
```

The verifier then appears under *Verification method*. Webhook URL: `POST /wp-json/upi-direct/v1/webhook/acme_bank`.

Rules enforced by the service: a verified verdict **must** include the amount; amount must equal both the stored transaction amount and the order total; currency must be INR; a returned `transaction_ref` must match. Otherwise the verdict is ignored, logged and noted on the order. Automatic verifiers are polled through Action Scheduler (`upidw_run_verification`).

## Actions

| Hook | Args |
|---|---|
| `upidw_register_verifiers` | `UPIDW_Verifier_Registry $registry` |
| `upidw_transaction_created` | `int $id, array $row` |
| `upidw_reference_submitted` | `WC_Order $order, object $txn` |
| `upidw_payment_verified` | `WC_Order $order, object $txn` |
| `upidw_payment_rejected` | `WC_Order $order, object $txn, string $reason` |

## Filters

| Filter | Purpose |
|---|---|
| `upidw_settings` | Adjust merged settings. |
| `upidw_is_available` | Override gateway availability. |
| `upidw_payment_params` | Modify UPI params (`pa pn am cu tn tr mc`). |
| `upidw_app_links` | Add/remove/relabel launch buttons. |
| `upidw_panel_config` | JS config of the payment panel. |
| `upidw_verify_capability` | Capability for confirming/rejecting (default `manage_woocommerce`). |
| `upidw_admin_notification_email` | Recipient of "reference submitted" emails. |
| `upidw_max_poll_attempts`, `upidw_poll_interval` | Automatic verification polling. |

## Template override

Copy `templates/payment-panel.php` to `yourtheme/upi-direct-for-woocommerce/payment-panel.php`. Styling: override the `--upidw-*` CSS variables on `.upidw-panel`.

## REST API (`/wp-json/upi-direct/v1`)

| Route | Auth | Purpose |
|---|---|---|
| `GET /orders/{id}/status?order_key=` | order key | Customer-facing state. |
| `POST /orders/{id}/reference` | order key + nonce | Body: `order_key`, `nonce`, `reference`. |
| `POST /admin/orders/{id}/verify` | `manage_woocommerce` + `X-WP-Nonce` | Body: `note`. |
| `POST /admin/orders/{id}/reject` | same | Body: `reason`. |
| `GET /admin/transactions` | same | Filters: `status`, `search`, `page`, `per_page`. Totals in `X-WP-Total`. |
| `POST /webhook/{provider}` | verifier-defined | Dispatched to the provider, which must authenticate it. |

Unknown orders and wrong keys return the same `403` so order IDs cannot be enumerated.

## Tests

- `tests/unit`: pure logic (`UPIDW_UPI`), no WordPress required. `composer test`.
- `tests/integration`: lifecycle, verification rules, REST authorisation against real WP + WooCommerce. `composer test:integration`.

## Security checklist for changes

Escape on output, sanitise on input, `$wpdb->prepare()` for every dynamic value, capability check on every admin route, never complete an order outside `finalize_verified()`.

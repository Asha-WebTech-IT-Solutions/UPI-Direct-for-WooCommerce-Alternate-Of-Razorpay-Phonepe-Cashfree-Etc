<?php
/**
 * Verifier contract.
 *
 * @package UPI_Direct_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * A payment verifier decides, from a TRUSTED source, whether money has arrived.
 *
 * Verification is intentionally separate from UPI Intent initiation: the intent only
 * opens the customer's app, it never proves payment. Implementations may query a bank
 * or PSP API, process a signed webhook, or defer to a human (manual).
 *
 * Never base a verdict on anything the customer's browser sends.
 */
interface UPIDW_Verifier_Interface {

	/**
	 * Unique lowercase slug, e.g. "manual" or "acme_bank".
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Human-readable name shown in settings.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Whether the verifier has the credentials/config it needs to run.
	 *
	 * @return bool
	 */
	public function is_configured();

	/**
	 * True when the verifier can reach a verdict without a human.
	 *
	 * @return bool
	 */
	public function is_automatic();

	/**
	 * Check a transaction. Must be idempotent and must not mutate the order.
	 *
	 * @param object   $txn   Row from the transactions table.
	 * @param WC_Order $order The order.
	 * @return UPIDW_Verification_Result
	 */
	public function verify( $txn, WC_Order $order );

	/**
	 * Handle an inbound provider webhook at /wp-json/upi-direct/v1/webhook/{id}.
	 *
	 * MUST authenticate the request (HMAC signature, mTLS, shared secret...) before
	 * trusting any field. Return a WP_Error to reject.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return UPIDW_Verification_Result|WP_Error
	 */
	public function handle_webhook( WP_REST_Request $request );
}

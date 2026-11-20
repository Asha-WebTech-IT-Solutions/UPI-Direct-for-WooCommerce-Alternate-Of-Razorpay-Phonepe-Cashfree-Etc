<?php
/**
 * Manual (human) verifier.
 *
 * @package UPI_Direct_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Default verifier: a shop manager confirms the credit in their bank statement
 * and then confirms the payment in WooCommerce. It never decides on its own.
 */
final class UPIDW_Manual_Verifier implements UPIDW_Verifier_Interface {

	/** @return string */
	public function get_id() {
		return 'manual';
	}

	/** @return string */
	public function get_label() {
		return __( 'Manual verification (check your bank statement)', 'upi-direct-for-woocommerce' );
	}

	/** @return bool */
	public function is_configured() {
		return true;
	}

	/** @return bool */
	public function is_automatic() {
		return false;
	}

	/**
	 * Manual verification is always "pending" until a person acts.
	 *
	 * @param object   $txn   Transaction.
	 * @param WC_Order $order Order.
	 * @return UPIDW_Verification_Result
	 */
	public function verify( $txn, WC_Order $order ) {
		return UPIDW_Verification_Result::pending( __( 'Waiting for manual confirmation.', 'upi-direct-for-woocommerce' ) );
	}

	/**
	 * Manual verification has no webhook.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error
	 */
	public function handle_webhook( WP_REST_Request $request ) {
		return new WP_Error( 'upidw_no_webhook', __( 'This verifier does not accept webhooks.', 'upi-direct-for-woocommerce' ), array( 'status' => 404 ) );
	}
}

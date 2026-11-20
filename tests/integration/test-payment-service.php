<?php
/**
 * Integration tests for the payment lifecycle.
 *
 * @package UPI_Direct_For_WooCommerce
 */

/**
 * A fake automatic verifier to test the provider path.
 */
final class UPIDW_Test_Fake_Verifier implements UPIDW_Verifier_Interface {
	/** @var UPIDW_Verification_Result */
	public static $next;
	public function get_id() {
		return 'fake';
	}
	public function get_label() {
		return 'Fake';
	}
	public function is_configured() {
		return true;
	}
	public function is_automatic() {
		return true;
	}
	public function verify( $txn, WC_Order $order ) {
		return self::$next;
	}
	public function handle_webhook( WP_REST_Request $request ) {
		return self::$next;
	}
}

/**
 * @group integration
 */
class UPIDW_Payment_Service_Test extends WP_UnitTestCase {

	/** @var UPIDW_Payment_Service */
	private $service;

	public function set_up() {
		parent::set_up();
		update_option(
			'woocommerce_upidw_settings',
			array(
				'enabled'          => 'yes',
				'vpa'              => 'shop@okaxis',
				'merchant_name'    => 'Test Shop',
				'reference_format' => 'utr12',
				'max_attempts'     => '3',
				'notify_admin'     => 'no',
			)
		);
		update_option( 'woocommerce_currency', 'INR' );
		$this->service = UPIDW_Plugin::instance()->service;
	}

	private function make_order( $total = '499.00' ) {
		$order = wc_create_order();
		$order->set_currency( 'INR' );
		$order->set_payment_method( 'upidw' );
		$order->set_total( $total );
		$order->set_status( 'on-hold' );
		$order->save();
		return $order;
	}

	private function make_admin() {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $id );
		return $id;
	}

	public function test_start_payment_is_idempotent_for_same_amount() {
		$order = $this->make_order();
		$a     = $this->service->start_payment( $order );
		$b     = $this->service->start_payment( $order );
		$this->assertSame( $a->id, $b->id );
		$this->assertSame( '499.00', $a->amount );
		$this->assertSame( UPIDW_Status::INITIATED, $a->status );
	}

	public function test_changed_total_closes_old_request_and_creates_new_one() {
		$order = $this->make_order();
		$a     = $this->service->start_payment( $order );
		$order->set_total( '599.00' );
		$order->save();
		$b = $this->service->start_payment( $order );
		$this->assertNotSame( $a->id, $b->id );
		$this->assertSame( UPIDW_Status::EXPIRED, UPIDW_Plugin::instance()->repository->get( (int) $a->id )->status );
	}

	public function test_non_inr_orders_are_refused() {
		$order = $this->make_order();
		$order->set_currency( 'USD' );
		$order->save();
		$this->assertWPError( $this->service->start_payment( $order ) );
	}

	public function test_submitting_a_reference_never_marks_order_paid() {
		$order = $this->make_order();
		$txn   = $this->service->submit_reference( $order, '4012 3456 7890' );
		$this->assertNotWPError( $txn );
		$this->assertSame( UPIDW_Status::SUBMITTED, $txn->status );
		$order = wc_get_order( $order->get_id() );
		$this->assertFalse( $order->is_paid() );
		$this->assertSame( 'on-hold', $order->get_status() );
	}

	public function test_invalid_reference_is_rejected() {
		$order = $this->make_order();
		$this->assertWPError( $this->service->submit_reference( $order, 'not-a-utr' ) );
	}

	public function test_same_reference_cannot_be_used_on_two_orders() {
		$one = $this->make_order();
		$two = $this->make_order();
		$this->assertNotWPError( $this->service->submit_reference( $one, '401234567890' ) );
		$result = $this->service->submit_reference( $two, '401234567890' );
		$this->assertWPError( $result );
		$this->assertSame( 'upidw_duplicate_reference', $result->get_error_code() );
	}

	public function test_attempts_are_capped() {
		$order = $this->make_order();
		for ( $i = 0; $i < 3; $i++ ) {
			$this->service->submit_reference( $order, 'bad' );
		}
		$result = $this->service->submit_reference( $order, '401234567890' );
		$this->assertWPError( $result );
		$this->assertSame( 'upidw_too_many_attempts', $result->get_error_code() );
	}

	public function test_manual_confirmation_requires_capability() {
		$order = $this->make_order();
		$this->service->submit_reference( $order, '401234567890' );
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$result   = $this->service->confirm_manually( $order, $customer );
		$this->assertWPError( $result );
		$this->assertSame( 'upidw_forbidden', $result->get_error_code() );
		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
	}

	public function test_manual_confirmation_completes_order_once() {
		$order = $this->make_order();
		$this->service->submit_reference( $order, '401234567890' );
		$admin  = $this->make_admin();
		$result = $this->service->confirm_manually( $order, $admin );
		$this->assertNotWPError( $result );
		$this->assertSame( UPIDW_Status::VERIFIED, $result->status );
		$fresh = wc_get_order( $order->get_id() );
		$this->assertTrue( $fresh->is_paid() );
		$this->assertSame( '401234567890', $fresh->get_transaction_id() );
		// Second confirmation must not double-complete.
		$this->assertWPError( $this->service->confirm_manually( $fresh, $admin ) );
	}

	public function test_reject_frees_reference_and_fails_order() {
		$order = $this->make_order();
		$this->service->submit_reference( $order, '401234567890' );
		$admin = $this->make_admin();
		$this->assertNotWPError( $this->service->reject( $order, $admin, 'Not in statement' ) );
		$this->assertSame( 'failed', wc_get_order( $order->get_id() )->get_status() );
		$other = $this->make_order();
		$this->assertNotWPError( $this->service->submit_reference( $other, '401234567890' ) );
	}

	public function test_provider_verdict_with_wrong_amount_is_not_accepted() {
		$order = $this->make_order( '499.00' );
		$txn   = $this->service->submit_reference( $order, '401234567890' );
		$res   = UPIDW_Verification_Result::verified( '1.00', 'RRN1', $txn->txn_ref );
		$out   = $this->service->apply_result( $order, $txn, $res, 0, 'fake' );
		$this->assertWPError( $out );
		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
	}

	public function test_provider_verdict_without_amount_is_not_accepted() {
		$order = $this->make_order();
		$txn   = $this->service->submit_reference( $order, '401234567890' );
		$res   = new UPIDW_Verification_Result( UPIDW_Verification_Result::VERIFIED, '', 'RRN1', null, 'INR', $txn->txn_ref );
		$this->assertWPError( $this->service->apply_result( $order, $txn, $res, 0, 'fake' ) );
		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
	}

	public function test_provider_verdict_with_wrong_txn_ref_is_not_accepted() {
		$order = $this->make_order();
		$txn   = $this->service->submit_reference( $order, '401234567890' );
		$res   = UPIDW_Verification_Result::verified( '499.00', 'RRN1', 'SOMEOTHERREF' );
		$this->assertWPError( $this->service->apply_result( $order, $txn, $res, 0, 'fake' ) );
	}

	public function test_matching_provider_verdict_completes_order() {
		$order = $this->make_order();
		$txn   = $this->service->submit_reference( $order, '401234567890' );
		$res   = UPIDW_Verification_Result::verified( '499.00', 'RRN1', $txn->txn_ref );
		$this->assertNotWPError( $this->service->apply_result( $order, $txn, $res, 0, 'fake' ) );
		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
	}

	public function test_order_key_is_required_for_customer_access() {
		$order = $this->make_order();
		$this->assertNull( $this->service->get_order_for_key( $order->get_id(), 'wrong' ) );
		$this->assertNull( $this->service->get_order_for_key( $order->get_id(), '' ) );
		$this->assertInstanceOf( WC_Order::class, $this->service->get_order_for_key( $order->get_id(), $order->get_order_key() ) );
	}

	public function test_admin_rest_routes_reject_anonymous_users() {
		$order = $this->make_order();
		wp_set_current_user( 0 );
		$request  = new WP_REST_Request( 'POST', '/upi-direct/v1/admin/orders/' . $order->get_id() . '/verify' );
		$response = rest_do_request( $request );
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
	}

	public function test_customer_routes_reject_wrong_key_uniformly() {
		$order    = $this->make_order();
		$request  = new WP_REST_Request( 'GET', '/upi-direct/v1/orders/' . $order->get_id() . '/status' );
		$request->set_param( 'order_key', 'wc_order_wrong' );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );
		$missing = new WP_REST_Request( 'GET', '/upi-direct/v1/orders/99999999/status' );
		$missing->set_param( 'order_key', 'wc_order_wrong' );
		$this->assertSame( 403, rest_do_request( $missing )->get_status() );
	}
}

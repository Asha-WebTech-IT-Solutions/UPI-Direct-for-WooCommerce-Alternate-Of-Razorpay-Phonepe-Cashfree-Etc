<?php
/**
 * Payment lifecycle service.
 *
 * @package UPI_Direct_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the payment state machine.
 *
 * Initiation  : start_payment() / get_payment_params()  -> builds the UPI link. Proves nothing.
 * Submission  : submit_reference()                      -> customer claims they paid. Proves nothing.
 * Verification: confirm_manually() / apply_result()     -> the ONLY paths that call payment_complete().
 */
class UPIDW_Payment_Service {

	const META_TXN_REF     = '_upidw_txn_ref';
	const META_REFERENCE   = '_upidw_reference';
	const META_STATUS      = '_upidw_status';
	const META_VERIFIED_BY = '_upidw_verified_by';
	const META_VERIFIED_AT = '_upidw_verified_at';
	const META_VPA         = '_upidw_vpa';

	/** @var UPIDW_Repository */
	private $repo;

	/** @var UPIDW_Verifier_Registry */
	private $verifiers;

	/**
	 * Constructor.
	 *
	 * @param UPIDW_Repository        $repo      Repository.
	 * @param UPIDW_Verifier_Registry $verifiers Verifiers.
	 */
	public function __construct( UPIDW_Repository $repo, UPIDW_Verifier_Registry $verifiers ) {
		$this->repo      = $repo;
		$this->verifiers = $verifiers;
	}

	/**
	 * Register service-level hooks.
	 */
	public function hooks() {
		add_action( 'upidw_run_verification', array( $this, 'run_verification' ), 10, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'close_for_order' ) );
	}

	/**
	 * Load an order only if the key matches and it belongs to this gateway.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $key      Order key.
	 * @return WC_Order|null
	 */
	public function get_order_for_key( $order_id, $key ) {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order || UPIDW_Settings::GATEWAY_ID !== $order->get_payment_method() ) {
			return null;
		}
		$key = (string) $key;
		if ( '' === $key || ! hash_equals( (string) $order->get_order_key(), $key ) ) {
			return null;
		}
		return $order;
	}

	/**
	 * Get (or create) the open transaction for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return object|WP_Error Transaction row.
	 */
	public function start_payment( WC_Order $order ) {
		if ( 'INR' !== strtoupper( $order->get_currency() ) ) {
			return new WP_Error( 'upidw_currency', __( 'UPI payments are only available for orders in INR.', 'upi-direct-for-woocommerce' ) );
		}

		$vpa = (string) UPIDW_Settings::get( 'vpa' );
		if ( ! UPIDW_UPI::is_valid_vpa( $vpa ) ) {
			return new WP_Error( 'upidw_config', __( 'UPI is not configured for this store.', 'upi-direct-for-woocommerce' ) );
		}

		$amount = UPIDW_UPI::format_amount( $order->get_total() );
		$latest = $this->repo->get_latest_for_order( $order->get_id() );

		if ( $latest && UPIDW_Status::VERIFIED === $latest->status ) {
			return $latest;
		}

		if ( $latest && in_array( $latest->status, UPIDW_Status::open(), true ) ) {
			if ( $latest->amount === $amount && $latest->vpa === $vpa ) {
				return $latest;
			}
			// Order total or payee changed: the old request must not be honoured.
			$this->repo->transition( (int) $latest->id, UPIDW_Status::open(), UPIDW_Status::EXPIRED, array( 'active_reference' => null ) );
			$this->repo->add_event( (int) $latest->id, $order->get_id(), 'closed', __( 'Closed because the order amount or payee changed.', 'upi-direct-for-woocommerce' ) );
		}

		$id = false;
		for ( $i = 0; $i < 3 && ! $id; $i++ ) {
			$id = $this->repo->create(
				array(
					'order_id' => $order->get_id(),
					'txn_ref'  => UPIDW_UPI::make_txn_ref( $order->get_id(), wp_generate_password( 8, false, false ) ),
					'amount'   => $amount,
					'currency' => 'INR',
					'vpa'      => $vpa,
				)
			);
		}
		if ( ! $id ) {
			UPIDW_Logger::log( 'Could not create transaction for order ' . $order->get_id(), 'error' );
			return new WP_Error( 'upidw_db', __( 'Could not start the payment. Please try again.', 'upi-direct-for-woocommerce' ) );
		}

		$txn = $this->repo->get( $id );
		$order->update_meta_data( self::META_TXN_REF, $txn->txn_ref );
		$order->update_meta_data( self::META_VPA, $vpa );
		$order->update_meta_data( self::META_STATUS, UPIDW_Status::INITIATED );
		$order->save();
		$this->repo->add_event( $id, $order->get_id(), 'initiated', __( 'UPI payment request created.', 'upi-direct-for-woocommerce' ) );

		return $txn;
	}

	/**
	 * UPI parameters for a transaction.
	 *
	 * @param WC_Order $order Order.
	 * @param object   $txn   Transaction.
	 * @return array<string,string>
	 */
	public function get_payment_params( WC_Order $order, $txn ) {
		$settings = UPIDW_Settings::all();
		$store    = '' !== trim( (string) $settings['store_name'] ) ? $settings['store_name'] : get_bloginfo( 'name' );
		$merchant = '' !== trim( (string) $settings['merchant_name'] ) ? $settings['merchant_name'] : $store;
		$note     = strtr(
			(string) $settings['payment_note'],
			array(
				'{order_number}' => $order->get_order_number(),
				'{store_name}'   => $store,
			)
		);

		$params = UPIDW_UPI::build_params(
			array(
				'vpa'           => $txn->vpa,
				'merchant_name' => $merchant,
				'amount'        => $txn->amount,
				'note'          => $note,
				'txn_ref'       => $txn->txn_ref,
				'merchant_code' => $settings['merchant_code'],
			)
		);

		/**
		 * Filters the UPI parameters (pa, pn, am, cu, tn, tr, mc).
		 *
		 * @param array    $params Params.
		 * @param WC_Order $order  Order.
		 * @param object   $txn    Transaction row.
		 */
		return apply_filters( 'upidw_payment_params', $params, $order, $txn );
	}

	/**
	 * Customer submits the UTR / reference. This is a CLAIM, never proof.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $reference Raw reference.
	 * @return object|WP_Error Transaction row on success.
	 */
	public function submit_reference( WC_Order $order, $reference ) {
		if ( UPIDW_Settings::GATEWAY_ID !== $order->get_payment_method() || $order->is_paid() || ! $order->has_status( array( 'on-hold', 'pending' ) ) ) {
			return new WP_Error( 'upidw_not_payable', __( 'This order is not awaiting a UPI payment.', 'upi-direct-for-woocommerce' ), array( 'status' => 409 ) );
		}

		$txn = $this->start_payment( $order );
		if ( is_wp_error( $txn ) ) {
			return $txn;
		}
		if ( ! in_array( $txn->status, UPIDW_Status::open(), true ) ) {
			return new WP_Error( 'upidw_not_payable', __( 'This payment can no longer be updated.', 'upi-direct-for-woocommerce' ), array( 'status' => 409 ) );
		}

		$max      = max( 1, (int) UPIDW_Settings::get( 'max_attempts', 5 ) );
		$attempts = $this->repo->bump_attempts( (int) $txn->id );
		if ( $attempts > $max ) {
			$this->repo->add_event( (int) $txn->id, $order->get_id(), 'throttled', __( 'Too many reference submissions.', 'upi-direct-for-woocommerce' ) );
			return new WP_Error( 'upidw_too_many_attempts', __( 'Too many attempts. Please contact the store with your payment details.', 'upi-direct-for-woocommerce' ), array( 'status' => 429 ) );
		}

		$format    = (string) UPIDW_Settings::get( 'reference_format', 'utr12' );
		$reference = UPIDW_UPI::normalize_reference( $reference );
		if ( ! UPIDW_UPI::is_valid_reference( $reference, $format ) ) {
			$message = 'alnum' === $format
				? __( 'Enter a valid reference of 8 to 35 letters and digits.', 'upi-direct-for-woocommerce' )
				: __( 'Enter the 12-digit UPI reference number (UTR) from your payment app.', 'upi-direct-for-woocommerce' );
			return new WP_Error( 'upidw_invalid_reference', $message, array( 'status' => 400 ) );
		}

		$result = $this->repo->attach_reference( (int) $txn->id, $reference );
		if ( 'duplicate' === $result ) {
			$this->repo->add_event( (int) $txn->id, $order->get_id(), 'duplicate', __( 'A reference that is already in use was submitted.', 'upi-direct-for-woocommerce' ) );
			return new WP_Error( 'upidw_duplicate_reference', __( 'This reference has already been used for another payment.', 'upi-direct-for-woocommerce' ), array( 'status' => 409 ) );
		}
		if ( true !== $result ) {
			return new WP_Error( 'upidw_not_payable', __( 'This payment can no longer be updated.', 'upi-direct-for-woocommerce' ), array( 'status' => 409 ) );
		}

		$txn = $this->repo->get( (int) $txn->id );
		$order->update_meta_data( self::META_REFERENCE, $reference );
		$order->update_meta_data( self::META_STATUS, UPIDW_Status::SUBMITTED );
		$order->save();
		$order->add_order_note(
			sprintf(
				/* translators: %s: UPI reference number. */
				__( 'Customer submitted UPI reference %s. Verify it against your bank statement before confirming.', 'upi-direct-for-woocommerce' ),
				$reference
			)
		);
		$this->repo->add_event( (int) $txn->id, $order->get_id(), 'submitted', __( 'Customer submitted a payment reference.', 'upi-direct-for-woocommerce' ) );

		/**
		 * Fires after a customer submitted a payment reference (unverified).
		 *
		 * @param WC_Order $order Order.
		 * @param object   $txn   Transaction.
		 */
		do_action( 'upidw_reference_submitted', $order, $txn );

		$this->notify_admin( $order, $txn );
		$this->request_verification( $txn );

		return $txn;
	}

	/**
	 * Queue automatic verification if the active verifier supports it.
	 *
	 * @param object $txn Transaction.
	 */
	public function request_verification( $txn ) {
		$verifier = $this->verifiers->active();
		if ( ! $verifier->is_automatic() ) {
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'upidw_run_verification', array( (int) $txn->id, 1 ), 'upi-direct-for-woocommerce' );
		} else {
			$this->run_verification( (int) $txn->id, 1 );
		}
	}

	/**
	 * Action Scheduler callback: ask the verifier and apply the verdict.
	 *
	 * @param int $txn_id  Transaction ID.
	 * @param int $attempt Attempt number, starting at 1.
	 */
	public function run_verification( $txn_id, $attempt = 1 ) {
		$txn = $this->repo->get( (int) $txn_id );
		if ( ! $txn || ! in_array( $txn->status, UPIDW_Status::open(), true ) ) {
			return;
		}
		$order = wc_get_order( (int) $txn->order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$verifier = $this->verifiers->active();
		if ( ! $verifier->is_automatic() ) {
			return;
		}

		try {
			$result = $verifier->verify( $txn, $order );
		} catch ( Throwable $e ) {
			UPIDW_Logger::log( 'Verifier ' . $verifier->get_id() . ' threw: ' . $e->getMessage(), 'error' );
			$result = UPIDW_Verification_Result::pending( 'error' );
		}

		$this->apply_result( $order, $txn, $result, 0, $verifier->get_id() );

		/** This filter is documented in docs/DEVELOPER.md. */
		$max = (int) apply_filters( 'upidw_max_poll_attempts', 20 );
		if ( $result->is_pending() && $attempt < $max && function_exists( 'as_schedule_single_action' ) ) {
			$interval = (int) apply_filters( 'upidw_poll_interval', 120, $attempt );
			as_schedule_single_action( time() + max( 30, $interval ), 'upidw_run_verification', array( (int) $txn->id, $attempt + 1 ), 'upi-direct-for-woocommerce' );
		}
	}

	/**
	 * Apply a verdict from a trusted verifier.
	 *
	 * A "verified" verdict is only accepted if the amount, currency and (when echoed) the
	 * transaction reference match what we expect. Anything else is logged and ignored.
	 *
	 * @param WC_Order                    $order    Order.
	 * @param object                      $txn      Transaction.
	 * @param UPIDW_Verification_Result   $result   Verdict.
	 * @param int                         $user_id  Acting user (0 = system).
	 * @param string                      $provider Verifier ID.
	 * @return object|true|WP_Error
	 */
	public function apply_result( WC_Order $order, $txn, UPIDW_Verification_Result $result, $user_id = 0, $provider = '' ) {
		if ( $result->is_verified() ) {
			$mismatch = '';
			if ( null === $result->amount || ! UPIDW_UPI::amounts_match( $result->amount, $txn->amount ) || ! UPIDW_UPI::amounts_match( $result->amount, $order->get_total() ) ) {
				$mismatch = __( 'amount does not match the order', 'upi-direct-for-woocommerce' );
			} elseif ( 'INR' !== strtoupper( $result->currency ) ) {
				$mismatch = __( 'currency is not INR', 'upi-direct-for-woocommerce' );
			} elseif ( '' !== $result->transaction_ref && ! hash_equals( (string) $txn->txn_ref, $result->transaction_ref ) ) {
				$mismatch = __( 'transaction reference does not match', 'upi-direct-for-woocommerce' );
			}
			if ( '' !== $mismatch ) {
				$this->repo->add_event(
					(int) $txn->id,
					$order->get_id(),
					'mismatch',
					/* translators: %s: reason. */
					sprintf( __( 'Verifier reported a payment but it was NOT accepted: %s.', 'upi-direct-for-woocommerce' ), $mismatch )
				);
				$order->add_order_note( __( 'A payment verifier reported a payment that does not match this order. It was not accepted. Please review manually.', 'upi-direct-for-woocommerce' ) );
				return new WP_Error( 'upidw_mismatch', $mismatch, array( 'status' => 422 ) );
			}
			return $this->finalize_verified( $order, $txn, $provider ? $provider : 'provider', $result->provider_reference, (int) $user_id );
		}

		if ( $result->is_failed() ) {
			return $this->reject( $order, (int) $user_id, $result->message, $txn );
		}

		return true;
	}

	/**
	 * Human confirmation. Requires the verify capability.
	 *
	 * @param WC_Order $order   Order.
	 * @param int      $user_id Acting user.
	 * @param string   $note    Optional note.
	 * @return object|WP_Error
	 */
	public function confirm_manually( WC_Order $order, $user_id, $note = '' ) {
		if ( ! UPIDW_Capabilities::can_verify( (int) $user_id ) || (int) $user_id <= 0 ) {
			return new WP_Error( 'upidw_forbidden', __( 'You are not allowed to verify payments.', 'upi-direct-for-woocommerce' ), array( 'status' => 403 ) );
		}
		if ( UPIDW_Settings::GATEWAY_ID !== $order->get_payment_method() ) {
			return new WP_Error( 'upidw_not_upi', __( 'This order was not paid with UPI Direct.', 'upi-direct-for-woocommerce' ), array( 'status' => 400 ) );
		}
		if ( $order->is_paid() ) {
			return new WP_Error( 'upidw_already_paid', __( 'This order is already paid.', 'upi-direct-for-woocommerce' ), array( 'status' => 409 ) );
		}

		$txn = $this->repo->get_latest_for_order( $order->get_id() );
		if ( ! $txn || ! in_array( $txn->status, UPIDW_Status::open(), true ) ) {
			$txn = $this->start_payment( $order );
			if ( is_wp_error( $txn ) ) {
				return $txn;
			}
		}

		$reference = (string) $txn->customer_reference;
		return $this->finalize_verified( $order, $txn, 'manual', $reference, (int) $user_id, $note );
	}

	/**
	 * Mark a payment as verified and complete the order. Atomic and idempotent.
	 *
	 * @param WC_Order $order    Order.
	 * @param object   $txn      Transaction.
	 * @param string   $provider Verifier ID.
	 * @param string   $ref      Bank reference.
	 * @param int      $user_id  Acting user.
	 * @param string   $note     Optional note.
	 * @return object|WP_Error
	 */
	private function finalize_verified( WC_Order $order, $txn, $provider, $ref, $user_id, $note = '' ) {
		$now = $this->repo->now();
		$ok  = $this->repo->transition(
			(int) $txn->id,
			UPIDW_Status::open(),
			UPIDW_Status::VERIFIED,
			array(
				'provider'           => $provider,
				'provider_reference' => '' !== $ref ? $ref : null,
				'verified_by'        => $user_id > 0 ? $user_id : null,
				'verified_at'        => $now,
			)
		);

		if ( ! $ok ) {
			$fresh = $this->repo->get( (int) $txn->id );
			if ( $fresh && UPIDW_Status::VERIFIED === $fresh->status ) {
				return $fresh; // Another request won the race: idempotent success.
			}
			return new WP_Error( 'upidw_invalid_state', __( 'This payment can no longer be verified.', 'upi-direct-for-woocommerce' ), array( 'status' => 409 ) );
		}

		$order->update_meta_data( self::META_STATUS, UPIDW_Status::VERIFIED );
		$order->update_meta_data( self::META_VERIFIED_AT, $now );
		if ( $user_id > 0 ) {
			$order->update_meta_data( self::META_VERIFIED_BY, $user_id );
		}

		$by   = $user_id > 0 ? get_userdata( $user_id ) : false;
		$text = sprintf(
			/* translators: 1: verification method, 2: who verified, 3: reference. */
			__( 'UPI payment verified (%1$s) by %2$s. Reference: %3$s.', 'upi-direct-for-woocommerce' ),
			$provider,
			$by ? $by->display_name : __( 'system', 'upi-direct-for-woocommerce' ),
			'' !== $ref ? $ref : __( 'none', 'upi-direct-for-woocommerce' )
		);
		if ( '' !== $note ) {
			$text .= ' ' . wp_strip_all_tags( $note );
		}
		$order->add_order_note( $text );
		$order->payment_complete( '' !== $ref ? $ref : (string) $txn->txn_ref );

		$this->repo->add_event( (int) $txn->id, $order->get_id(), 'verified', $text, $user_id );
		$fresh = $this->repo->get( (int) $txn->id );

		/**
		 * Fires after a UPI payment was verified and the order completed.
		 *
		 * @param WC_Order $order Order.
		 * @param object   $txn   Transaction.
		 */
		do_action( 'upidw_payment_verified', $order, $fresh );
		return $fresh;
	}

	/**
	 * Reject a payment (not received / wrong reference). The customer may retry.
	 *
	 * @param WC_Order    $order   Order.
	 * @param int         $user_id Acting user (0 = system).
	 * @param string      $reason  Reason.
	 * @param object|null $txn     Transaction (latest if omitted).
	 * @return object|WP_Error
	 */
	public function reject( WC_Order $order, $user_id, $reason = '', $txn = null ) {
		if ( (int) $user_id > 0 && ! UPIDW_Capabilities::can_verify( (int) $user_id ) ) {
			return new WP_Error( 'upidw_forbidden', __( 'You are not allowed to reject payments.', 'upi-direct-for-woocommerce' ), array( 'status' => 403 ) );
		}
		if ( $order->is_paid() ) {
			return new WP_Error( 'upidw_already_paid', __( 'This order is already paid.', 'upi-direct-for-woocommerce' ), array( 'status' => 409 ) );
		}
		$txn = $txn ? $txn : $this->repo->get_latest_for_order( $order->get_id() );
		if ( ! $txn ) {
			return new WP_Error( 'upidw_no_transaction', __( 'No UPI transaction found for this order.', 'upi-direct-for-woocommerce' ), array( 'status' => 404 ) );
		}

		$ok = $this->repo->transition( (int) $txn->id, UPIDW_Status::open(), UPIDW_Status::REJECTED, array( 'active_reference' => null ) );
		if ( ! $ok ) {
			return new WP_Error( 'upidw_invalid_state', __( 'This payment can no longer be rejected.', 'upi-direct-for-woocommerce' ), array( 'status' => 409 ) );
		}

		$reason = wp_strip_all_tags( (string) $reason );
		$order->update_meta_data( self::META_STATUS, UPIDW_Status::REJECTED );
		$order->update_status(
			'failed',
			/* translators: %s: reason. */
			sprintf( __( 'UPI payment rejected. %s', 'upi-direct-for-woocommerce' ), $reason )
		);
		$this->repo->add_event( (int) $txn->id, $order->get_id(), 'rejected', $reason, (int) $user_id );

		/**
		 * Fires after a UPI payment was rejected.
		 *
		 * @param WC_Order $order  Order.
		 * @param object   $txn    Transaction.
		 * @param string   $reason Reason.
		 */
		do_action( 'upidw_payment_rejected', $order, $this->repo->get( (int) $txn->id ), $reason );
		return $this->repo->get( (int) $txn->id );
	}

	/**
	 * Close any open transaction when the order is cancelled.
	 *
	 * @param int $order_id Order ID.
	 */
	public function close_for_order( $order_id ) {
		$txn = $this->repo->get_latest_for_order( (int) $order_id );
		if ( $txn && $this->repo->transition( (int) $txn->id, UPIDW_Status::open(), UPIDW_Status::EXPIRED, array( 'active_reference' => null ) ) ) {
			$this->repo->add_event( (int) $txn->id, (int) $order_id, 'closed', __( 'Order cancelled.', 'upi-direct-for-woocommerce' ) );
		}
	}

	/**
	 * What the customer should see.
	 *
	 * @param WC_Order $order Order.
	 * @return array{state:string,label:string,message:string,reference:string}
	 */
	public function get_customer_state( WC_Order $order ) {
		$txn       = $this->repo->get_latest_for_order( $order->get_id() );
		$reference = $txn && $txn->customer_reference ? (string) $txn->customer_reference : '';
		$masked    = '' !== $reference ? str_repeat( '•', max( 0, strlen( $reference ) - 4 ) ) . substr( $reference, -4 ) : '';

		$state = 'awaiting_payment';
		if ( $order->is_paid() || ( $txn && UPIDW_Status::VERIFIED === $txn->status ) ) {
			$state = 'paid';
		} elseif ( $order->has_status( array( 'cancelled', 'refunded' ) ) || ( $txn && UPIDW_Status::EXPIRED === $txn->status ) ) {
			$state = 'closed';
		} elseif ( $txn && UPIDW_Status::REJECTED === $txn->status ) {
			$state = 'rejected';
		} elseif ( $txn && UPIDW_Status::SUBMITTED === $txn->status ) {
			$state = 'awaiting_verification';
		}

		$labels = array(
			'awaiting_payment'      => array( __( 'Awaiting payment', 'upi-direct-for-woocommerce' ), __( 'Pay with your UPI app, then enter the reference number below.', 'upi-direct-for-woocommerce' ) ),
			'awaiting_verification' => array( __( 'Verifying payment', 'upi-direct-for-woocommerce' ), __( 'Thanks! We received your reference and are checking it against our bank records. This page updates automatically.', 'upi-direct-for-woocommerce' ) ),
			'paid'                  => array( __( 'Payment confirmed', 'upi-direct-for-woocommerce' ), __( 'We have received your payment. Thank you!', 'upi-direct-for-woocommerce' ) ),
			'rejected'              => array( __( 'Payment not confirmed', 'upi-direct-for-woocommerce' ), __( 'We could not match your payment. Please check the details or contact the store.', 'upi-direct-for-woocommerce' ) ),
			'closed'                => array( __( 'Order closed', 'upi-direct-for-woocommerce' ), __( 'This order is no longer awaiting payment.', 'upi-direct-for-woocommerce' ) ),
		);

		return array(
			'state'     => $state,
			'label'     => $labels[ $state ][0],
			'message'   => $labels[ $state ][1],
			'reference' => $masked,
		);
	}

	/**
	 * Notify the store admin that a reference needs checking.
	 *
	 * @param WC_Order $order Order.
	 * @param object   $txn   Transaction.
	 */
	private function notify_admin( WC_Order $order, $txn ) {
		if ( ! UPIDW_Settings::is_yes( 'notify_admin' ) || $this->verifiers->active()->is_automatic() ) {
			return;
		}
		$to = (string) apply_filters( 'upidw_admin_notification_email', get_option( 'admin_email' ), $order );
		if ( ! is_email( $to ) ) {
			return;
		}
		$subject = sprintf(
			/* translators: 1: site name, 2: order number. */
			__( '[%1$s] UPI payment awaiting verification for order #%2$s', 'upi-direct-for-woocommerce' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			$order->get_order_number()
		);
		$body = sprintf(
			/* translators: 1: amount, 2: reference, 3: edit order URL. */
			__( "A customer reports having paid %1\$s by UPI.\nReference: %2\$s\n\nCheck your bank statement, then confirm or reject the payment here:\n%3\$s", 'upi-direct-for-woocommerce' ),
			wp_strip_all_tags( wc_price( $txn->amount ) ),
			$txn->customer_reference,
			$order->get_edit_order_url()
		);
		wp_mail( $to, $subject, $body );
	}
}

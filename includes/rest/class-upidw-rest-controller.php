<?php
/**
 * REST API.
 *
 * @package UPI_Direct_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /wp-json/upi-direct/v1.
 *
 * Customer routes are authorised by the order key (the same secret WooCommerce uses for the
 * order-received page) plus a per-order nonce. Admin routes need the verify capability.
 */
final class UPIDW_REST_Controller {

	const NAMESPACE_ID = 'upi-direct/v1';

	/** @var UPIDW_Payment_Service */
	private $service;

	/** @var UPIDW_Repository */
	private $repo;

	/** @var UPIDW_Verifier_Registry */
	private $verifiers;

	/**
	 * Constructor.
	 *
	 * @param UPIDW_Payment_Service   $service   Service.
	 * @param UPIDW_Repository        $repo      Repository.
	 * @param UPIDW_Verifier_Registry $verifiers Verifiers.
	 */
	public function __construct( UPIDW_Payment_Service $service, UPIDW_Repository $repo, UPIDW_Verifier_Registry $verifiers ) {
		$this->service   = $service;
		$this->repo      = $repo;
		$this->verifiers = $verifiers;
	}

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$order_key = array(
			'required'          => true,
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		);

		register_rest_route(
			self::NAMESPACE_ID,
			'/orders/(?P<id>\d+)/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'can_access_order' ),
				'args'                => array( 'order_key' => $order_key ),
			)
		);

		register_rest_route(
			self::NAMESPACE_ID,
			'/orders/(?P<id>\d+)/reference',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'submit_reference' ),
				'permission_callback' => array( $this, 'can_submit_reference' ),
				'args'                => array(
					'order_key' => $order_key,
					'nonce'     => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'reference' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_ID,
			'/admin/orders/(?P<id>\d+)/verify',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'admin_verify' ),
				'permission_callback' => array( $this, 'can_verify' ),
				'args'                => array(
					'note' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_ID,
			'/admin/orders/(?P<id>\d+)/reject',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'admin_reject' ),
				'permission_callback' => array( $this, 'can_verify' ),
				'args'                => array(
					'reason' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_ID,
			'/admin/transactions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_transactions' ),
				'permission_callback' => array( $this, 'can_verify' ),
				'args'                => array(
					'status'   => array(
						'type'              => 'string',
						'enum'              => array_keys( UPIDW_Status::labels() ),
						'sanitize_callback' => 'sanitize_key',
					),
					'search'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
				),
			)
		);

		// Inbound webhooks for verification providers. The provider authenticates the request.
		register_rest_route(
			self::NAMESPACE_ID,
			'/webhook/(?P<provider>[a-z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'webhook' ),
				'permission_callback' => '__return_true', // Authentication happens inside the verifier (signature check).
			)
		);
	}

	/**
	 * Permission: customer proves knowledge of the order key.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_access_order( WP_REST_Request $request ) {
		$order = $this->service->get_order_for_key( (int) $request['id'], (string) $request->get_param( 'order_key' ) );
		if ( ! $order ) {
			// Same response for "no such order" and "wrong key": do not leak which orders exist.
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you cannot access this order.', 'upi-direct-for-woocommerce' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Permission: order key + per-order nonce for state-changing customer requests.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_submit_reference( WP_REST_Request $request ) {
		$access = $this->can_access_order( $request );
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$nonce = (string) $request->get_param( 'nonce' );
		if ( ! wp_verify_nonce( $nonce, 'upidw_submit_' . (int) $request['id'] ) ) {
			return new WP_Error( 'upidw_bad_nonce', __( 'This page has expired. Please refresh and try again.', 'upi-direct-for-woocommerce' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Permission: shop managers only.
	 *
	 * @return true|WP_Error
	 */
	public function can_verify() {
		if ( ! UPIDW_Capabilities::can_verify() ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'upi-direct-for-woocommerce' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * GET status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_status( WP_REST_Request $request ) {
		$order    = wc_get_order( (int) $request['id'] );
		$response = rest_ensure_response( $this->service->get_customer_state( $order ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * POST reference.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit_reference( WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request['id'] );
		$txn   = $this->service->submit_reference( $order, (string) $request->get_param( 'reference' ) );
		if ( is_wp_error( $txn ) ) {
			$data = $txn->get_error_data();
			return new WP_Error( $txn->get_error_code(), $txn->get_error_message(), array( 'status' => isset( $data['status'] ) ? $data['status'] : 400 ) );
		}
		$order = wc_get_order( $order->get_id() );
		return rest_ensure_response( $this->service->get_customer_state( $order ) );
	}

	/**
	 * POST admin verify.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_verify( WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'upidw_not_found', __( 'Order not found.', 'upi-direct-for-woocommerce' ), array( 'status' => 404 ) );
		}
		$result = $this->service->confirm_manually( $order, get_current_user_id(), (string) $request->get_param( 'note' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response(
			array(
				'status'  => UPIDW_Status::VERIFIED,
				'message' => __( 'Payment verified. The order has been updated.', 'upi-direct-for-woocommerce' ),
			)
		);
	}

	/**
	 * POST admin reject.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_reject( WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'upidw_not_found', __( 'Order not found.', 'upi-direct-for-woocommerce' ), array( 'status' => 404 ) );
		}
		$result = $this->service->reject( $order, get_current_user_id(), (string) $request->get_param( 'reason' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response(
			array(
				'status'  => UPIDW_Status::REJECTED,
				'message' => __( 'Payment rejected. The customer can retry.', 'upi-direct-for-woocommerce' ),
			)
		);
	}

	/**
	 * GET admin transactions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_transactions( WP_REST_Request $request ) {
		$args  = array(
			'status'   => (string) $request->get_param( 'status' ),
			'search'   => (string) $request->get_param( 'search' ),
			'page'     => (int) $request->get_param( 'page' ),
			'per_page' => (int) $request->get_param( 'per_page' ),
		);
		$rows  = $this->repo->query( $args );
		$total = $this->repo->count( $args );
		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'id'                 => (int) $row->id,
				'order_id'           => (int) $row->order_id,
				'txn_ref'            => $row->txn_ref,
				'amount'             => $row->amount,
				'currency'           => $row->currency,
				'status'             => $row->status,
				'customer_reference' => $row->customer_reference,
				'provider'           => $row->provider,
				'provider_reference' => $row->provider_reference,
				'created_at'         => mysql_to_rfc3339( $row->created_at ),
				'submitted_at'       => $row->submitted_at ? mysql_to_rfc3339( $row->submitted_at ) : null,
				'verified_at'        => $row->verified_at ? mysql_to_rfc3339( $row->verified_at ) : null,
			);
		}
		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / max( 1, $args['per_page'] ) ) ) );
		return $response;
	}

	/**
	 * Provider webhook. The verifier authenticates it; the service validates the verdict.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function webhook( WP_REST_Request $request ) {
		$verifier = $this->verifiers->get( sanitize_key( $request['provider'] ) );
		if ( ! $verifier || ! $verifier->is_automatic() || ! $verifier->is_configured() ) {
			return new WP_Error( 'upidw_unknown_provider', __( 'Unknown provider.', 'upi-direct-for-woocommerce' ), array( 'status' => 404 ) );
		}

		$result = $verifier->handle_webhook( $request );
		if ( is_wp_error( $result ) ) {
			UPIDW_Logger::log( 'Webhook rejected for ' . $verifier->get_id() . ': ' . $result->get_error_code(), 'warning' );
			return $result;
		}
		if ( ! $result instanceof UPIDW_Verification_Result || '' === $result->transaction_ref ) {
			return new WP_Error( 'upidw_bad_webhook', __( 'Invalid webhook result.', 'upi-direct-for-woocommerce' ), array( 'status' => 400 ) );
		}

		$txn   = $this->repo->get_by_txn_ref( $result->transaction_ref );
		$order = $txn ? wc_get_order( (int) $txn->order_id ) : null;
		if ( ! $txn || ! $order instanceof WC_Order ) {
			return new WP_Error( 'upidw_unknown_transaction', __( 'Unknown transaction.', 'upi-direct-for-woocommerce' ), array( 'status' => 404 ) );
		}

		$applied = $this->service->apply_result( $order, $txn, $result, 0, $verifier->get_id() );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}
		return rest_ensure_response( array( 'received' => true ) );
	}
}

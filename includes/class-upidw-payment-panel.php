<?php
/**
 * Customer-facing payment screen.
 *
 * @package UPI_Direct_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the payment panel on the order-received and view-order pages, plus a
 * no-JavaScript fallback for submitting the reference.
 */
final class UPIDW_Payment_Panel {

	/** @var UPIDW_Payment_Service */
	private $service;

	/**
	 * Constructor.
	 *
	 * @param UPIDW_Payment_Service $service Service.
	 */
	public function __construct( UPIDW_Payment_Service $service ) {
		$this->service = $service;
	}

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'woocommerce_thankyou_' . UPIDW_Settings::GATEWAY_ID, array( $this, 'render' ), 5 );
		add_action( 'woocommerce_view_order', array( $this, 'render_on_view_order' ), 5 );
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
		add_action( 'admin_post_nopriv_upidw_submit_reference', array( $this, 'handle_form_post' ) );
		add_action( 'admin_post_upidw_submit_reference', array( $this, 'handle_form_post' ) );
	}

	/**
	 * Register (and conditionally enqueue) assets.
	 */
	public function register_assets() {
		wp_register_style( 'upidw-checkout', UPIDW_URL . 'assets/css/checkout.css', array(), UPIDW_VERSION );
		wp_register_script( 'upidw-qrcode', UPIDW_URL . 'assets/js/vendor/qrcode.js', array(), '2.0.4', true );
		wp_register_script( 'upidw-checkout', UPIDW_URL . 'assets/js/checkout.js', array( 'upidw-qrcode', 'wp-i18n' ), UPIDW_VERSION, true );
		wp_set_script_translations( 'upidw-checkout', 'upi-direct-for-woocommerce', UPIDW_PATH . 'languages' );

		if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'view-order' ) ) ) {
			wp_enqueue_style( 'upidw-checkout' );
		}
	}

	/**
	 * Render on the My Account → View order page for unpaid UPI orders.
	 *
	 * @param int $order_id Order ID.
	 */
	public function render_on_view_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order && UPIDW_Settings::GATEWAY_ID === $order->get_payment_method() && ! $order->is_paid() && $order->has_status( array( 'on-hold', 'pending' ) ) ) {
			$this->render( $order_id );
		}
	}

	/**
	 * Render the panel.
	 *
	 * @param int $order_id Order ID.
	 */
	public function render( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || UPIDW_Settings::GATEWAY_ID !== $order->get_payment_method() ) {
			return;
		}

		$state = $this->service->get_customer_state( $order );
		$links = array();
		$uri   = '';
		$txn   = null;

		if ( in_array( $state['state'], array( 'awaiting_payment', 'awaiting_verification' ), true ) ) {
			$txn = $this->service->start_payment( $order );
			if ( is_wp_error( $txn ) ) {
				wc_print_notice( $txn->get_error_message(), 'error' );
				return;
			}
			$params = $this->service->get_payment_params( $order, $txn );
			$uri    = UPIDW_UPI::build_uri( $params );
			$links  = UPIDW_UPI::app_links( $params, (array) UPIDW_Settings::get( 'apps', array() ) );
			if ( isset( $links['generic'] ) ) {
				$links['generic']['label'] = __( 'Choose a UPI app', 'upi-direct-for-woocommerce' );
			}
			/**
			 * Filters the app launch links shown on the payment screen.
			 *
			 * @param array    $links Links: id => [label, uri, android_only].
			 * @param WC_Order $order Order.
			 */
			$links = apply_filters( 'upidw_app_links', $links, $order );
		}

		$config = array(
			'restUrl'  => esc_url_raw( rest_url( UPIDW_REST_Controller::NAMESPACE_ID . '/' ) ),
			'orderId'  => $order->get_id(),
			'orderKey' => $order->get_order_key(),
			'nonce'    => wp_create_nonce( 'upidw_submit_' . $order->get_id() ),
			'upiUri'   => $uri,
			'showQr'   => UPIDW_Settings::is_yes( 'show_qr' ),
			'state'    => $state['state'],
			'pollMs'   => 8000,
		);
		/**
		 * Filters the JS configuration of the payment panel.
		 *
		 * @param array    $config Config.
		 * @param WC_Order $order  Order.
		 */
		$config = apply_filters( 'upidw_panel_config', $config, $order );

		wp_enqueue_style( 'upidw-checkout' );
		wp_enqueue_script( 'upidw-checkout' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$flash = isset( $_GET['upidw_msg'] ) ? sanitize_key( wp_unslash( $_GET['upidw_msg'] ) ) : '';

		wc_get_template(
			'payment-panel.php',
			array(
				'order'         => $order,
				'state'         => $state,
				'txn'           => $txn,
				'links'         => $links,
				'config'        => $config,
				'instructions'  => (string) UPIDW_Settings::get( 'instructions' ),
				'vpa'           => $txn ? $txn->vpa : '',
				'ask_reference' => UPIDW_Settings::is_yes( 'require_reference' ),
				'ref_format'    => (string) UPIDW_Settings::get( 'reference_format', 'utr12' ),
				'flash'         => $this->flash_message( $flash ),
				'retry_url'     => $order->get_checkout_payment_url(),
			),
			'upi-direct-for-woocommerce/',
			UPIDW_PATH . 'templates/'
		);
	}

	/**
	 * Whitelisted flash messages for the no-JS fallback.
	 *
	 * @param string $code Code.
	 * @return array{type:string,text:string}|null
	 */
	private function flash_message( $code ) {
		$map = array(
			'ok'                       => array( 'success', __( 'Reference received. We will confirm your payment shortly.', 'upi-direct-for-woocommerce' ) ),
			'upidw_invalid_reference'  => array( 'error', __( 'That reference does not look right. Check the number in your payment app and try again.', 'upi-direct-for-woocommerce' ) ),
			'upidw_duplicate_reference' => array( 'error', __( 'This reference has already been used for another payment.', 'upi-direct-for-woocommerce' ) ),
			'upidw_too_many_attempts'  => array( 'error', __( 'Too many attempts. Please contact the store with your payment details.', 'upi-direct-for-woocommerce' ) ),
			'error'                    => array( 'error', __( 'Something went wrong. Please try again.', 'upi-direct-for-woocommerce' ) ),
		);
		if ( '' === $code || ! isset( $map[ $code ] ) ) {
			return null;
		}
		return array(
			'type' => $map[ $code ][0],
			'text' => $map[ $code ][1],
		);
	}

	/**
	 * No-JavaScript fallback: classic POST that uses the same service as the REST route.
	 */
	public function handle_form_post() {
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified just below.
		$nonce    = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! $order_id || ! wp_verify_nonce( $nonce, 'upidw_submit_' . $order_id ) ) {
			wp_die( esc_html__( 'This page has expired. Please go back, refresh and try again.', 'upi-direct-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$key   = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		$order = $this->service->get_order_for_key( $order_id, $key );
		if ( ! $order ) {
			wp_die( esc_html__( 'Sorry, you cannot access this order.', 'upi-direct-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$reference = isset( $_POST['reference'] ) ? sanitize_text_field( wp_unslash( $_POST['reference'] ) ) : '';
		$result    = $this->service->submit_reference( $order, $reference );
		$code      = is_wp_error( $result ) ? $result->get_error_code() : 'ok';

		wp_safe_redirect( add_query_arg( 'upidw_msg', rawurlencode( $code ), $order->get_checkout_order_received_url() ) );
		exit;
	}

	/**
	 * Add instructions to the customer's order-on-hold email.
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $sent_to_admin Whether the email goes to the admin.
	 * @param bool     $plain_text    Plain text email.
	 */
	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if ( $sent_to_admin || ! $order instanceof WC_Order || UPIDW_Settings::GATEWAY_ID !== $order->get_payment_method() || ! $order->has_status( 'on-hold' ) ) {
			return;
		}
		$text = trim( (string) UPIDW_Settings::get( 'instructions' ) );
		$url  = $order->get_checkout_order_received_url();
		if ( $plain_text ) {
			echo esc_html( wp_strip_all_tags( $text ) ) . "\n";
			echo esc_html__( 'Complete your UPI payment:', 'upi-direct-for-woocommerce' ) . ' ' . esc_url( $url ) . "\n\n";
			return;
		}
		echo '<p>' . esc_html( $text ) . '</p>';
		echo '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Complete your UPI payment', 'upi-direct-for-woocommerce' ) . '</a></p>';
	}
}

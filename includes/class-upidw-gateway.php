<?php
/**
 * WooCommerce payment gateway.
 *
 * @package UPI_Direct_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * UPI Direct gateway. Initiates a UPI request; never marks an order paid by itself.
 */
class UPIDW_Gateway extends WC_Payment_Gateway {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = UPIDW_Settings::GATEWAY_ID;
		$this->icon               = '';
		$this->has_fields         = false;
		$this->method_title       = __( 'UPI Direct', 'upi-direct-for-woocommerce' );
		$this->method_description = __( 'Let customers pay straight to your UPI ID using UPI Intent or a QR code. Payments are confirmed by you (or a verification provider), never by the customer\'s browser.', 'upi-direct-for-woocommerce' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title        = $this->get_option( 'title' );
		$this->description  = $this->get_option( 'description' );
		$this->instructions = $this->get_option( 'instructions' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_settings_assets' ) );
	}

	/**
	 * Settings fields.
	 */
	public function init_form_fields() {
		$registry = class_exists( 'UPIDW_Plugin' ) && UPIDW_Plugin::instance()->verifiers ? UPIDW_Plugin::instance()->verifiers : null;
		$verifier_options = $registry ? $registry->options() : array( 'manual' => __( 'Manual verification', 'upi-direct-for-woocommerce' ) );
		$d = UPIDW_Settings::defaults();

		$this->form_fields = array(
			'enabled'           => array(
				'title'   => __( 'Enable/Disable', 'upi-direct-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable UPI Direct', 'upi-direct-for-woocommerce' ),
				'default' => 'no',
			),
			'section_merchant'  => array(
				'title'       => __( 'Merchant details', 'upi-direct-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Where the money goes. Use a UPI ID that is registered to your business account.', 'upi-direct-for-woocommerce' ),
			),
			'vpa'               => array(
				'title'       => __( 'UPI ID (VPA)', 'upi-direct-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'For example: yourshop@okaxis. Double-check it: customers pay this address directly.', 'upi-direct-for-woocommerce' ),
				'desc_tip'    => false,
				'default'     => '',
				'placeholder' => 'yourshop@bank',
			),
			'merchant_name'     => array(
				'title'       => __( 'Merchant name', 'upi-direct-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Shown in the customer\'s UPI app as the payee. Must match the name on your bank/UPI account. Max 50 characters.', 'upi-direct-for-woocommerce' ),
				'default'     => '',
			),
			'store_name'        => array(
				'title'       => __( 'Store name', 'upi-direct-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Used in the payment note. Leave empty to use your site title.', 'upi-direct-for-woocommerce' ),
				'default'     => '',
			),
			'merchant_code'     => array(
				'title'       => __( 'Merchant category code', 'upi-direct-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Optional 4-digit MCC issued by your bank. Leave empty if you do not have one.', 'upi-direct-for-woocommerce' ),
				'default'     => '',
				'css'         => 'width:6em;',
			),
			'payment_note'      => array(
				'title'       => __( 'Payment note', 'upi-direct-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Shown to the customer in their UPI app (max 80 characters). Placeholders: {order_number}, {store_name}.', 'upi-direct-for-woocommerce' ),
				'default'     => $d['payment_note'],
			),
			'section_checkout'  => array(
				'title' => __( 'Checkout experience', 'upi-direct-for-woocommerce' ),
				'type'  => 'title',
			),
			'title'             => array(
				'title'    => __( 'Title', 'upi-direct-for-woocommerce' ),
				'type'     => 'text',
				'desc_tip' => __( 'The title customers see at checkout.', 'upi-direct-for-woocommerce' ),
				'default'  => $d['title'],
			),
			'description'       => array(
				'title'    => __( 'Description', 'upi-direct-for-woocommerce' ),
				'type'     => 'textarea',
				'desc_tip' => __( 'Shown under the title at checkout.', 'upi-direct-for-woocommerce' ),
				'default'  => $d['description'],
			),
			'instructions'      => array(
				'title'       => __( 'Payment instructions', 'upi-direct-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Shown on the payment screen and in the order-on-hold email.', 'upi-direct-for-woocommerce' ),
				'default'     => $d['instructions'],
			),
			'apps'              => array(
				'title'             => __( 'UPI apps', 'upi-direct-for-woocommerce' ),
				'type'              => 'multiselect',
				'class'             => 'wc-enhanced-select',
				'description'       => __( 'Launch buttons shown on mobile. "Any UPI app" uses the standard UPI link and works everywhere; app-specific links are provided for convenience.', 'upi-direct-for-woocommerce' ),
				'default'           => $d['apps'],
				'options'           => array(
					'generic'  => __( 'Any UPI app (recommended)', 'upi-direct-for-woocommerce' ),
					'gpay'     => 'Google Pay',
					'phonepe'  => 'PhonePe',
					'paytm'    => 'Paytm',
					'bhim'     => 'BHIM (Android)',
				),
			),
			'show_qr'           => array(
				'title'   => __( 'QR code', 'upi-direct-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show a QR code (generated in the browser, nothing leaves your site)', 'upi-direct-for-woocommerce' ),
				'default' => 'yes',
			),
			'section_verify'    => array(
				'title'       => __( 'Verification & limits', 'upi-direct-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'A UPI app opening, or a customer saying "I paid", is not proof of payment. Orders are completed only after verification.', 'upi-direct-for-woocommerce' ),
			),
			'verifier'          => array(
				'title'       => __( 'Verification method', 'upi-direct-for-woocommerce' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'Manual: you confirm each payment from your bank statement. Add-ons can register automatic bank/PSP verifiers.', 'upi-direct-for-woocommerce' ),
				'default'     => 'manual',
				'options'     => $verifier_options,
			),
			'require_reference' => array(
				'title'       => __( 'Payment reference', 'upi-direct-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Ask customers for their UPI reference (UTR) after paying', 'upi-direct-for-woocommerce' ),
				'description' => __( 'Strongly recommended for manual verification: it lets you match the payment in your bank statement.', 'upi-direct-for-woocommerce' ),
				'default'     => 'yes',
			),
			'reference_format'  => array(
				'title'   => __( 'Reference format', 'upi-direct-for-woocommerce' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => 'utr12',
				'options' => array(
					'utr12' => __( '12-digit UTR (most banks and apps)', 'upi-direct-for-woocommerce' ),
					'alnum' => __( 'Letters and digits, 8–35 characters', 'upi-direct-for-woocommerce' ),
				),
			),
			'max_amount'        => array(
				'title'             => __( 'Maximum order amount (₹)', 'upi-direct-for-woocommerce' ),
				'type'              => 'number',
				'description'       => __( 'UPI hides itself above this amount. The standard per-transaction UPI limit is ₹1,00,000 for most payments; check your bank for yours.', 'upi-direct-for-woocommerce' ),
				'default'           => $d['max_amount'],
				'custom_attributes' => array( 'min' => '1', 'step' => '1' ),
			),
			'max_attempts'      => array(
				'title'             => __( 'Reference attempts per order', 'upi-direct-for-woocommerce' ),
				'type'              => 'number',
				'description'       => __( 'Stops customers from spamming guesses.', 'upi-direct-for-woocommerce' ),
				'default'           => $d['max_attempts'],
				'custom_attributes' => array( 'min' => '1', 'max' => '20', 'step' => '1' ),
			),
			'notify_admin'      => array(
				'title'   => __( 'Admin email', 'upi-direct-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Email the site admin when a customer submits a reference', 'upi-direct-for-woocommerce' ),
				'default' => 'yes',
			),
			'section_advanced'  => array(
				'title' => __( 'Advanced', 'upi-direct-for-woocommerce' ),
				'type'  => 'title',
			),
			'debug'             => array(
				'title'       => __( 'Debug log', 'upi-direct-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable logging', 'upi-direct-for-woocommerce' ),
				'description' => __( 'Logs go to WooCommerce → Status → Logs (source: upi-direct-for-woocommerce). UPI IDs are never logged.', 'upi-direct-for-woocommerce' ),
				'default'     => 'no',
			),
			'delete_data'       => array(
				'title'   => __( 'Uninstall', 'upi-direct-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Delete all plugin data (transactions, history, settings) when the plugin is deleted', 'upi-direct-for-woocommerce' ),
				'default' => 'no',
			),
		);
	}

	/**
	 * Validate the VPA field.
	 *
	 * @param string $key   Field key.
	 * @param string $value Posted value.
	 * @return string
	 */
	public function validate_vpa_field( $key, $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( '' !== $value && ! UPIDW_UPI::is_valid_vpa( $value ) ) {
			WC_Admin_Settings::add_error( __( 'The UPI ID looks invalid. Use the format name@bank. It was not saved.', 'upi-direct-for-woocommerce' ) );
			return (string) $this->get_option( 'vpa' );
		}
		return $value;
	}

	/**
	 * Validate the merchant code field.
	 *
	 * @param string $key   Field key.
	 * @param string $value Posted value.
	 * @return string
	 */
	public function validate_merchant_code_field( $key, $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( '' !== $value && ! preg_match( '/^[0-9]{4}$/D', $value ) ) {
			WC_Admin_Settings::add_error( __( 'The merchant category code must be exactly 4 digits. It was not saved.', 'upi-direct-for-woocommerce' ) );
			return (string) $this->get_option( 'merchant_code' );
		}
		return $value;
	}

	/**
	 * Refuse to enable the gateway without a valid VPA.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();
		if ( 'yes' === $this->get_option( 'enabled' ) && ! UPIDW_UPI::is_valid_vpa( (string) $this->get_option( 'vpa' ) ) ) {
			$this->update_option( 'enabled', 'no' );
			WC_Admin_Settings::add_error( __( 'UPI Direct was not enabled because no valid UPI ID is set.', 'upi-direct-for-woocommerce' ) );
		}
		return $saved;
	}

	/**
	 * Availability at checkout.
	 *
	 * @return bool
	 */
	public function is_available() {
		$available = parent::is_available();

		if ( $available && 'INR' !== strtoupper( get_woocommerce_currency() ) ) {
			$available = false;
		}
		if ( $available && ! UPIDW_UPI::is_valid_vpa( (string) $this->get_option( 'vpa' ) ) ) {
			$available = false;
		}
		if ( $available && function_exists( 'WC' ) && WC()->cart ) {
			$max = (float) $this->get_option( 'max_amount', 100000 );
			if ( $max > 0 && (float) WC()->cart->get_total( 'edit' ) > $max ) {
				$available = false;
			}
		}

		/**
		 * Filters whether UPI Direct is available at checkout.
		 *
		 * @param bool $available Availability.
		 */
		return (bool) apply_filters( 'upidw_is_available', $available );
	}

	/**
	 * Create the UPI request and send the customer to the payment screen.
	 * The order goes on-hold: payment is NOT assumed.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return array( 'result' => 'failure' );
		}

		$txn = UPIDW_Plugin::instance()->service->start_payment( $order );
		if ( is_wp_error( $txn ) ) {
			wc_add_notice( $txn->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_status( 'on-hold', __( 'Awaiting UPI payment. Verify the payment before shipping.', 'upi-direct-for-woocommerce' ) );

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Small admin script for the settings screen (live VPA hint).
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue_settings_assets( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'woocommerce_page_wc-settings' !== $hook || ! isset( $_GET['section'] ) || $this->id !== sanitize_key( wp_unslash( $_GET['section'] ) ) ) {
			return;
		}
		wp_enqueue_style( 'upidw-admin', UPIDW_URL . 'assets/css/admin.css', array(), UPIDW_VERSION );
	}
}

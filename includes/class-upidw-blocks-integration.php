<?php
/**
 * WooCommerce Blocks checkout integration.
 *
 * @package UPI_Direct_For_WooCommerce
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the payment method for the Cart & Checkout blocks.
 */
final class UPIDW_Blocks_Integration extends AbstractPaymentMethodType {

	/** @var string */
	protected $name = UPIDW_Settings::GATEWAY_ID;

	/**
	 * Load settings.
	 */
	public function initialize() {
		$this->settings = UPIDW_Settings::all();
	}

	/**
	 * Active when the gateway is enabled.
	 *
	 * @return bool
	 */
	public function is_active() {
		return 'yes' === $this->get_setting( 'enabled' );
	}

	/**
	 * Script handles.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'upidw-blocks',
			UPIDW_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			UPIDW_VERSION,
			true
		);
		wp_set_script_translations( 'upidw-blocks', 'upi-direct-for-woocommerce', UPIDW_PATH . 'languages' );
		return array( 'upidw-blocks' );
	}

	/**
	 * Data exposed to JS as getSetting( 'upidw_data' ).
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'       => $this->get_setting( 'title' ),
			'description' => $this->get_setting( 'description' ),
			'supports'    => array( 'products' ),
		);
	}
}

<?php
/**
 * Settings accessor.
 *
 * @package UPI_Direct_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Typed access to the gateway settings with defaults.
 */
final class UPIDW_Settings {

	const GATEWAY_ID = 'upidw';

	/**
	 * Default values.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'enabled'           => 'no',
			'title'             => __( 'Pay with UPI', 'upi-direct-for-woocommerce' ),
			'description'       => __( 'Pay instantly from any UPI app such as Google Pay, PhonePe, Paytm or BHIM.', 'upi-direct-for-woocommerce' ),
			'instructions'      => __( 'Pay the exact amount shown, then enter the UPI reference (UTR) from your payment app. We confirm your payment after checking it against our bank records.', 'upi-direct-for-woocommerce' ),
			'vpa'               => '',
			'merchant_name'     => '',
			'store_name'        => '',
			'merchant_code'     => '',
			'payment_note'      => 'Order {order_number} {store_name}',
			'apps'              => array( 'generic', 'gpay', 'phonepe', 'paytm', 'bhim' ),
			'show_qr'           => 'yes',
			'require_reference' => 'yes',
			'reference_format'  => 'utr12',
			'verifier'          => 'manual',
			'max_amount'        => '100000',
			'max_attempts'      => '5',
			'notify_admin'      => 'yes',
			'debug'             => 'no',
			'delete_data'       => 'no',
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		$stored = get_option( 'woocommerce_' . self::GATEWAY_ID . '_settings', array() );
		$stored = is_array( $stored ) ? $stored : array();
		/**
		 * Filters the merged gateway settings.
		 *
		 * @param array $settings Settings.
		 */
		return apply_filters( 'upidw_settings', array_merge( self::defaults(), $stored ) );
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = '' ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : $default;
	}

	/**
	 * Boolean helper for yes/no settings.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function is_yes( $key ) {
		return 'yes' === self::get( $key );
	}
}

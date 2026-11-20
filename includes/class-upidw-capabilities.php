<?php
/**
 * Capability helper.
 *
 * @package UPI_Direct_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Central place for capability checks.
 */
final class UPIDW_Capabilities {

	/**
	 * Capability required to confirm or reject payments.
	 *
	 * @return string
	 */
	public static function verify_capability() {
		/**
		 * Filters the capability needed to verify or reject UPI payments.
		 *
		 * @param string $cap Capability. Default manage_woocommerce.
		 */
		return (string) apply_filters( 'upidw_verify_capability', 'manage_woocommerce' );
	}

	/**
	 * Whether a user may verify payments.
	 *
	 * @param int $user_id User ID (0 = current user).
	 * @return bool
	 */
	public static function can_verify( $user_id = 0 ) {
		if ( $user_id > 0 ) {
			return user_can( $user_id, self::verify_capability() );
		}
		return current_user_can( self::verify_capability() );
	}
}

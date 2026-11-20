<?php
/**
 * Debug logger.
 *
 * @package UPI_Direct_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over the WooCommerce logger. Never log VPAs, names or full UPI links.
 */
final class UPIDW_Logger {

	/**
	 * Log a message when debug logging is on (errors are always logged).
	 *
	 * @param string $message Message.
	 * @param string $level   PSR-3 level.
	 */
	public static function log( $message, $level = 'info' ) {
		if ( 'error' !== $level && ! UPIDW_Settings::is_yes( 'debug' ) ) {
			return;
		}
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => 'upi-direct-for-woocommerce' ) );
		}
	}
}

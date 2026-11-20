<?php
/**
 * Transaction statuses.
 *
 * @package UPI_Direct_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Status constants and labels for UPI transactions.
 */
final class UPIDW_Status {

	const INITIATED = 'initiated';
	const SUBMITTED = 'submitted';
	const VERIFIED  = 'verified';
	const REJECTED  = 'rejected';
	const EXPIRED   = 'expired';

	/**
	 * All statuses with translated labels.
	 *
	 * @return array<string,string>
	 */
	public static function labels() {
		return array(
			self::INITIATED => __( 'Awaiting payment', 'upi-direct-for-woocommerce' ),
			self::SUBMITTED => __( 'Awaiting verification', 'upi-direct-for-woocommerce' ),
			self::VERIFIED  => __( 'Verified', 'upi-direct-for-woocommerce' ),
			self::REJECTED  => __( 'Rejected', 'upi-direct-for-woocommerce' ),
			self::EXPIRED   => __( 'Closed', 'upi-direct-for-woocommerce' ),
		);
	}

	/**
	 * Label for one status.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	public static function label( $status ) {
		$labels = self::labels();
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Statuses that can still move to verified/rejected.
	 *
	 * @return string[]
	 */
	public static function open() {
		return array( self::INITIATED, self::SUBMITTED );
	}
}

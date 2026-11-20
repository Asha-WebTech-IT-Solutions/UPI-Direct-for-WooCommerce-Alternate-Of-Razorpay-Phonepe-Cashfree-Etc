<?php
/**
 * UPI helpers: validation and payment link building.
 *
 * Deliberately free of WordPress function calls so it can be unit tested in isolation.
 *
 * @package UPI_Direct_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pure UPI utilities.
 */
final class UPIDW_UPI {

	/**
	 * Validate a Virtual Payment Address (handle@psp).
	 *
	 * @param string $vpa VPA.
	 * @return bool
	 */
	public static function is_valid_vpa( $vpa ) {
		return is_string( $vpa ) && 1 === preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9._-]{1,99}@[a-zA-Z][a-zA-Z0-9]{1,63}$/D', $vpa );
	}

	/**
	 * Normalise a customer-entered payment reference (strip whitespace, uppercase).
	 *
	 * @param string $reference Raw reference.
	 * @return string
	 */
	public static function normalize_reference( $reference ) {
		return strtoupper( preg_replace( '/[\s-]+/', '', trim( (string) $reference ) ) );
	}

	/**
	 * Regex for the accepted reference format.
	 *
	 * @param string $format utr12|alnum.
	 * @return string
	 */
	public static function reference_pattern( $format ) {
		if ( 'alnum' === $format ) {
			return '/^[A-Z0-9]{8,35}$/D';
		}
		return '/^[0-9]{12}$/D';
	}

	/**
	 * Check a normalised reference against a format.
	 *
	 * @param string $reference Normalised reference.
	 * @param string $format    utr12|alnum.
	 * @return bool
	 */
	public static function is_valid_reference( $reference, $format = 'utr12' ) {
		return 1 === preg_match( self::reference_pattern( $format ), $reference );
	}

	/**
	 * Format an amount for UPI (two decimals, dot separator, no thousands separator).
	 *
	 * @param string|float|int $amount Amount.
	 * @return string
	 */
	public static function format_amount( $amount ) {
		return number_format( round( (float) $amount, 2 ), 2, '.', '' );
	}

	/**
	 * Compare two amounts in minor units to avoid float drift.
	 *
	 * @param string|float|int $a First.
	 * @param string|float|int $b Second.
	 * @return bool
	 */
	public static function amounts_match( $a, $b ) {
		return (int) round( (float) $a * 100 ) === (int) round( (float) $b * 100 );
	}

	/**
	 * Build a transaction reference (UPI "tr"): alphanumeric, max 35 chars.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $random   Random alphanumeric suffix.
	 * @return string
	 */
	public static function make_txn_ref( $order_id, $random ) {
		$random = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $random ) );
		return substr( 'UPI' . (int) $order_id . $random, 0, 35 );
	}

	/**
	 * Truncate to a UTF-8 safe length after stripping control characters.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max characters.
	 * @return string
	 */
	public static function clean_text( $text, $max ) {
		$text = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', (string) $text );
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $max, 'UTF-8' );
		}
		return substr( $text, 0, $max );
	}

	/**
	 * Build the ordered UPI parameter set.
	 *
	 * @param array $args {
	 *     @type string $vpa           Payee VPA (pa).
	 *     @type string $merchant_name Payee name (pn).
	 *     @type string $amount        Amount (am).
	 *     @type string $note          Transaction note (tn).
	 *     @type string $txn_ref       Transaction reference (tr).
	 *     @type string $merchant_code Optional merchant category code (mc), 4 digits.
	 *     @type string $currency      Currency (cu). Always INR.
	 * }
	 * @return array<string,string>
	 */
	public static function build_params( array $args ) {
		$params = array(
			'pa' => trim( (string) $args['vpa'] ),
			'pn' => self::clean_text( $args['merchant_name'], 50 ),
			'am' => self::format_amount( $args['amount'] ),
			'cu' => 'INR',
			'tn' => self::clean_text( isset( $args['note'] ) ? $args['note'] : '', 80 ),
			'tr' => substr( preg_replace( '/[^A-Za-z0-9]/', '', (string) $args['txn_ref'] ), 0, 35 ),
		);
		if ( ! empty( $args['merchant_code'] ) && 1 === preg_match( '/^[0-9]{4}$/D', (string) $args['merchant_code'] ) ) {
			$params['mc'] = (string) $args['merchant_code'];
		}
		return array_filter(
			$params,
			static function ( $value ) {
				return '' !== $value;
			}
		);
	}

	/**
	 * RFC 3986 query string (spaces as %20, which UPI apps expect).
	 *
	 * @param array<string,string> $params Params.
	 * @return string
	 */
	public static function build_query( array $params ) {
		$pairs = array();
		foreach ( $params as $key => $value ) {
			$pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}
		return implode( '&', $pairs );
	}

	/**
	 * Standard NPCI deep link: upi://pay?...
	 *
	 * @param array<string,string> $params Params.
	 * @return string
	 */
	public static function build_uri( array $params ) {
		return 'upi://pay?' . self::build_query( $params );
	}

	/**
	 * Available app definitions. 'android_only' links use an Android intent URL.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function app_definitions() {
		return array(
			'generic'  => array(
				'label'  => 'Any UPI app',
				'scheme' => 'upi://pay?',
			),
			'gpay'     => array(
				'label'  => 'Google Pay',
				'scheme' => 'tez://upi/pay?',
			),
			'phonepe'  => array(
				'label'  => 'PhonePe',
				'scheme' => 'phonepe://pay?',
			),
			'paytm'    => array(
				'label'  => 'Paytm',
				'scheme' => 'paytmmp://pay?',
			),
			'bhim'     => array(
				'label'        => 'BHIM',
				'android_only' => true,
				'package'      => 'in.org.npci.upiapp',
			),
		);
	}

	/**
	 * Build per-app launch links.
	 *
	 * @param array<string,string> $params  UPI params.
	 * @param string[]             $enabled Enabled app IDs.
	 * @return array<string,array{label:string,uri:string,android_only:bool}>
	 */
	public static function app_links( array $params, array $enabled ) {
		$query = self::build_query( $params );
		$links = array();
		foreach ( self::app_definitions() as $id => $def ) {
			if ( ! in_array( $id, $enabled, true ) ) {
				continue;
			}
			if ( ! empty( $def['android_only'] ) ) {
				$uri = 'intent://pay?' . $query . '#Intent;scheme=upi;package=' . $def['package'] . ';end';
			} else {
				$uri = $def['scheme'] . $query;
			}
			$links[ $id ] = array(
				'label'        => $def['label'],
				'uri'          => $uri,
				'android_only' => ! empty( $def['android_only'] ),
			);
		}
		return $links;
	}
}

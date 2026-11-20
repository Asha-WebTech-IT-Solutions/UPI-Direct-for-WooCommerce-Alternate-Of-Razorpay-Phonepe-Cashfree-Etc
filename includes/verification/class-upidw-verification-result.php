<?php
/**
 * Verification result value object.
 *
 * @package UPI_Direct_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Immutable verdict returned by a verifier.
 */
final class UPIDW_Verification_Result {

	const VERIFIED = 'verified';
	const PENDING  = 'pending';
	const FAILED   = 'failed';

	/** @var string */
	public $status;
	/** @var string */
	public $message;
	/** @var string The bank/PSP reference (RRN/UTR) as reported by the trusted source. */
	public $provider_reference;
	/** @var string|null Amount credited, as reported by the trusted source. */
	public $amount;
	/** @var string Currency reported by the source. */
	public $currency;
	/** @var string Our "tr" transaction reference, if the source echoes it (needed for webhooks). */
	public $transaction_ref;

	/**
	 * Constructor.
	 *
	 * @param string      $status             Status constant.
	 * @param string      $message            Human-readable detail.
	 * @param string      $provider_reference Provider reference.
	 * @param string|null $amount             Amount reported.
	 * @param string      $currency           Currency reported.
	 * @param string      $transaction_ref    Our tr value.
	 */
	public function __construct( $status, $message = '', $provider_reference = '', $amount = null, $currency = 'INR', $transaction_ref = '' ) {
		$this->status             = $status;
		$this->message            = (string) $message;
		$this->provider_reference = (string) $provider_reference;
		$this->amount             = $amount;
		$this->currency           = (string) $currency;
		$this->transaction_ref    = (string) $transaction_ref;
	}

	/**
	 * Verified result. Amount is REQUIRED so the service can compare it with the order.
	 *
	 * @param string $amount             Amount credited.
	 * @param string $provider_reference Bank reference.
	 * @param string $transaction_ref    Our tr value.
	 * @param string $currency           Currency.
	 * @return self
	 */
	public static function verified( $amount, $provider_reference = '', $transaction_ref = '', $currency = 'INR' ) {
		return new self( self::VERIFIED, '', $provider_reference, (string) $amount, $currency, $transaction_ref );
	}

	/**
	 * Still waiting.
	 *
	 * @param string $message Detail.
	 * @return self
	 */
	public static function pending( $message = '' ) {
		return new self( self::PENDING, $message );
	}

	/**
	 * Definitively not paid (failed/reversed).
	 *
	 * @param string $message Detail.
	 * @return self
	 */
	public static function failed( $message = '' ) {
		return new self( self::FAILED, $message );
	}

	/** @return bool */
	public function is_verified() {
		return self::VERIFIED === $this->status;
	}

	/** @return bool */
	public function is_pending() {
		return self::PENDING === $this->status;
	}

	/** @return bool */
	public function is_failed() {
		return self::FAILED === $this->status;
	}
}

<?php
/**
 * Verifier registry.
 *
 * @package UPI_Direct_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Holds all registered verifiers. Add-ons register theirs on `upidw_register_verifiers`.
 */
final class UPIDW_Verifier_Registry {

	/** @var UPIDW_Verifier_Interface[] */
	private $verifiers = array();

	/** @var bool */
	private $booted = false;

	/**
	 * Register a verifier.
	 *
	 * @param UPIDW_Verifier_Interface $verifier Verifier.
	 */
	public function register( UPIDW_Verifier_Interface $verifier ) {
		$this->verifiers[ $verifier->get_id() ] = $verifier;
	}

	/**
	 * Lazily register built-ins and let add-ons hook in.
	 */
	private function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;
		$this->register( new UPIDW_Manual_Verifier() );
		/**
		 * Register additional verifiers.
		 *
		 * @param UPIDW_Verifier_Registry $registry Registry.
		 */
		do_action( 'upidw_register_verifiers', $this );
	}

	/**
	 * All verifiers keyed by ID.
	 *
	 * @return UPIDW_Verifier_Interface[]
	 */
	public function all() {
		$this->boot();
		return $this->verifiers;
	}

	/**
	 * One verifier by ID.
	 *
	 * @param string $id Verifier ID.
	 * @return UPIDW_Verifier_Interface|null
	 */
	public function get( $id ) {
		$all = $this->all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * The verifier selected in settings. Falls back to manual if the selection is
	 * missing or not configured, so a misconfigured provider can never auto-approve.
	 *
	 * @return UPIDW_Verifier_Interface
	 */
	public function active() {
		$id       = (string) UPIDW_Settings::get( 'verifier', 'manual' );
		$verifier = $this->get( $id );
		if ( $verifier && $verifier->is_configured() ) {
			return $verifier;
		}
		return $this->get( 'manual' );
	}

	/**
	 * Options for the settings dropdown.
	 *
	 * @return array<string,string>
	 */
	public function options() {
		$options = array();
		foreach ( $this->all() as $id => $verifier ) {
			$options[ $id ] = $verifier->get_label();
		}
		return $options;
	}
}

<?php
/**
 * Plugin bootstrap.
 *
 * @package UPI_Direct_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin together.
 */
final class UPIDW_Plugin {

	/** @var UPIDW_Plugin|null */
	private static $instance = null;

	/** @var UPIDW_Repository */
	public $repository;

	/** @var UPIDW_Verifier_Registry */
	public $verifiers;

	/** @var UPIDW_Payment_Service */
	public $service;

	/**
	 * Singleton accessor.
	 *
	 * @return UPIDW_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boot.
	 */
	public function init() {
		UPIDW_Installer::maybe_upgrade();

		$this->repository = new UPIDW_Repository();
		$this->verifiers  = new UPIDW_Verifier_Registry();
		$this->service    = new UPIDW_Payment_Service( $this->repository, $this->verifiers );
		$this->service->hooks();

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_filter( 'plugin_action_links_' . UPIDW_BASENAME, array( $this, 'action_links' ) );

		( new UPIDW_REST_Controller( $this->service, $this->repository, $this->verifiers ) )->hooks();
		( new UPIDW_Payment_Panel( $this->service ) )->hooks();

		if ( is_admin() ) {
			( new UPIDW_Admin( $this->service, $this->repository ) )->hooks();
		}
	}

	/**
	 * Load translations (needed for non-WordPress.org installs).
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'upi-direct-for-woocommerce', false, dirname( UPIDW_BASENAME ) . '/languages' );
	}

	/**
	 * Add the gateway to WooCommerce.
	 *
	 * @param string[] $gateways Gateway class names.
	 * @return string[]
	 */
	public function register_gateway( $gateways ) {
		$gateways[] = 'UPIDW_Gateway';
		return $gateways;
	}

	/**
	 * "Settings" link on the plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . UPIDW_Settings::GATEWAY_ID );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'upi-direct-for-woocommerce' ) . '</a>' );
		return $links;
	}
}

<?php
/**
 * Plugin Name:          UPI Direct for WooCommerce
 * Plugin URI:           https://github.com/your-org/upi-direct-for-woocommerce
 * Description:          Accept UPI payments directly in WooCommerce with UPI Intent and QR codes. Secure manual or provider-based verification, no middlemen, no tracking.
 * Version:              1.0.0
 * Requires at least:    6.2
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               UPI Direct Contributors
 * Author URI:           https://github.com/your-org/upi-direct-for-woocommerce/graphs/contributors
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          upi-direct-for-woocommerce
 * Domain Path:          /languages
 * WC requires at least: 8.0
 * WC tested up to:      10.0
 *
 * @package UPI_Direct_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'UPIDW_VERSION', '1.0.0' );
define( 'UPIDW_FILE', __FILE__ );
define( 'UPIDW_PATH', plugin_dir_path( __FILE__ ) );
define( 'UPIDW_URL', plugin_dir_url( __FILE__ ) );
define( 'UPIDW_BASENAME', plugin_basename( __FILE__ ) );
define( 'UPIDW_MIN_PHP', '7.4' );

if ( version_compare( PHP_VERSION, UPIDW_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: minimum PHP version. */
						__( 'UPI Direct for WooCommerce requires PHP %s or higher.', 'upi-direct-for-woocommerce' ),
						UPIDW_MIN_PHP
					)
				)
			);
		}
	);
	return;
}

/**
 * Simple class autoloader: UPIDW_Foo_Bar => includes/(sub/)class-upidw-foo-bar.php
 */
spl_autoload_register(
	static function ( $class_name ) {
		if ( 0 !== strpos( $class_name, 'UPIDW_' ) ) {
			return;
		}
		$slug = strtolower( str_replace( '_', '-', $class_name ) );
		$dirs = array( '', 'admin/', 'verification/', 'rest/' );
		foreach ( $dirs as $dir ) {
			foreach ( array( 'class-', 'interface-' ) as $prefix ) {
				$file = UPIDW_PATH . 'includes/' . $dir . $prefix . $slug . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
					return;
				}
			}
		}
	}
);

register_activation_hook( __FILE__, array( 'UPIDW_Installer', 'activate' ) );

/**
 * Declare compatibility with HPOS and the Cart/Checkout Blocks.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', UPIDW_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', UPIDW_FILE, true );
		}
	}
);

/**
 * Register the WooCommerce Blocks checkout integration.
 */
add_action(
	'woocommerce_blocks_loaded',
	static function () {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ) {
				$registry->register( new UPIDW_Blocks_Integration() );
			}
		);
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__( 'UPI Direct for WooCommerce requires WooCommerce to be installed and active.', 'upi-direct-for-woocommerce' )
					);
				}
			);
			return;
		}
		UPIDW_Plugin::instance()->init();
	},
	20
);

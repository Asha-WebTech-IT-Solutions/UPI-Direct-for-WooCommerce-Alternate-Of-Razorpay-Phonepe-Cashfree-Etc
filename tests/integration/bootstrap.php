<?php
/**
 * Bootstrap for integration tests: needs the WordPress test suite and WooCommerce.
 *
 * Install with:
 *   bash bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest
 * and place WooCommerce in $WP_CORE_DIR/wp-content/plugins/woocommerce.
 *
 * @package UPI_Direct_For_WooCommerce
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';

$_polyfills = dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills';
if ( is_dir( $_polyfills ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_polyfills );
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		require dirname( __DIR__, 2 ) . '/upi-direct-for-woocommerce.php';
	}
);

tests_add_filter(
	'setup_theme',
	static function () {
		WC_Install::install();
		UPIDW_Installer::install();
		$GLOBALS['wp_roles'] = null;
		wp_roles();
	}
);

require $_tests_dir . '/includes/bootstrap.php';

<?php
/**
 * Uninstall handler. Removes data only when "Delete data on uninstall" is enabled.
 *
 * @package UPI_Direct_For_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$upidw_settings = get_option( 'woocommerce_upidw_settings', array() );

if ( is_array( $upidw_settings ) && isset( $upidw_settings['delete_data'] ) && 'yes' === $upidw_settings['delete_data'] ) {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}upidw_events" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}upidw_transactions" );
	// phpcs:enable
	delete_option( 'woocommerce_upidw_settings' );
	delete_option( 'upidw_db_version' );
}

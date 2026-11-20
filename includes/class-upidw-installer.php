<?php
/**
 * Database installer.
 *
 * @package UPI_Direct_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin tables.
 */
final class UPIDW_Installer {

	const DB_VERSION = '1';

	/**
	 * Activation callback.
	 */
	public static function activate() {
		self::install();
	}

	/**
	 * Run the installer when the stored DB version is behind.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'upidw_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Create tables.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$tx      = $wpdb->prefix . 'upidw_transactions';
		$ev      = $wpdb->prefix . 'upidw_events';

		// active_reference is NULL unless the reference is "in use" (submitted or verified).
		// A UNIQUE index permits many NULLs, which gives us duplicate-UTR protection for free.
		$sql_tx = "CREATE TABLE {$tx} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  txn_ref varchar(40) NOT NULL,
  amount decimal(14,2) NOT NULL DEFAULT 0.00,
  currency varchar(10) NOT NULL DEFAULT 'INR',
  vpa varchar(255) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'initiated',
  customer_reference varchar(64) DEFAULT NULL,
  active_reference varchar(64) DEFAULT NULL,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  provider varchar(40) NOT NULL DEFAULT '',
  provider_reference varchar(100) DEFAULT NULL,
  verified_by bigint(20) unsigned DEFAULT NULL,
  verified_at datetime DEFAULT NULL,
  submitted_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY txn_ref (txn_ref),
  UNIQUE KEY active_reference (active_reference),
  KEY order_id (order_id),
  KEY status (status)
) {$charset};";

		$sql_ev = "CREATE TABLE {$ev} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  transaction_id bigint(20) unsigned NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  event varchar(40) NOT NULL,
  message text NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY order_id (order_id),
  KEY transaction_id (transaction_id)
) {$charset};";

		dbDelta( $sql_tx );
		dbDelta( $sql_ev );

		update_option( 'upidw_db_version', self::DB_VERSION );
	}
}

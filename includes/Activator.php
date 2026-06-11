<?php
/**
 * Plugin activation / deactivation lifecycle.
 *
 * @package FormPayCM
 */

namespace FormPayCM;

use FormPayCM\Payment\TransactionStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Activator {

	/**
	 * Run on activation: create the transactions table and schedule the
	 * reconciliation sweep that backstops the (single-delivery) webhook.
	 */
	public static function activate() {
		self::create_tables();

		if ( ! wp_next_scheduled( Plugin::CRON_RECONCILE ) ) {
			wp_schedule_event( time() + 300, 'hourly', Plugin::CRON_RECONCILE );
		}

		add_option( 'formpay_cm_db_version', FORMPAY_CM_VERSION );
		flush_rewrite_rules();
	}

	/**
	 * Run on deactivation: clear scheduled events. Data is preserved;
	 * teardown of tables/options belongs in uninstall.php.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( Plugin::CRON_RECONCILE );
		flush_rewrite_rules();
	}

	/**
	 * Create the custom transactions table via dbDelta.
	 */
	private static function create_tables() {
		global $wpdb;

		$table           = $wpdb->prefix . TransactionStore::TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// external_id is our reconciliation anchor; trans_id is Fapshi's.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			external_id VARCHAR(100) NOT NULL,
			trans_id VARCHAR(100) DEFAULT NULL,
			provider VARCHAR(40) NOT NULL DEFAULT 'fapshi',
			source VARCHAR(40) NOT NULL DEFAULT '',
			form_id VARCHAR(100) NOT NULL DEFAULT '',
			entry_id VARCHAR(100) DEFAULT NULL,
			amount INT UNSIGNED NOT NULL,
			currency VARCHAR(8) NOT NULL DEFAULT 'XAF',
			status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
			payer_phone VARCHAR(20) DEFAULT NULL,
			payer_email VARCHAR(190) DEFAULT NULL,
			payment_link TEXT DEFAULT NULL,
			environment VARCHAR(10) NOT NULL DEFAULT 'sandbox',
			meta LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY external_id (external_id),
			KEY trans_id (trans_id),
			KEY status (status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}

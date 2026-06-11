<?php
/**
 * Runs when the plugin is deleted. Removes options + the transactions table.
 *
 * @package FormPayCM
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'formpay_cm_settings' );
delete_option( 'formpay_cm_price_rules' );
delete_option( 'formpay_cm_db_version' );

$table = $wpdb->prefix . 'formpay_cm_transactions';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB

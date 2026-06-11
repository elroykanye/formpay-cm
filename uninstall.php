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

$formpay_cm_table = $wpdb->prefix . 'formpay_cm_transactions';
// Table name is a trusted internal constant; identifiers can't be bound via prepare().
$wpdb->query( "DROP TABLE IF EXISTS {$formpay_cm_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

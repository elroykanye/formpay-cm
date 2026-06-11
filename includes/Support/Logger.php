<?php
/**
 * Minimal logger. Writes via error_log when WP_DEBUG is on; everything
 * routes through here so we can later swap in a DB log table or a settings
 * toggle without touching call sites.
 *
 * @package FormPayCM
 */

namespace FormPayCM\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Logger {

	public static function error( $message, array $context = array() ) {
		self::write( 'ERROR', $message, $context );
	}

	public static function info( $message, array $context = array() ) {
		self::write( 'INFO', $message, $context );
	}

	private static function write( $level, $message, array $context ) {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}
		$line = sprintf( '[FormPayCM][%s] %s', $level, $message );
		if ( $context ) {
			$line .= ' ' . wp_json_encode( $context );
		}
		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

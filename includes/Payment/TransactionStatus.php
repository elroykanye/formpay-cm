<?php
/**
 * Canonical transaction statuses (mirrors Fapshi's vocabulary).
 *
 * @package FormPayCM
 */

namespace FormPayCM\Payment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TransactionStatus {

	const PENDING    = 'PENDING';    // Created locally / awaiting payment.
	const SUCCESSFUL = 'SUCCESSFUL';
	const FAILED     = 'FAILED';
	const EXPIRED    = 'EXPIRED';    // initiate-pay link lapsed (24h).

	/**
	 * Terminal states never transition again — guards against a late/stale
	 * webhook flipping a settled transaction.
	 *
	 * @return string[]
	 */
	public static function terminal() {
		return array( self::SUCCESSFUL, self::FAILED, self::EXPIRED );
	}

	public static function is_terminal( $status ) {
		return in_array( $status, self::terminal(), true );
	}

	public static function is_valid( $status ) {
		return in_array(
			$status,
			array( self::PENDING, self::SUCCESSFUL, self::FAILED, self::EXPIRED ),
			true
		);
	}
}

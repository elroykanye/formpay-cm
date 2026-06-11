<?php
/**
 * Parses the human-friendly text formats used by the textarea-based config
 * surfaces (WPForms builder, central Form Payments screen) into PriceRule
 * data. Kept in one place so every authoring surface behaves identically.
 *
 * @package FormPayCM
 */

namespace FormPayCM\Pricing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RuleParser {

	/**
	 * "medicine:75000" lines → [ 'medicine' => 75000 ].
	 *
	 * @return array<string,int>
	 */
	public static function parse_map( $text ) {
		$map = array();
		foreach ( self::lines( $text ) as $line ) {
			if ( false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $value, $amount ) = array_map( 'trim', explode( ':', $line, 2 ) );
			if ( '' !== $value ) {
				$map[ $value ] = (int) preg_replace( '/\D/', '', $amount );
			}
		}
		return $map;
	}

	/**
	 * "fieldA=valA & fieldB=valB : amount" lines → conditional rules.
	 *
	 * @return array<int,array>
	 */
	public static function parse_conditions( $text ) {
		$rules = array();
		foreach ( self::lines( $text ) as $line ) {
			if ( false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $cond, $amount ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$when = array();
			foreach ( explode( '&', $cond ) as $pair ) {
				if ( false === strpos( $pair, '=' ) ) {
					continue;
				}
				list( $f, $v ) = array_map( 'trim', explode( '=', $pair, 2 ) );
				if ( '' !== $f ) {
					$when[ $f ] = $v;
				}
			}
			if ( $when ) {
				$rules[] = array( 'when' => $when, 'amount' => (int) preg_replace( '/\D/', '', $amount ) );
			}
		}
		return $rules;
	}

	/** Inverse of parse_map, for pre-filling the editor. */
	public static function map_to_text( array $map ) {
		$lines = array();
		foreach ( $map as $value => $amount ) {
			$lines[] = $value . ':' . (int) $amount;
		}
		return implode( "\n", $lines );
	}

	/** Inverse of parse_conditions. */
	public static function conditions_to_text( array $rules ) {
		$lines = array();
		foreach ( $rules as $rule ) {
			$pairs = array();
			foreach ( (array) ( $rule['when'] ?? array() ) as $f => $v ) {
				$pairs[] = $f . '=' . $v;
			}
			$lines[] = implode( ' & ', $pairs ) . ' : ' . (int) ( $rule['amount'] ?? 0 );
		}
		return implode( "\n", $lines );
	}

	private static function lines( $text ) {
		return array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $text ) ), 'strlen' );
	}
}

<?php
/**
 * Resolves the authoritative payable amount from a submission + a PriceRule.
 *
 * Security invariant: for field_map / conditional we look the price up
 * server-side from the option KEY the form submitted — we never accept a
 * price posted by the browser. Only field_value (explicit opt-in) trusts a
 * client-supplied number.
 *
 * @package FormPayCM
 */

namespace FormPayCM\Pricing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PriceResolver {

	const MIN_AMOUNT = 100; // Fapshi minimum, XAF.

	/**
	 * @param PriceRule $rule
	 * @param array     $fields  Submitted values keyed by field id/name.
	 * @return int|\WP_Error     Amount in XAF.
	 */
	public function resolve( PriceRule $rule, array $fields ) {
		switch ( $rule->mode ) {
			case PriceRule::MODE_FIXED:
				$amount = (int) $rule->amount;
				break;

			case PriceRule::MODE_FIELD_MAP:
				$amount = $this->resolve_field_map( $rule, $fields );
				break;

			case PriceRule::MODE_CONDITIONAL:
				$amount = $this->resolve_conditional( $rule, $fields );
				break;

			case PriceRule::MODE_FIELD_VALUE:
				$amount = $this->resolve_field_value( $rule, $fields );
				break;

			default:
				return new \WP_Error( 'formpay_cm_price_mode', __( 'Unknown pricing mode.', 'formpay-cm' ) );
		}

		if ( is_wp_error( $amount ) ) {
			return $amount;
		}

		$amount = (int) $amount;
		if ( $amount < self::MIN_AMOUNT ) {
			return new \WP_Error(
				'formpay_cm_price_min',
				/* translators: %d minimum amount */
				sprintf( __( 'Resolved amount %d is below the minimum of 100 XAF.', 'formpay-cm' ), $amount )
			);
		}

		/**
		 * Final say on the resolved amount, e.g. apply a surcharge or discount.
		 *
		 * @param int       $amount
		 * @param PriceRule $rule
		 * @param array     $fields
		 */
		return (int) apply_filters( 'formpay_cm_resolved_amount', $amount, $rule, $fields );
	}

	private function resolve_field_map( PriceRule $rule, array $fields ) {
		$key = $this->field_value( $rule->field, $fields );
		if ( '' === $key ) {
			return $this->fallback( $rule, __( 'No selection made for the priced field.', 'formpay-cm' ) );
		}
		if ( array_key_exists( $key, $rule->map ) ) {
			return (int) $rule->map[ $key ];
		}
		return $this->fallback( $rule, __( 'No price configured for the selected option.', 'formpay-cm' ) );
	}

	private function resolve_conditional( PriceRule $rule, array $fields ) {
		foreach ( $rule->rules as $entry ) {
			$when = isset( $entry['when'] ) && is_array( $entry['when'] ) ? $entry['when'] : array();
			if ( $this->matches( $when, $fields ) ) {
				return (int) $entry['amount'];
			}
		}
		return $this->fallback( $rule, __( 'No pricing rule matched this submission.', 'formpay-cm' ) );
	}

	private function resolve_field_value( PriceRule $rule, array $fields ) {
		// Trusts client input by design — only reachable when the admin chose
		// this mode (e.g. a donation "enter amount" field).
		$raw = $this->field_value( $rule->field, $fields );
		return (int) preg_replace( '/[^\d]/', '', $raw );
	}

	/**
	 * Every key in $when must equal the submitted value (loose string compare).
	 */
	private function matches( array $when, array $fields ) {
		foreach ( $when as $field => $expected ) {
			if ( (string) $this->field_value( $field, $fields ) !== (string) $expected ) {
				return false;
			}
		}
		return ! empty( $when );
	}

	private function fallback( PriceRule $rule, $message ) {
		if ( null !== $rule->default ) {
			return (int) $rule->default;
		}
		return new \WP_Error( 'formpay_cm_price_unresolved', $message );
	}

	/**
	 * Read a single submitted field, normalised to a scalar string.
	 */
	private function field_value( $field, array $fields ) {
		if ( '' === (string) $field || ! isset( $fields[ $field ] ) ) {
			return '';
		}
		$value = $fields[ $field ];
		if ( is_array( $value ) ) {
			$value = reset( $value ); // first selected option for multi-value fields
		}
		return trim( (string) $value );
	}
}

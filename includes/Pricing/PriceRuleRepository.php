<?php
/**
 * Stores/retrieves a PriceRule per form, keyed by "source:form_id"
 * (e.g. "elementor:contact-1", "wpforms:42").
 *
 * Backed by a single option for now. Each form plugin's native settings UI
 * can write here, so authoring location and lookup stay decoupled — that's
 * what makes the pricing flexible across all three plugins.
 *
 * @package FormPayCM
 */

namespace FormPayCM\Pricing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PriceRuleRepository {

	const OPTION = 'formpay_cm_price_rules';

	private function key( $source, $form_id ) {
		return $source . ':' . $form_id;
	}

	/**
	 * @return PriceRule|null Null when this form has no payment config.
	 */
	public function get( $source, $form_id ) {
		$all = get_option( self::OPTION, array() );
		$key = $this->key( $source, $form_id );
		if ( empty( $all[ $key ] ) || ! is_array( $all[ $key ] ) ) {
			return null;
		}
		return PriceRule::from_array( $all[ $key ] );
	}

	public function save( $source, $form_id, PriceRule $rule ) {
		$all = get_option( self::OPTION, array() );
		$all[ $this->key( $source, $form_id ) ] = $rule->to_array();
		update_option( self::OPTION, $all );
	}

	public function delete( $source, $form_id ) {
		$all = get_option( self::OPTION, array() );
		unset( $all[ $this->key( $source, $form_id ) ] );
		update_option( self::OPTION, $all );
	}
}

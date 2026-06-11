<?php
/**
 * A form's pricing configuration — how to derive the payable amount from a
 * submission. This is the heart of FormPay CM's flexibility: the amount is
 * never a fixed form setting, it's resolved from submitted field values.
 *
 * Modes:
 *   fixed        Always `amount`.
 *   field_map    Look up submitted[`field`] in `map` (option key => amount).
 *   conditional  First rule in `rules` whose `when` all match wins.
 *   field_value  submitted[`field`] IS the amount (opt-in; trusts client input).
 *
 * @package FormPayCM
 */

namespace FormPayCM\Pricing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PriceRule {

	const MODE_FIXED       = 'fixed';
	const MODE_FIELD_MAP   = 'field_map';
	const MODE_CONDITIONAL = 'conditional';
	const MODE_FIELD_VALUE = 'field_value';

	/** @var string */
	public $mode = self::MODE_FIXED;

	/** @var int Amount for `fixed`. */
	public $amount = 0;

	/** @var string Selector/value field key for map/value modes. */
	public $field = '';

	/** @var array<string,int> option key => amount, for `field_map`. */
	public $map = array();

	/**
	 * For `conditional`: ordered list of
	 *   [ 'when' => [ fieldKey => expectedValue, ... ], 'amount' => int ]
	 * First rule whose `when` entries all match the submission wins.
	 *
	 * @var array<int,array>
	 */
	public $rules = array();

	/** @var int|null Fallback when nothing matches (null => error). */
	public $default = null;

	/** @var string Currency. Cameroon = XAF. */
	public $currency = 'XAF';

	/**
	 * Which submitted field carries the payer phone / email (optional, used to
	 * prefill Fapshi checkout). Field keys, not values.
	 *
	 * @var array{phone?:string,email?:string}
	 */
	public $payer_fields = array();

	public static function from_array( array $data ) {
		$rule               = new self();
		$rule->mode         = isset( $data['mode'] ) ? (string) $data['mode'] : self::MODE_FIXED;
		$rule->amount       = isset( $data['amount'] ) ? (int) $data['amount'] : 0;
		$rule->field        = isset( $data['field'] ) ? (string) $data['field'] : '';
		$rule->map          = isset( $data['map'] ) && is_array( $data['map'] ) ? array_map( 'intval', $data['map'] ) : array();
		$rule->rules        = isset( $data['rules'] ) && is_array( $data['rules'] ) ? $data['rules'] : array();
		$rule->default      = isset( $data['default'] ) && '' !== $data['default'] ? (int) $data['default'] : null;
		$rule->currency     = isset( $data['currency'] ) ? (string) $data['currency'] : 'XAF';
		$rule->payer_fields = isset( $data['payer_fields'] ) && is_array( $data['payer_fields'] ) ? $data['payer_fields'] : array();
		return $rule;
	}

	public function to_array() {
		return array(
			'mode'         => $this->mode,
			'amount'       => $this->amount,
			'field'        => $this->field,
			'map'          => $this->map,
			'rules'        => $this->rules,
			'default'      => $this->default,
			'currency'     => $this->currency,
			'payer_fields' => $this->payer_fields,
		);
	}
}

<?php
/**
 * Provider-agnostic payment request DTO.
 *
 * Built by a form adapter from server-side form config + submitted fields.
 * The amount is ALWAYS sourced from trusted server-side config, never from
 * the browser POST.
 *
 * @package FormPayCM
 */

namespace FormPayCM\Payment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PaymentRequest {

	/** @var int Amount in XAF (integer, min 100). */
	public $amount;

	/** @var string Currency code. Cameroon = XAF. */
	public $currency = 'XAF';

	/** @var string Our reconciliation anchor (WP entry/order ref). */
	public $external_id;

	/** @var string Which adapter produced this (elementor|metform|wpforms). */
	public $source = '';

	/** @var string Form identifier within that plugin. */
	public $form_id = '';

	/** @var string|null Submission/entry identifier, if available. */
	public $entry_id = null;

	/** @var string|null Payer phone (67XXXXXXX) — only needed for direct-pay. */
	public $phone = null;

	/** @var string|null Payer email (prefills Fapshi checkout, used on receipt). */
	public $email = null;

	/** @var string|null Human-readable reason shown to the payer. */
	public $message = null;

	/** @var string|null Where Fapshi sends the payer after checkout. */
	public $redirect_url = null;

	/** @var array Arbitrary extra context persisted with the transaction. */
	public $meta = array();

	/**
	 * Validate the request before it reaches a gateway.
	 *
	 * @return true|\WP_Error
	 */
	public function validate() {
		$amount = (int) $this->amount;
		if ( $amount < 100 ) {
			return new \WP_Error(
				'formpay_cm_amount',
				__( 'Amount must be at least 100 XAF.', 'formpay-cm' )
			);
		}
		if ( empty( $this->external_id ) ) {
			return new \WP_Error(
				'formpay_cm_external_id',
				__( 'A unique reference (external_id) is required.', 'formpay-cm' )
			);
		}
		return true;
	}
}

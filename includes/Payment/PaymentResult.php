<?php
/**
 * Result of initiating a payment with a gateway.
 *
 * @package FormPayCM
 */

namespace FormPayCM\Payment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PaymentResult {

	/** @var string Provider transaction id (Fapshi transId). */
	public $trans_id;

	/** @var string|null Hosted checkout URL to redirect the payer to. */
	public $payment_link = null;

	/** @var string Current status (usually PENDING right after initiate). */
	public $status = TransactionStatus::PENDING;

	/** @var array Raw provider response, for debugging/audit. */
	public $raw = array();

	public function __construct( $trans_id, $payment_link = null ) {
		$this->trans_id     = $trans_id;
		$this->payment_link = $payment_link;
	}
}

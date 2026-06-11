<?php
/**
 * Contract every payment provider implements. Keeps the engine
 * provider-agnostic so additional gateways drop in without touching core.
 *
 * @package FormPayCM
 */

namespace FormPayCM\Payment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface GatewayInterface {

	/**
	 * Unique slug, e.g. 'fapshi'.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Begin a payment. For Fapshi this is the initiate-pay (hosted redirect)
	 * call; returns a PaymentResult carrying the checkout link + transId.
	 *
	 * @param PaymentRequest $request
	 * @return PaymentResult|\WP_Error
	 */
	public function initiate( PaymentRequest $request );

	/**
	 * Fetch the authoritative status of a transaction from the provider.
	 * Used by the webhook handler and the reconciliation sweep.
	 *
	 * @param string $trans_id
	 * @return array|\WP_Error  Normalised: ['status' => ..., 'amount' => ..., 'raw' => [...]]
	 */
	public function fetch_status( $trans_id );
}

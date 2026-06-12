<?php
/**
 * Contract every payment provider implements. Keeps the engine
 * provider-agnostic so additional operators drop in without touching core.
 *
 * @package FormPayCM
 */

namespace FormPayCM\Payment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface GatewayInterface {

	/**
	 * Unique slug, e.g. 'fapshi'. Used as the provider key everywhere.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Human-readable name shown in the admin, e.g. 'Fapshi'.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Active environment for this gateway, e.g. 'sandbox' or 'live'.
	 *
	 * @return string
	 */
	public function environment();

	/**
	 * Declarative list of credential fields this provider needs, so the
	 * settings screen can render itself without knowing the provider:
	 *
	 *   [ 'api_key' => [ 'label' => 'API Key', 'type' => 'password',
	 *                    'description' => '...' ], ... ]
	 *
	 * @return array<string,array>
	 */
	public function config_fields();

	/**
	 * Begin a payment. Returns a PaymentResult carrying the checkout redirect
	 * and the provider transaction id.
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

	/**
	 * Authenticate an incoming webhook request for this provider (signature,
	 * shared secret, IP allow-list — whatever the provider uses).
	 *
	 * @param \WP_REST_Request $request
	 * @return bool
	 */
	public function verify_webhook( \WP_REST_Request $request );

	/**
	 * Pull the provider transaction id out of a webhook payload, so the engine
	 * can re-query the authoritative status.
	 *
	 * @param \WP_REST_Request $request
	 * @return string Empty string when none is present.
	 */
	public function extract_trans_id( \WP_REST_Request $request );
}

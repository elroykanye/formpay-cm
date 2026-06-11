<?php
/**
 * Fapshi webhook receiver.
 *
 * Fapshi POSTs the same body as payment-status on SUCCESSFUL / FAILED /
 * EXPIRED, with an x-wh-secret header when a secret is configured. We verify
 * the secret, then RE-QUERY payment-status as the source of truth rather than
 * trusting the webhook body — and always answer 200 quickly (single delivery).
 *
 * Route: POST /wp-json/formpay-cm/v1/webhook/fapshi
 *
 * @package FormPayCM
 */

namespace FormPayCM\Http;

use FormPayCM\Admin\Settings;
use FormPayCM\Payment\PaymentManager;
use FormPayCM\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WebhookController {

	/** @var PaymentManager */
	private $payments;

	public function __construct( PaymentManager $payments ) {
		$this->payments = $payments;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			'formpay-cm/v1',
			'/webhook/fapshi',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true', // auth handled via x-wh-secret below
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ) {
		// 1. Verify the shared secret, if one is configured.
		$expected = Settings::get_webhook_secret();
		if ( $expected ) {
			$provided = $request->get_header( 'x-wh-secret' );
			if ( ! $provided || ! hash_equals( $expected, $provided ) ) {
				Logger::error( 'Webhook secret mismatch' );
				// 200 so Fapshi doesn't treat it as a delivery failure on a spoofed call,
				// but do nothing. (Return 401 instead if you prefer hard rejection.)
				return new \WP_REST_Response( array( 'ok' => false ), 200 );
			}
		}

		$body     = $request->get_json_params();
		$trans_id = is_array( $body ) && ! empty( $body['transId'] ) ? sanitize_text_field( $body['transId'] ) : '';

		if ( ! $trans_id ) {
			return new \WP_REST_Response( array( 'ok' => false, 'reason' => 'no transId' ), 200 );
		}

		// 2. Don't trust the body — re-query the authoritative status.
		$result = $this->payments->sync_from_provider( $trans_id );
		if ( is_wp_error( $result ) ) {
			Logger::error( 'Webhook sync failed', array( 'transId' => $trans_id, 'error' => $result->get_error_message() ) );
		}

		// 3. Always 200 fast — Fapshi delivers each event only once.
		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}
}

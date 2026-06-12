<?php
/**
 * Provider-agnostic webhook receiver.
 *
 * One route, /wp-json/formpay-cm/v1/webhook/{provider}, dispatches to the
 * matching gateway: the gateway verifies authenticity and extracts its
 * transaction id, then the engine RE-QUERIES the authoritative status rather
 * than trusting the payload. We always answer 200 quickly, since providers
 * commonly deliver each event only once.
 *
 * @package FormPayCM
 */

namespace FormPayCM\Http;

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
			'/webhook/(?P<provider>[a-z0-9_-]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true', // auth handled per-gateway below
				'args'                => array(
					'provider' => array(
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ) {
		$provider = sanitize_key( $request->get_param( 'provider' ) );

		$gateway = $this->payments->gateway_for( $provider );
		if ( is_wp_error( $gateway ) ) {
			return new \WP_REST_Response(
				array(
					'ok'     => false,
					'reason' => 'unknown provider',
				),
				404
			);
		}

		// 1. Let the gateway authenticate the request (signature / secret / etc.).
		if ( ! $gateway->verify_webhook( $request ) ) {
			Logger::error( 'Webhook verification failed', array( 'provider' => $provider ) );
			// 200 so the provider doesn't keep retrying a request we reject.
			return new \WP_REST_Response( array( 'ok' => false ), 200 );
		}

		// 2. Pull the provider transaction id from the payload.
		$trans_id = $gateway->extract_trans_id( $request );
		if ( '' === $trans_id ) {
			return new \WP_REST_Response(
				array(
					'ok'     => false,
					'reason' => 'no transaction id',
				),
				200
			);
		}

		// 3. Don't trust the body — re-query the authoritative status.
		$result = $this->payments->sync_from_provider( $trans_id );
		if ( is_wp_error( $result ) ) {
			Logger::error(
				'Webhook sync failed',
				array(
					'provider' => $provider,
					'trans_id' => $trans_id,
					'error'    => $result->get_error_message(),
				)
			);
		}

		// 4. Always 200 fast — many providers deliver each event only once.
		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}
}

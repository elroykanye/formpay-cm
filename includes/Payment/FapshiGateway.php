<?php
/**
 * Fapshi gateway (Cameroon — MTN MoMo + Orange Money, XAF).
 *
 * v1 uses initiate-pay (hosted redirect checkout), which is enabled by
 * default on live and handles operator selection + receipts for us.
 * direct-pay can be added later as an advanced embedded mode.
 *
 * Docs: https://docs.fapshi.com/en/api-reference/endpoint/initiate-pay.md
 *
 * @package FormPayCM
 */

namespace FormPayCM\Payment;

use FormPayCM\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FapshiGateway extends AbstractGateway {

	const BASE_SANDBOX = 'https://sandbox.fapshi.com';
	const BASE_LIVE    = 'https://live.fapshi.com';

	public function id() {
		return 'fapshi';
	}

	public function label() {
		return __( 'Fapshi (Cameroon)', 'formpay-cm' );
	}

	/**
	 * Credential fields rendered by the settings screen.
	 *
	 * @return array<string,array>
	 */
	public function config_fields() {
		return array(
			'api_user'       => array(
				'label' => __( 'API User', 'formpay-cm' ),
				'type'  => 'text',
			),
			'api_key'        => array(
				'label' => __( 'API Key', 'formpay-cm' ),
				'type'  => 'password',
			),
			'webhook_secret' => array(
				'label'       => __( 'Webhook Secret', 'formpay-cm' ),
				'type'        => 'password',
				'description' => __( 'Set the same value as the webhook secret in your Fapshi dashboard. Fapshi cannot display an existing secret, so keep this in sync yourself.', 'formpay-cm' ),
			),
		);
	}

	private function base_url() {
		return 'live' === $this->environment() ? self::BASE_LIVE : self::BASE_SANDBOX;
	}

	/**
	 * initiate-pay: returns a Fapshi-hosted checkout link + transId.
	 *
	 * @param PaymentRequest $request
	 * @return PaymentResult|\WP_Error
	 */
	public function initiate( PaymentRequest $request ) {
		$valid = $request->validate();
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$body = array(
			'amount'     => (int) $request->amount,       // integer, min 100 XAF
			'externalId' => $request->external_id,        // our reconciliation anchor
		);
		if ( $request->email ) {
			$body['email'] = $request->email;
		}
		if ( $request->redirect_url ) {
			$body['redirectUrl'] = $request->redirect_url;
		}
		if ( $request->message ) {
			$body['message'] = $request->message;
		}

		$response = $this->post( '/initiate-pay', $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['transId'] ) || empty( $response['link'] ) ) {
			return new \WP_Error(
				'formpay_cm_fapshi_initiate',
				__( 'Fapshi did not return a payment link.', 'formpay-cm' ),
				$response
			);
		}

		$result      = new PaymentResult( $response['transId'], $response['link'] );
		$result->raw = $response;
		return $result;
	}

	/**
	 * payment-status: authoritative status lookup for a transId.
	 *
	 * @param string $trans_id
	 * @return array|\WP_Error
	 */
	public function fetch_status( $trans_id ) {
		$response = $this->get( '/payment-status/' . rawurlencode( $trans_id ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = isset( $response['status'] ) ? strtoupper( $response['status'] ) : '';
		if ( ! TransactionStatus::is_valid( $status ) ) {
			return new \WP_Error(
				'formpay_cm_fapshi_status',
				__( 'Unrecognised status from Fapshi.', 'formpay-cm' ),
				$response
			);
		}

		return array(
			'status' => $status,
			'amount' => isset( $response['amount'] ) ? (int) $response['amount'] : null,
			'raw'    => $response,
		);
	}

	/**
	 * Fapshi sends an x-wh-secret header when a secret is configured.
	 *
	 * @param \WP_REST_Request $request
	 * @return bool
	 */
	public function verify_webhook( \WP_REST_Request $request ) {
		$expected = (string) $this->config( 'webhook_secret', '' );
		if ( '' === $expected ) {
			return true; // No secret configured — nothing to verify against.
		}
		$provided = (string) $request->get_header( 'x-wh-secret' );
		return '' !== $provided && hash_equals( $expected, $provided );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return string
	 */
	public function extract_trans_id( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		return is_array( $body ) && ! empty( $body['transId'] ) ? sanitize_text_field( $body['transId'] ) : '';
	}

	// --- HTTP helpers ---

	private function headers() {
		return array(
			'apiuser'      => (string) $this->config( 'api_user', '' ),
			'apikey'       => (string) $this->config( 'api_key', '' ),
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);
	}

	private function post( $path, array $body ) {
		return $this->request( 'POST', $path, $body );
	}

	private function get( $path ) {
		return $this->request( 'GET', $path, null );
	}

	/**
	 * @param string     $method
	 * @param string     $path
	 * @param array|null $body
	 * @return array|\WP_Error Decoded JSON body on 2xx, WP_Error otherwise.
	 */
	private function request( $method, $path, $body ) {
		if ( '' === (string) $this->config( 'api_user', '' ) || '' === (string) $this->config( 'api_key', '' ) ) {
			return new \WP_Error(
				'formpay_cm_no_credentials',
				__( 'Fapshi API credentials are not configured.', 'formpay-cm' )
			);
		}

		$args = array(
			'method'  => $method,
			'headers' => $this->headers(),
			'timeout' => 30,
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->base_url() . $path, $args );

		if ( is_wp_error( $response ) ) {
			Logger::error(
				'Fapshi request transport error',
				array(
					'path'  => $path,
					'error' => $response->get_error_message(),
				)
			);
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $json ) && isset( $json['message'] ) ? $json['message'] : __( 'Fapshi request failed.', 'formpay-cm' );
			Logger::error(
				'Fapshi request error',
				array(
					'path' => $path,
					'code' => $code,
					'body' => $json,
				)
			);
			return new \WP_Error(
				'formpay_cm_fapshi_http',
				$msg,
				array(
					'code' => $code,
					'body' => $json,
				)
			);
		}

		return is_array( $json ) ? $json : array();
	}
}

<?php
/**
 * Shared base for payment gateways: holds the config slice and provides
 * sensible defaults so a concrete gateway only implements what it must.
 *
 * @package FormPayCM
 */

namespace FormPayCM\Payment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class AbstractGateway implements GatewayInterface {

	/**
	 * Provider config slice (its declared fields plus the common
	 * 'environment' key), as supplied by Settings.
	 *
	 * @var array
	 */
	protected $config;

	public function __construct( array $config = array() ) {
		$this->config = $config;
	}

	/**
	 * Read a config value with a fallback.
	 *
	 * @param string $key
	 * @param mixed  $fallback
	 * @return mixed
	 */
	protected function config( $key, $fallback = '' ) {
		return isset( $this->config[ $key ] ) && '' !== $this->config[ $key ] ? $this->config[ $key ] : $fallback;
	}

	public function environment() {
		return 'live' === $this->config( 'environment', 'sandbox' ) ? 'live' : 'sandbox';
	}

	/**
	 * Default: no provider-specific credential fields.
	 *
	 * @return array<string,array>
	 */
	public function config_fields() {
		return array();
	}

	/**
	 * Default: accept the webhook. Providers with a secret/signature override.
	 *
	 * @param \WP_REST_Request $request
	 * @return bool
	 */
	public function verify_webhook( \WP_REST_Request $request ) {
		return true;
	}
}

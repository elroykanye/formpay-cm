<?php
/**
 * Registry of available payment gateways. This is what makes FormPay CM
 * provider-agnostic: gateways are registered via the `formpay_cm_gateways`
 * filter, so additional operators are added without changing the engine.
 *
 *   add_filter( 'formpay_cm_gateways', function ( $gateways ) {
 *       $gateways['myprovider'] = \My\Provider\Gateway::class;
 *       return $gateways;
 *   } );
 *
 * @package FormPayCM
 */

namespace FormPayCM\Payment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GatewayRegistry {

	/**
	 * Map of provider id => gateway class name.
	 *
	 * @return array<string,string>
	 */
	public function classes() {
		$gateways = array(
			'fapshi' => FapshiGateway::class,
		);

		/**
		 * Register additional payment gateways.
		 *
		 * @param array<string,string> $gateways provider id => class implementing GatewayInterface.
		 */
		$gateways = apply_filters( 'formpay_cm_gateways', $gateways );

		// Keep only valid, instantiable GatewayInterface implementations.
		return array_filter(
			$gateways,
			static function ( $gateway_class ) {
				return is_string( $gateway_class ) && class_exists( $gateway_class ) && is_subclass_of( $gateway_class, GatewayInterface::class );
			}
		);
	}

	/**
	 * @return string[] Registered provider ids.
	 */
	public function ids() {
		return array_keys( $this->classes() );
	}

	public function has( $id ) {
		$classes = $this->classes();
		return isset( $classes[ $id ] );
	}

	/**
	 * Provider id => human label, for select boxes.
	 *
	 * @return array<string,string>
	 */
	public function labels() {
		$labels = array();
		foreach ( $this->classes() as $id => $class ) {
			$labels[ $id ] = ( new $class() )->label();
		}
		return $labels;
	}

	/**
	 * Instantiate a gateway by id with its config.
	 *
	 * @param string $id
	 * @param array  $config
	 * @return GatewayInterface|null Null when the provider is not registered.
	 */
	public function make( $id, array $config = array() ) {
		$classes = $this->classes();
		if ( ! isset( $classes[ $id ] ) ) {
			return null;
		}
		$class = $classes[ $id ];
		return new $class( $config );
	}

	/**
	 * The default provider id (first registered).
	 *
	 * @return string
	 */
	public function default_id() {
		$ids = $this->ids();
		return $ids ? $ids[0] : 'fapshi';
	}
}

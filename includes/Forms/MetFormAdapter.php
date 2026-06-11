<?php
/**
 * MetForm adapter — fully integrated, grounded in MetForm's real submission
 * flow (core/entries/action.php):
 *
 *   do_action('metform_after_store_form_data', $form_id, $form_data,
 *             $form_settings, $attributes);
 *   $response->data['redirect_to'] = $form_settings['redirect_to'];  // MetForm redirects here
 *
 * MetForm redirects the browser to its native "Confirmation → Redirect To"
 * URL, and third-party code can't mutate the AJAX response object from the
 * action hook. So, exactly like MetForm's own PayPal flow (which redirects to
 * a REST URL that then forwards to PayPal), we:
 *
 *   1. In the hook: resolve amount, start the Fapshi payment, and stash the
 *      checkout link in a short-lived transient keyed by a per-visitor token
 *      (also dropped as a cookie on the same AJAX response).
 *   2. Have the site owner point MetForm's "Redirect To" at our bridge
 *      endpoint (home_url('/?formpay_cm_pay=1')), which reads the token and
 *      302s the browser to the Fapshi checkout.
 *
 * MetForm can't host our config UI inside its Elementor widget, so its pricing
 * rules are authored in the central "Form Payments" admin screen and looked up
 * here via PriceRuleRepository (source = "metform").
 *
 * @package FormPayCM
 */

namespace FormPayCM\Forms;

use FormPayCM\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MetFormAdapter extends AbstractFormAdapter {

	const COOKIE       = 'formpay_cm_pay_token';
	const TRANSIENT    = 'formpay_cm_link_';
	const TOKEN_TTL    = 1800; // 30 minutes

	public function id() {
		return 'metform';
	}

	public function is_available() {
		return defined( 'METFORM_VERSION' ) || class_exists( '\MetForm\Plugin' );
	}

	public function register() {
		add_action( 'metform_after_store_form_data', array( $this, 'on_store' ), 10, 4 );
		add_action( 'init', array( $this, 'maybe_handle_bridge' ) );
	}

	/**
	 * Fires after MetForm stores the entry.
	 *
	 * @param int|string $form_id
	 * @param array      $form_data      Submitted values keyed by input name.
	 * @param array      $form_settings
	 * @param mixed      $attributes
	 */
	public function on_store( $form_id, $form_data, $form_settings = array(), $attributes = null ) {
		$fields  = is_array( $form_data ) ? $form_data : array();
		$context = array(
			'entry_id'     => null,
			'redirect_url' => home_url( '/' ), // post-payment return; Fapshi redirect target
			'message'      => isset( $form_settings['form_title'] ) ? $form_settings['form_title'] : __( 'Payment', 'formpay-cm' ),
		);

		// Pricing rule is authored centrally for MetForm.
		$result = $this->process_submission( (string) $form_id, $fields, $context );

		if ( null === $result ) {
			return; // form not configured for payment
		}

		if ( is_wp_error( $result ) ) {
			Logger::error( 'MetForm payment start failed', array( 'form' => $form_id, 'error' => $result->get_error_message() ) );
			return;
		}

		// Stash the checkout link against a per-visitor token for the bridge.
		$token = wp_generate_uuid4();
		set_transient( self::TRANSIENT . $token, $result['redirect'], self::TOKEN_TTL );
		$this->set_token_cookie( $token );
	}

	/**
	 * Bridge endpoint: home_url('/?formpay_cm_pay=1'). Reads the cookie token,
	 * resolves the stored Fapshi link, and forwards the browser to checkout.
	 */
	public function maybe_handle_bridge() {
		if ( empty( $_GET['formpay_cm_pay'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$token = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		$link  = $token ? get_transient( self::TRANSIENT . $token ) : false;

		// One-shot: clear so a stale token can't be replayed.
		if ( $token ) {
			delete_transient( self::TRANSIENT . $token );
			$this->clear_token_cookie();
		}

		if ( $link ) {
			wp_redirect( $link ); // phpcs:ignore WordPress.Security.SafeRedirect — external provider URL by design
			exit;
		}

		// Nothing pending — send home with a notice rather than a blank page.
		wp_safe_redirect( add_query_arg( 'formpay_cm', 'no_pending_payment', home_url( '/' ) ) );
		exit;
	}

	private function set_token_cookie( $token ) {
		$params = array(
			'expires'  => time() + self::TOKEN_TTL,
			'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);
		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie( self::COOKIE, $token, $params );
		} else {
			setcookie( self::COOKIE, $token, $params['expires'], $params['path'] . '; samesite=Lax', '', $params['secure'], true );
		}
	}

	private function clear_token_cookie() {
		$path = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
		setcookie( self::COOKIE, '', time() - 3600, $path );
	}
}

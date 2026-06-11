<?php
/**
 * Shared helpers for form adapters: building a PaymentRequest and handing
 * off to the engine. Keeps the per-plugin adapters focused on field mapping.
 *
 * @package FormPayCM
 */

namespace FormPayCM\Forms;

use FormPayCM\Payment\PaymentManager;
use FormPayCM\Payment\PaymentRequest;
use FormPayCM\Pricing\PriceResolver;
use FormPayCM\Pricing\PriceRuleRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class AbstractFormAdapter implements FormAdapterInterface {

	/** @var PaymentManager */
	protected $payments;

	/** @var PriceRuleRepository */
	protected $rules;

	/** @var PriceResolver */
	protected $resolver;

	public function __construct( PaymentManager $payments, PriceRuleRepository $rules = null, PriceResolver $resolver = null ) {
		$this->payments = $payments;
		$this->rules    = $rules ?: new PriceRuleRepository();
		$this->resolver = $resolver ?: new PriceResolver();
	}

	/**
	 * The full submission → payment pipeline shared by every adapter:
	 *   1. load this form's PriceRule (skip silently if none configured)
	 *   2. resolve the authoritative amount from submitted fields
	 *   3. build the PaymentRequest and start the payment
	 *
	 * @param string $form_id  Form identifier within the source plugin.
	 * @param array  $fields   Submitted values keyed by field id/name.
	 * @param array  $context  entry_id, redirect_url, meta (optional).
	 * @return array|\WP_Error|null  ['transaction'=>, 'redirect'=>], error, or
	 *                               null when the form has no payment config.
	 */
	protected function process_submission( $form_id, array $fields, array $context = array() ) {
		$rule = $this->rules->get( $this->id(), $form_id );
		if ( ! $rule ) {
			return null; // This form isn't set up to collect payment — no-op.
		}
		return $this->process_with_rule( $form_id, $fields, $rule, $context );
	}

	/**
	 * Same pipeline, but with a PriceRule supplied directly (e.g. authored in
	 * the Elementor editor) rather than loaded from the repository.
	 *
	 * @param string         $form_id
	 * @param array          $fields
	 * @param \FormPayCM\Pricing\PriceRule $rule
	 * @param array          $context
	 * @return array|\WP_Error
	 */
	protected function process_with_rule( $form_id, array $fields, $rule, array $context = array() ) {
		$amount = $this->resolver->resolve( $rule, $fields );
		if ( is_wp_error( $amount ) ) {
			return $amount;
		}

		$request = $this->build_request(
			array(
				'amount'       => $amount,
				'currency'     => $rule->currency,
				'form_id'      => $form_id,
				'entry_id'     => isset( $context['entry_id'] ) ? $context['entry_id'] : null,
				'email'        => $this->pick_payer_field( $rule, 'email', $fields ),
				'phone'        => $this->pick_payer_field( $rule, 'phone', $fields ),
				'message'      => isset( $context['message'] ) ? $context['message'] : '',
				'redirect_url' => isset( $context['redirect_url'] ) ? $context['redirect_url'] : null,
				'meta'         => isset( $context['meta'] ) ? $context['meta'] : array(),
			)
		);

		return $this->payments->start( $request );
	}

	/**
	 * Pull a payer detail (email/phone) from the submission using the field
	 * key the admin mapped in the PriceRule.
	 */
	protected function pick_payer_field( $rule, $which, array $fields ) {
		$key = isset( $rule->payer_fields[ $which ] ) ? $rule->payer_fields[ $which ] : '';
		return ( $key && isset( $fields[ $key ] ) ) ? $fields[ $key ] : null;
	}

	/**
	 * Build a normalised PaymentRequest from already-extracted values.
	 *
	 * IMPORTANT: $amount must come from server-side form config, never from
	 * a value the browser could tamper with.
	 *
	 * @param array $args amount, form_id, entry_id, email, phone, message
	 * @return PaymentRequest
	 */
	protected function build_request( array $args ) {
		$req               = new PaymentRequest();
		$req->source       = $this->id();
		$req->amount       = isset( $args['amount'] ) ? (int) $args['amount'] : 0;
		$req->currency     = isset( $args['currency'] ) ? (string) $args['currency'] : 'XAF';
		$req->form_id      = isset( $args['form_id'] ) ? (string) $args['form_id'] : '';
		$req->entry_id     = isset( $args['entry_id'] ) ? (string) $args['entry_id'] : null;
		$req->email        = isset( $args['email'] ) ? sanitize_email( $args['email'] ) : null;
		$req->phone        = isset( $args['phone'] ) ? preg_replace( '/\D/', '', $args['phone'] ) : null;
		$req->message      = isset( $args['message'] ) ? sanitize_text_field( $args['message'] ) : null;
		$req->external_id  = $this->make_external_id( $req->form_id, $req->entry_id );
		$req->redirect_url = isset( $args['redirect_url'] ) ? esc_url_raw( $args['redirect_url'] ) : home_url( '/' );
		$req->meta         = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();
		return $req;
	}

	/**
	 * Deterministic, unique reconciliation reference. Stable per submission
	 * so retries map to the same transaction (idempotency).
	 */
	protected function make_external_id( $form_id, $entry_id ) {
		$entry = $entry_id ?: wp_generate_uuid4();
		return substr( sprintf( '%s-%s-%s', $this->id(), $form_id, $entry ), 0, 100 );
	}

	/**
	 * Hand off to the engine and return the checkout redirect URL (or WP_Error).
	 *
	 * @param PaymentRequest $request
	 * @return string|\WP_Error
	 */
	protected function start_payment( PaymentRequest $request ) {
		$result = $this->payments->start( $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $result['redirect'];
	}
}

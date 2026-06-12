<?php
/**
 * The provider-agnostic payment engine. Adapters call start(); the webhook
 * and cron sweep call sync_from_provider() / reconcile_pending().
 *
 * The engine never names a specific operator: gateways come from the
 * GatewayRegistry, the active one from settings, and in-flight transactions
 * are always reconciled against the provider stored on the transaction.
 *
 * @package FormPayCM
 */

namespace FormPayCM\Payment;

use FormPayCM\Admin\Settings;
use FormPayCM\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PaymentManager {

	/** @var TransactionStore */
	private $store;

	/** @var GatewayRegistry */
	private $registry;

	/** @var GatewayInterface|null Optional override (mainly for tests). */
	private $gateway_override;

	public function __construct( TransactionStore $store = null, GatewayRegistry $registry = null, GatewayInterface $gateway_override = null ) {
		$this->store            = $store ?? new TransactionStore();
		$this->registry         = $registry ?? new GatewayRegistry();
		$this->gateway_override = $gateway_override;
	}

	/**
	 * @return TransactionStore
	 */
	public function store() {
		return $this->store;
	}

	/**
	 * @return GatewayRegistry
	 */
	public function registry() {
		return $this->registry;
	}

	/**
	 * The currently active gateway (the provider chosen in settings).
	 *
	 * @return GatewayInterface|\WP_Error
	 */
	public function active_gateway() {
		return $this->gateway_for( Settings::active_provider( $this->registry ) );
	}

	/**
	 * Build a gateway for a specific provider id, configured from settings.
	 *
	 * @param string $provider_id
	 * @return GatewayInterface|\WP_Error
	 */
	public function gateway_for( $provider_id ) {
		if ( $this->gateway_override && $this->gateway_override->id() === $provider_id ) {
			return $this->gateway_override;
		}
		$gateway = $this->registry->make( $provider_id, Settings::provider_config( $provider_id ) );
		if ( ! $gateway ) {
			return new \WP_Error(
				'formpay_cm_unknown_provider',
				/* translators: %s provider id */
				sprintf( __( 'Unknown payment provider: %s', 'formpay-cm' ), $provider_id )
			);
		}
		return $gateway;
	}

	/**
	 * Begin a payment from a normalised request: persist PENDING (tagged with
	 * the active provider), call the gateway, attach the provider ref.
	 *
	 * @param PaymentRequest $request
	 * @return array|\WP_Error  ['transaction' => object, 'redirect' => string]
	 */
	public function start( PaymentRequest $request ) {
		$valid = $request->validate();
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$gateway = $this->active_gateway();
		if ( is_wp_error( $gateway ) ) {
			return $gateway;
		}

		$txn = $this->store->create_pending( $request, $gateway->id(), $gateway->environment() );

		// Idempotency: if this reference was already settled, don't re-charge.
		if ( TransactionStatus::is_terminal( $txn->status ) ) {
			return new \WP_Error(
				'formpay_cm_already_settled',
				__( 'This order has already been processed.', 'formpay-cm' )
			);
		}

		// Already has a live checkout link? Reuse it.
		if ( ! empty( $txn->payment_link ) && ! empty( $txn->trans_id ) ) {
			return array(
				'transaction' => $txn,
				'redirect'    => $txn->payment_link,
			);
		}

		$result = $gateway->initiate( $request );
		if ( is_wp_error( $result ) ) {
			Logger::error(
				'Payment initiate failed',
				array(
					'provider'    => $gateway->id(),
					'external_id' => $request->external_id,
					'error'       => $result->get_error_message(),
				)
			);
			return $result;
		}

		$txn = $this->store->attach_provider_ref( $txn->id, $result->trans_id, $result->payment_link );

		return array(
			'transaction' => $txn,
			'redirect'    => $result->payment_link,
		);
	}

	/**
	 * Re-fetch authoritative status from the provider and apply it. Single
	 * source of truth used by BOTH the webhook and the reconcile sweep, so a
	 * lost webhook is always recoverable. The gateway is chosen from the
	 * transaction's stored provider, not the active one.
	 *
	 * @param string $trans_id
	 * @return object|\WP_Error Updated transaction row.
	 */
	public function sync_from_provider( $trans_id ) {
		$txn = $this->store->find_by_trans_id( $trans_id );
		if ( ! $txn ) {
			return new \WP_Error( 'formpay_cm_txn_missing', __( 'Unknown transaction.', 'formpay-cm' ) );
		}

		$gateway = $this->gateway_for( $txn->provider );
		if ( is_wp_error( $gateway ) ) {
			return $gateway;
		}

		$status = $gateway->fetch_status( $trans_id );
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		// Defence in depth: confirm the provider amount matches what we expect.
		if ( null !== $status['amount'] && (int) $status['amount'] !== (int) $txn->amount
			&& TransactionStatus::SUCCESSFUL === $status['status'] ) {
			Logger::error(
				'Amount mismatch on settlement',
				array(
					'trans_id' => $trans_id,
					'expected' => (int) $txn->amount,
					'actual'   => (int) $status['amount'],
				)
			);
			return new \WP_Error( 'formpay_cm_amount_mismatch', __( 'Payment amount mismatch.', 'formpay-cm' ) );
		}

		return $this->store->mark_status( $txn->id, $status['status'] );
	}

	/**
	 * Cron backstop: re-check every stale PENDING transaction against its
	 * provider. Compensates for single-delivery webhooks.
	 */
	public function reconcile_pending() {
		$rows = $this->store->stale_pending();
		foreach ( $rows as $row ) {
			$this->sync_from_provider( $row->trans_id );
		}
	}
}

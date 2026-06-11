<?php
/**
 * The provider-agnostic payment engine. Adapters call start(); the webhook
 * and cron sweep call sync_from_provider() / reconcile_pending().
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

	/** @var GatewayInterface|null */
	private $gateway;

	public function __construct( TransactionStore $store = null, GatewayInterface $gateway = null ) {
		$this->store   = $store ?: new TransactionStore();
		$this->gateway = $gateway; // lazy — built from settings when first needed
	}

	/**
	 * @return TransactionStore
	 */
	public function store() {
		return $this->store;
	}

	/**
	 * @return GatewayInterface
	 */
	public function gateway() {
		if ( null === $this->gateway ) {
			$this->gateway = new FapshiGateway( Settings::get_gateway_config() );
		}
		return $this->gateway;
	}

	/**
	 * Begin a payment from a normalised request: persist PENDING, call the
	 * gateway, attach the provider ref. Returns the checkout link to redirect to.
	 *
	 * @param PaymentRequest $request
	 * @return array|\WP_Error  ['transaction' => object, 'redirect' => string]
	 */
	public function start( PaymentRequest $request ) {
		$valid = $request->validate();
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$gateway = $this->gateway();
		$txn     = $this->store->create_pending( $request, $gateway->environment() );

		// Idempotency: if this reference was already settled, don't re-charge.
		if ( TransactionStatus::is_terminal( $txn->status ) ) {
			return new \WP_Error(
				'formpay_cm_already_settled',
				__( 'This order has already been processed.', 'formpay-cm' )
			);
		}

		// Already has a live checkout link? Reuse it.
		if ( ! empty( $txn->payment_link ) && ! empty( $txn->trans_id ) ) {
			return array( 'transaction' => $txn, 'redirect' => $txn->payment_link );
		}

		$result = $gateway->initiate( $request );
		if ( is_wp_error( $result ) ) {
			Logger::error( 'Payment initiate failed', array( 'external_id' => $request->external_id, 'error' => $result->get_error_message() ) );
			return $result;
		}

		$txn = $this->store->attach_provider_ref( $txn->id, $result->trans_id, $result->payment_link );

		return array( 'transaction' => $txn, 'redirect' => $result->payment_link );
	}

	/**
	 * Re-fetch authoritative status from the provider and apply it. Single
	 * source of truth used by BOTH the webhook and the reconcile sweep, so a
	 * lost webhook is always recoverable.
	 *
	 * @param string $trans_id
	 * @return object|\WP_Error Updated transaction row.
	 */
	public function sync_from_provider( $trans_id ) {
		$txn = $this->store->find_by_trans_id( $trans_id );
		if ( ! $txn ) {
			return new \WP_Error( 'formpay_cm_txn_missing', __( 'Unknown transaction.', 'formpay-cm' ) );
		}

		$status = $this->gateway()->fetch_status( $trans_id );
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		// Defence in depth: confirm the provider amount matches what we expect.
		if ( null !== $status['amount'] && (int) $status['amount'] !== (int) $txn->amount
			&& TransactionStatus::SUCCESSFUL === $status['status'] ) {
			Logger::error( 'Amount mismatch on settlement', array(
				'trans_id' => $trans_id,
				'expected' => (int) $txn->amount,
				'actual'   => (int) $status['amount'],
			) );
			return new \WP_Error( 'formpay_cm_amount_mismatch', __( 'Payment amount mismatch.', 'formpay-cm' ) );
		}

		return $this->store->mark_status( $txn->id, $status['status'] );
	}

	/**
	 * Cron backstop: re-check every stale PENDING transaction against the
	 * provider. Compensates for Fapshi's single-delivery webhook.
	 */
	public function reconcile_pending() {
		$rows = $this->store->stale_pending();
		foreach ( $rows as $row ) {
			$this->sync_from_provider( $row->trans_id );
		}
	}
}

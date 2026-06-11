<?php
/**
 * Persistence + state machine for payment transactions.
 *
 * All money-state transitions funnel through mark_status() so the terminal-
 * state guard lives in exactly one place.
 *
 * @package FormPayCM
 */

namespace FormPayCM\Payment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TransactionStore {

	const TABLE = 'formpay_cm_transactions';

	private function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create a PENDING transaction. Idempotent on external_id: if a row
	 * already exists for this reference we return it rather than duplicating.
	 *
	 * @param PaymentRequest $request
	 * @param string         $environment
	 * @return object Transaction row.
	 */
	public function create_pending( PaymentRequest $request, $environment ) {
		global $wpdb;

		$existing = $this->find_by_external_id( $request->external_id );
		if ( $existing ) {
			return $existing;
		}

		$now = current_time( 'mysql', true );
		$wpdb->insert(
			$this->table(),
			array(
				'external_id' => $request->external_id,
				'provider'    => 'fapshi',
				'source'      => $request->source,
				'form_id'     => (string) $request->form_id,
				'entry_id'    => $request->entry_id,
				'amount'      => (int) $request->amount,
				'currency'    => $request->currency,
				'status'      => TransactionStatus::PENDING,
				'payer_phone' => $request->phone,
				'payer_email' => $request->email,
				'environment' => $environment,
				'meta'        => wp_json_encode( $request->meta ),
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Attach the provider transId + checkout link once initiate succeeds.
	 */
	public function attach_provider_ref( $id, $trans_id, $payment_link ) {
		global $wpdb;
		$wpdb->update(
			$this->table(),
			array(
				'trans_id'     => $trans_id,
				'payment_link' => $payment_link,
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		return $this->find( (int) $id );
	}

	/**
	 * Transition a transaction to a new status, honouring the terminal guard.
	 *
	 * @return object|\WP_Error Updated row, or error if transition rejected.
	 */
	public function mark_status( $id, $new_status ) {
		global $wpdb;

		$row = $this->find( (int) $id );
		if ( ! $row ) {
			return new \WP_Error( 'formpay_cm_txn_missing', __( 'Transaction not found.', 'formpay-cm' ) );
		}

		if ( ! TransactionStatus::is_valid( $new_status ) ) {
			return new \WP_Error( 'formpay_cm_txn_status', __( 'Invalid status.', 'formpay-cm' ) );
		}

		// Already settled — never re-transition (idempotent against repeat/stale webhooks).
		if ( TransactionStatus::is_terminal( $row->status ) ) {
			return $row;
		}

		if ( $row->status === $new_status ) {
			return $row;
		}

		$wpdb->update(
			$this->table(),
			array(
				'status'     => $new_status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$row->status = $new_status;

		/**
		 * Fires after a transaction reaches a settled state. Other plugins
		 * (or the originating form) can hook this to fulfil the order.
		 *
		 * @param object $row The transaction row.
		 */
		if ( TransactionStatus::is_terminal( $new_status ) ) {
			do_action( 'formpay_cm_transaction_settled', $row );
			do_action( "formpay_cm_transaction_{$new_status}", $row );
		}

		return $row;
	}

	// The queries below interpolate $this->table(), which returns
	// $wpdb->prefix . self::TABLE — a trusted internal identifier. Table names
	// cannot be bound as prepared parameters, so this interpolation is safe.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	public function find( $id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id )
		);
	}

	public function find_by_external_id( $external_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE external_id = %s", $external_id )
		);
	}

	public function find_by_trans_id( $trans_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE trans_id = %s", $trans_id )
		);
	}

	/**
	 * PENDING transactions older than $min_age_minutes, for the reconcile sweep.
	 *
	 * @return object[]
	 */
	public function stale_pending( $min_age_minutes = 5, $limit = 50 ) {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $min_age_minutes * 60 ) );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()}
				 WHERE status = %s AND trans_id IS NOT NULL AND updated_at < %s
				 ORDER BY updated_at ASC LIMIT %d",
				TransactionStatus::PENDING,
				$cutoff,
				$limit
			)
		);
	}
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

<?php
/**
 * Main plugin orchestrator.
 *
 * @package FormPayCM
 */

namespace FormPayCM;

use FormPayCM\Admin\AdminPage;
use FormPayCM\Admin\FormPayments;
use FormPayCM\Admin\Settings;
use FormPayCM\Forms\ElementorAdapter;
use FormPayCM\Forms\MetFormAdapter;
use FormPayCM\Forms\WPFormsAdapter;
use FormPayCM\Http\WebhookController;
use FormPayCM\Payment\PaymentManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	const CRON_RECONCILE = 'formpay_cm_reconcile';

	/** @var Plugin|null */
	private static $instance = null;

	/** @var PaymentManager */
	private $payments;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Wire everything together. Called on plugins_loaded.
	 */
	public function boot() {
		load_plugin_textdomain( 'formpay-cm', false, dirname( FORMPAY_CM_BASENAME ) . '/languages' );

		// Core engine — provider-agnostic; Fapshi is just the first gateway.
		$this->payments = new PaymentManager();

		// Single tabbed admin page: Connection (credentials) + Payment Rules.
		if ( is_admin() ) {
			$settings      = new Settings();
			$form_payments = new FormPayments();
			$settings->register();        // registers the setting (admin_init)
			$form_payments->register();   // registers save/delete handlers (admin_post)
			( new AdminPage( $settings, $form_payments ) )->register(); // menu + tabs
		}

		// REST webhook endpoint + reconciliation backstop.
		( new WebhookController( $this->payments ) )->register();
		add_action( self::CRON_RECONCILE, array( $this->payments, 'reconcile_pending' ) );

		// Form adapters. Each only activates if its form plugin is present.
		$this->register_form_adapters();
	}

	/**
	 * @return PaymentManager
	 */
	public function payments() {
		return $this->payments;
	}

	/**
	 * Register an adapter per supported form plugin. Adapters are thin:
	 * they read this form's payment config, normalise fields into a
	 * PaymentRequest, and hand off to the PaymentManager.
	 */
	private function register_form_adapters() {
		$adapters = array(
			new ElementorAdapter( $this->payments ),
			new MetFormAdapter( $this->payments ),
			new WPFormsAdapter( $this->payments ),
		);

		foreach ( $adapters as $adapter ) {
			if ( $adapter->is_available() ) {
				$adapter->register();
			}
		}
	}
}

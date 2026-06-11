<?php
/**
 * Elementor Pro Forms adapter.
 *
 * Registers a custom "after submit" form action. Elementor renders the
 * action's controls in the form editor for free, and hands us the record
 * (all submitted fields) at submission time.
 *
 * Hook: elementor_pro/forms/actions/register
 * Docs: https://developers.elementor.com/custom-form-action/
 *
 * NOTE: The action class below is a thin wrapper — once Elementor Pro is
 * confirmed active we register an instance whose run() funnels into the
 * shared process_submission() pipeline.
 *
 * @package FormPayCM
 */

namespace FormPayCM\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ElementorAdapter extends AbstractFormAdapter {

	public function id() {
		return 'elementor';
	}

	public function is_available() {
		// Elementor Pro Forms module is what provides the action registry.
		return did_action( 'elementor_pro/init' ) || class_exists( '\ElementorPro\Plugin' );
	}

	public function register() {
		add_action(
			'elementor_pro/forms/actions/register',
			function ( $actions_registrar ) {
				require_once __DIR__ . '/Elementor/FormPayAction.php';
				$actions_registrar->register( new Elementor\FormPayAction( $this ) );
			}
		);
	}

	/**
	 * Called by the Elementor action with normalised data and the PriceRule
	 * assembled from the editor controls. Self-contained: no repository lookup.
	 *
	 * @param string                       $form_id
	 * @param array                        $fields  field id => value
	 * @param \FormPayCM\Pricing\PriceRule $rule
	 * @param array                        $context
	 * @return array|\WP_Error
	 */
	public function handle_submission( $form_id, array $fields, $rule, array $context = array() ) {
		return $this->process_with_rule( $form_id, $fields, $rule, $context );
	}
}

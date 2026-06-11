<?php
/**
 * WPForms adapter — fully integrated.
 *
 * Adds a "FormPay CM" section to the form builder's Settings panel where the
 * pricing rule is authored (including the faculty value→amount table), then
 * hooks the submission to resolve the amount, start the Fapshi payment, and
 * redirect the payer to checkout.
 *
 * @package FormPayCM
 */

namespace FormPayCM\Forms;

use FormPayCM\Pricing\PriceRule;
use FormPayCM\Pricing\RuleParser;
use FormPayCM\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPFormsAdapter extends AbstractFormAdapter {

	public function id() {
		return 'wpforms';
	}

	public function is_available() {
		return function_exists( 'wpforms' );
	}

	public function register() {
		// Builder: add our settings section (sidebar tab + content).
		add_filter( 'wpforms_builder_settings_sections', array( $this, 'add_builder_section' ), 20, 2 );
		add_action( 'wpforms_form_settings_panel_content', array( $this, 'render_builder_section' ), 20 );

		// Submission.
		add_action( 'wpforms_process_complete', array( $this, 'on_complete' ), 10, 4 );
	}

	// --- Builder UI ---

	public function add_builder_section( $sections, $form_data ) {
		$sections['formpay_cm'] = __( 'FormPay CM', 'formpay-cm' );
		return $sections;
	}

	public function render_builder_section( $instance ) {
		echo '<div class="wpforms-panel-content-section wpforms-panel-content-section-formpay_cm">';
		echo '<div class="wpforms-panel-content-section-title">' . esc_html__( 'FormPay CM — Mobile Money', 'formpay-cm' ) . '</div>';

		wpforms_panel_field( 'toggle', 'settings', 'formpay_cm_enable', $instance->form_data, __( 'Collect payment for this form', 'formpay-cm' ), array() );

		wpforms_panel_field(
			'select',
			'settings',
			'formpay_cm_mode',
			$instance->form_data,
			__( 'How is the amount decided?', 'formpay-cm' ),
			array(
				'default' => PriceRule::MODE_FIXED,
				'options' => array(
					PriceRule::MODE_FIXED       => __( 'Fixed amount', 'formpay-cm' ),
					PriceRule::MODE_FIELD_MAP   => __( 'From a field (Faculty → amount)', 'formpay-cm' ),
					PriceRule::MODE_CONDITIONAL => __( 'Conditional rules', 'formpay-cm' ),
					PriceRule::MODE_FIELD_VALUE => __( 'Amount typed by user', 'formpay-cm' ),
				),
			)
		);

		wpforms_panel_field( 'text', 'settings', 'formpay_cm_amount', $instance->form_data, __( 'Fixed amount (XAF)', 'formpay-cm' ), array() );
		wpforms_panel_field( 'text', 'settings', 'formpay_cm_field', $instance->form_data, __( 'Selector field ID', 'formpay-cm' ), array( 'tooltip' => __( 'The WPForms field ID whose value sets the price.', 'formpay-cm' ) ) );

		wpforms_panel_field(
			'textarea',
			'settings',
			'formpay_cm_map',
			$instance->form_data,
			__( 'Value → amount table', 'formpay-cm' ),
			array( 'tooltip' => __( 'One per line: value:amount  e.g.  medicine:75000', 'formpay-cm' ) )
		);

		wpforms_panel_field(
			'textarea',
			'settings',
			'formpay_cm_conditions',
			$instance->form_data,
			__( 'Conditional rules', 'formpay-cm' ),
			array( 'tooltip' => __( 'One per line: fieldA=valueA & fieldB=valueB : amount', 'formpay-cm' ) )
		);

		wpforms_panel_field( 'text', 'settings', 'formpay_cm_default', $instance->form_data, __( 'Default amount if no match (blank = reject)', 'formpay-cm' ), array() );
		wpforms_panel_field( 'text', 'settings', 'formpay_cm_email_field', $instance->form_data, __( 'Email field ID', 'formpay-cm' ), array() );
		wpforms_panel_field( 'text', 'settings', 'formpay_cm_phone_field', $instance->form_data, __( 'Phone field ID', 'formpay-cm' ), array() );
		wpforms_panel_field( 'text', 'settings', 'formpay_cm_redirect', $instance->form_data, __( 'After-payment redirect URL', 'formpay-cm' ), array() );

		echo '</div>';
	}

	// --- Submission ---

	/**
	 * @param array $fields    Processed fields (id => [name,value,...]).
	 * @param array $entry     Raw entry.
	 * @param array $form_data Form configuration.
	 * @param int   $entry_id  Saved entry id (0 if entries disabled).
	 */
	public function on_complete( $fields, $entry, $form_data, $entry_id ) {
		$settings = isset( $form_data['settings'] ) ? $form_data['settings'] : array();
		if ( empty( $settings['formpay_cm_enable'] ) || '1' !== (string) $settings['formpay_cm_enable'] ) {
			return; // payment not enabled for this form
		}

		$form_id = isset( $form_data['id'] ) ? (string) $form_data['id'] : '';

		$values = array();
		foreach ( (array) $fields as $fid => $field ) {
			$values[ (string) $fid ] = isset( $field['value'] ) ? $field['value'] : '';
		}

		$rule    = $this->build_rule( $settings );
		$context = array(
			'entry_id'     => $entry_id ? (string) $entry_id : null,
			'redirect_url' => ! empty( $settings['formpay_cm_redirect'] ) ? $settings['formpay_cm_redirect'] : home_url( '/' ),
			'message'      => isset( $form_data['settings']['form_title'] ) ? $form_data['settings']['form_title'] : __( 'Payment', 'formpay-cm' ),
		);

		$result = $this->process_with_rule( $form_id, $values, $rule, $context );

		if ( is_wp_error( $result ) ) {
			Logger::error(
				'WPForms payment start failed',
				array(
					'form'  => $form_id,
					'error' => $result->get_error_message(),
				)
			);
			// Surface to the user via WPForms' confirmation/error channel.
			add_filter(
				'wpforms_process_redirect_url',
				function ( $url ) use ( $result ) {
					return add_query_arg( 'formpay_cm_error', rawurlencode( $result->get_error_message() ), $url );
				},
				20
			);
			return;
		}

		// Override WPForms' redirect with the Fapshi checkout URL.
		$link = $result['redirect'];
		add_filter(
			'wpforms_process_redirect_url',
			function () use ( $link ) {
				return $link;
			},
			20
		);
	}

	/**
	 * Build a PriceRule from the textarea-based WPForms settings.
	 */
	private function build_rule( array $settings ) {
		$data = array(
			'mode'         => isset( $settings['formpay_cm_mode'] ) ? $settings['formpay_cm_mode'] : PriceRule::MODE_FIXED,
			'amount'       => isset( $settings['formpay_cm_amount'] ) ? (int) $settings['formpay_cm_amount'] : 0,
			'field'        => isset( $settings['formpay_cm_field'] ) ? trim( $settings['formpay_cm_field'] ) : '',
			'default'      => isset( $settings['formpay_cm_default'] ) && '' !== trim( (string) $settings['formpay_cm_default'] ) ? (int) $settings['formpay_cm_default'] : null,
			'currency'     => 'XAF',
			'map'          => RuleParser::parse_map( isset( $settings['formpay_cm_map'] ) ? $settings['formpay_cm_map'] : '' ),
			'rules'        => RuleParser::parse_conditions( isset( $settings['formpay_cm_conditions'] ) ? $settings['formpay_cm_conditions'] : '' ),
			'payer_fields' => array(
				'email' => isset( $settings['formpay_cm_email_field'] ) ? trim( $settings['formpay_cm_email_field'] ) : '',
				'phone' => isset( $settings['formpay_cm_phone_field'] ) ? trim( $settings['formpay_cm_phone_field'] ) : '',
			),
		);
		return PriceRule::from_array( $data );
	}
}

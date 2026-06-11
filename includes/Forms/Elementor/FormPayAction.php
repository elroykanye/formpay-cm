<?php
/**
 * The Elementor Pro form action. Appears under "Actions After Submit" in the
 * form editor as "FormPay CM (Mobile Money)".
 *
 * The entire pricing config (including the faculty → amount table) is authored
 * here in the editor via Elementor controls, so a form is fully self-contained:
 * no code and no separate settings screen required.
 *
 * @package FormPayCM
 */

namespace FormPayCM\Forms\Elementor;

use FormPayCM\Forms\ElementorAdapter;
use FormPayCM\Pricing\PriceRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\ElementorPro\Modules\Forms\Classes\Action_Base' ) ) {
	return;
}

class FormPayAction extends \ElementorPro\Modules\Forms\Classes\Action_Base {

	/** @var ElementorAdapter */
	private $adapter;

	public function __construct( ElementorAdapter $adapter ) {
		$this->adapter = $adapter;
	}

	public function get_name() {
		return 'formpay_cm';
	}

	public function get_label() {
		return __( 'FormPay CM (Mobile Money)', 'formpay-cm' );
	}

	/* ------------------------------------------------------------------ */
	/* Editor controls                                                     */
	/* ------------------------------------------------------------------ */

	public function register_settings_section( $widget ) {
		$widget->start_controls_section(
			'section_formpay_cm',
			array(
				'label' => __( 'FormPay CM — Payment', 'formpay-cm' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$widget->add_control(
			'formpay_cm_mode',
			array(
				'label'   => __( 'How is the amount decided?', 'formpay-cm' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => PriceRule::MODE_FIXED,
				'options' => array(
					PriceRule::MODE_FIXED       => __( 'Fixed amount', 'formpay-cm' ),
					PriceRule::MODE_FIELD_MAP   => __( 'From a field (e.g. Faculty → amount)', 'formpay-cm' ),
					PriceRule::MODE_CONDITIONAL => __( 'Conditional rules (multiple fields)', 'formpay-cm' ),
					PriceRule::MODE_FIELD_VALUE => __( 'Amount typed by the user (donations)', 'formpay-cm' ),
				),
			)
		);

		// Fixed.
		$widget->add_control(
			'formpay_cm_amount',
			array(
				'label'      => __( 'Amount (XAF)', 'formpay-cm' ),
				'type'       => \Elementor\Controls_Manager::NUMBER,
				'min'        => 100,
				'default'    => 1000,
				'condition'  => array( 'formpay_cm_mode' => PriceRule::MODE_FIXED ),
			)
		);

		// Selector field (field_map / field_value).
		$widget->add_control(
			'formpay_cm_field',
			array(
				'label'       => __( 'Form field ID', 'formpay-cm' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'description' => __( 'The Elementor field ID whose value sets the price (e.g. the Faculty dropdown ID, or the amount field for donations).', 'formpay-cm' ),
				'condition'   => array(
					'formpay_cm_mode' => array( PriceRule::MODE_FIELD_MAP, PriceRule::MODE_FIELD_VALUE ),
				),
			)
		);

		// field_map: the value → amount table (your faculty case).
		$map_repeater = new \Elementor\Repeater();
		$map_repeater->add_control(
			'option_value',
			array(
				'label'       => __( 'Field value', 'formpay-cm' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'description' => __( 'The submitted value, e.g. "medicine".', 'formpay-cm' ),
			)
		);
		$map_repeater->add_control(
			'amount',
			array(
				'label'   => __( 'Amount (XAF)', 'formpay-cm' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'min'     => 100,
				'default' => 1000,
			)
		);
		$widget->add_control(
			'formpay_cm_price_map',
			array(
				'label'       => __( 'Value → amount table', 'formpay-cm' ),
				'type'        => \Elementor\Controls_Manager::REPEATER,
				'fields'      => $map_repeater->get_controls(),
				'title_field' => '{{{ option_value }}} → {{{ amount }}} XAF',
				'condition'   => array( 'formpay_cm_mode' => PriceRule::MODE_FIELD_MAP ),
			)
		);

		// conditional: up to two ANDed fields per rule → amount.
		$cond_repeater = new \Elementor\Repeater();
		$cond_repeater->add_control( 'field_a', array( 'label' => __( 'Field A ID', 'formpay-cm' ), 'type' => \Elementor\Controls_Manager::TEXT ) );
		$cond_repeater->add_control( 'value_a', array( 'label' => __( 'Field A equals', 'formpay-cm' ), 'type' => \Elementor\Controls_Manager::TEXT ) );
		$cond_repeater->add_control( 'field_b', array( 'label' => __( 'Field B ID (optional)', 'formpay-cm' ), 'type' => \Elementor\Controls_Manager::TEXT ) );
		$cond_repeater->add_control( 'value_b', array( 'label' => __( 'Field B equals', 'formpay-cm' ), 'type' => \Elementor\Controls_Manager::TEXT ) );
		$cond_repeater->add_control( 'amount', array( 'label' => __( 'Amount (XAF)', 'formpay-cm' ), 'type' => \Elementor\Controls_Manager::NUMBER, 'min' => 100, 'default' => 1000 ) );
		$widget->add_control(
			'formpay_cm_conditions',
			array(
				'label'       => __( 'Conditional rules (first match wins)', 'formpay-cm' ),
				'type'        => \Elementor\Controls_Manager::REPEATER,
				'fields'      => $cond_repeater->get_controls(),
				'title_field' => '{{{ field_a }}}={{{ value_a }}} → {{{ amount }}} XAF',
				'condition'   => array( 'formpay_cm_mode' => PriceRule::MODE_CONDITIONAL ),
			)
		);

		// Fallback used by field_map / conditional when nothing matches.
		$widget->add_control(
			'formpay_cm_default',
			array(
				'label'       => __( 'Default amount if no match (blank = reject)', 'formpay-cm' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'min'         => 100,
				'condition'   => array(
					'formpay_cm_mode' => array( PriceRule::MODE_FIELD_MAP, PriceRule::MODE_CONDITIONAL ),
				),
			)
		);

		// Payer detail field mapping (prefill Fapshi checkout / receipt).
		$widget->add_control(
			'formpay_cm_email_field',
			array(
				'label'       => __( 'Email field ID', 'formpay-cm' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'description' => __( 'Optional. Used to prefill the Fapshi checkout and receipt.', 'formpay-cm' ),
			)
		);
		$widget->add_control(
			'formpay_cm_phone_field',
			array(
				'label' => __( 'Phone field ID', 'formpay-cm' ),
				'type'  => \Elementor\Controls_Manager::TEXT,
			)
		);

		$widget->add_control(
			'formpay_cm_redirect',
			array(
				'label'       => __( 'After-payment redirect URL', 'formpay-cm' ),
				'type'        => \Elementor\Controls_Manager::URL,
				'description' => __( 'Where Fapshi returns the payer after checkout (e.g. a thank-you page).', 'formpay-cm' ),
			)
		);

		$widget->end_controls_section();
	}

	/* ------------------------------------------------------------------ */
	/* Submission                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record  $record
	 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler
	 */
	public function run( $record, $ajax_handler ) {
		$settings = $record->get( 'form_settings' );
		$form_id  = ! empty( $settings['id'] ) ? $settings['id'] : '';

		// Flatten field records to id => value.
		$fields = array();
		foreach ( (array) $record->get( 'fields' ) as $id => $field ) {
			$fields[ $id ] = isset( $field['value'] ) ? $field['value'] : '';
		}

		$rule    = $this->build_rule( $settings );
		$context = array(
			'entry_id'     => null,
			'redirect_url' => isset( $settings['formpay_cm_redirect']['url'] ) ? $settings['formpay_cm_redirect']['url'] : null,
			'message'      => isset( $settings['form_name'] ) ? $settings['form_name'] : __( 'Payment', 'formpay-cm' ),
		);

		$result = $this->adapter->handle_submission( (string) $form_id, $fields, $rule, $context );

		if ( is_wp_error( $result ) ) {
			$ajax_handler->add_error_message( $result->get_error_message() );
			return;
		}

		// Redirect the payer to the Fapshi hosted checkout.
		$ajax_handler->add_response_data( 'redirect_url', $result['redirect'] );
	}

	/**
	 * Assemble a PriceRule from the editor control values.
	 *
	 * @param array $settings
	 * @return PriceRule
	 */
	private function build_rule( array $settings ) {
		$data = array(
			'mode'         => isset( $settings['formpay_cm_mode'] ) ? $settings['formpay_cm_mode'] : PriceRule::MODE_FIXED,
			'amount'       => isset( $settings['formpay_cm_amount'] ) ? (int) $settings['formpay_cm_amount'] : 0,
			'field'        => isset( $settings['formpay_cm_field'] ) ? $settings['formpay_cm_field'] : '',
			'default'      => isset( $settings['formpay_cm_default'] ) && '' !== $settings['formpay_cm_default'] ? (int) $settings['formpay_cm_default'] : null,
			'currency'     => 'XAF',
			'payer_fields' => array(
				'email' => isset( $settings['formpay_cm_email_field'] ) ? $settings['formpay_cm_email_field'] : '',
				'phone' => isset( $settings['formpay_cm_phone_field'] ) ? $settings['formpay_cm_phone_field'] : '',
			),
		);

		// field_map table.
		$map = array();
		foreach ( (array) ( $settings['formpay_cm_price_map'] ?? array() ) as $row ) {
			if ( isset( $row['option_value'] ) && '' !== $row['option_value'] ) {
				$map[ (string) $row['option_value'] ] = (int) $row['amount'];
			}
		}
		$data['map'] = $map;

		// conditional rules.
		$rules = array();
		foreach ( (array) ( $settings['formpay_cm_conditions'] ?? array() ) as $row ) {
			$when = array();
			if ( ! empty( $row['field_a'] ) ) {
				$when[ $row['field_a'] ] = isset( $row['value_a'] ) ? $row['value_a'] : '';
			}
			if ( ! empty( $row['field_b'] ) ) {
				$when[ $row['field_b'] ] = isset( $row['value_b'] ) ? $row['value_b'] : '';
			}
			if ( $when ) {
				$rules[] = array( 'when' => $when, 'amount' => (int) $row['amount'] );
			}
		}
		$data['rules'] = $rules;

		return PriceRule::from_array( $data );
	}

	public function on_export( $element ) {
		return $element;
	}
}

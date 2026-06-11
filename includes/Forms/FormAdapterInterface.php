<?php
/**
 * Contract every form-plugin adapter implements. Adapters are deliberately
 * thin: detect availability, register the submission hook, and translate a
 * submission into a PaymentRequest. No money logic lives here.
 *
 * @package FormPayCM
 */

namespace FormPayCM\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface FormAdapterInterface {

	/** Slug, e.g. 'elementor'. @return string */
	public function id();

	/** Is the underlying form plugin active? @return bool */
	public function is_available();

	/** Hook into the form plugin's submission lifecycle. @return void */
	public function register();
}

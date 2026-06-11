<?php
/**
 * Plugin Name:       FormPay CM
 * Plugin URI:        https://example.com/formpay-cm
 * Description:        Collect Mobile Money & Orange Money payments in Cameroon from any supported WordPress form (Elementor, MetForm, WPForms) via Fapshi.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Elroy
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       formpay-cm
 * Domain Path:       /languages
 *
 * @package FormPayCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'FORMPAY_CM_VERSION', '0.1.0' );
define( 'FORMPAY_CM_FILE', __FILE__ );
define( 'FORMPAY_CM_DIR', plugin_dir_path( __FILE__ ) );
define( 'FORMPAY_CM_URL', plugin_dir_url( __FILE__ ) );
define( 'FORMPAY_CM_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Minimal PSR-4-style autoloader for the FormPayCM\ namespace.
 *
 * FormPayCM\Payment\FapshiGateway  ->  includes/Payment/FapshiGateway.php
 */
spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'FormPayCM\\';
		$len    = strlen( $prefix );
		if ( 0 !== strncmp( $prefix, $class_name, $len ) ) {
			return;
		}
		$relative = substr( $class_name, $len );
		$path     = FORMPAY_CM_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

// Activation / deactivation lifecycle.
register_activation_hook( __FILE__, array( \FormPayCM\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \FormPayCM\Activator::class, 'deactivate' ) );

/**
 * Boot the plugin once all plugins are loaded (so form-plugin classes exist).
 */
add_action(
	'plugins_loaded',
	function () {
		\FormPayCM\Plugin::instance()->boot();
	}
);

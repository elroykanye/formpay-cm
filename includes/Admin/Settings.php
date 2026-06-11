<?php
/**
 * Admin settings: Fapshi credentials, environment, webhook secret.
 *
 * Stored under a single option array so credentials live server-side only.
 *
 * @package FormPayCM
 */

namespace FormPayCM\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	const OPTION   = 'formpay_cm_settings';
	const CAP      = 'manage_options';
	const PAGE     = 'formpay-cm';

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function add_menu() {
		add_options_page(
			__( 'FormPay CM', 'formpay-cm' ),
			__( 'FormPay CM', 'formpay-cm' ),
			self::CAP,
			self::PAGE,
			array( $this, 'render' )
		);
	}

	public function register_settings() {
		register_setting(
			self::OPTION,
			self::OPTION,
			array( 'sanitize_callback' => array( $this, 'sanitize' ) )
		);
	}

	public function sanitize( $input ) {
		$out                = array();
		$out['environment'] = ( isset( $input['environment'] ) && 'live' === $input['environment'] ) ? 'live' : 'sandbox';
		$out['api_user']    = isset( $input['api_user'] ) ? sanitize_text_field( $input['api_user'] ) : '';
		$out['api_key']     = isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '';
		$out['webhook_secret'] = isset( $input['webhook_secret'] ) ? sanitize_text_field( $input['webhook_secret'] ) : '';
		return $out;
	}

	public function render() {
		$opts        = self::all();
		$webhook_url = rest_url( 'formpay-cm/v1/webhook/fapshi' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'FormPay CM — Fapshi Settings', 'formpay-cm' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Environment', 'formpay-cm' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION ); ?>[environment]">
								<option value="sandbox" <?php selected( $opts['environment'], 'sandbox' ); ?>><?php esc_html_e( 'Sandbox', 'formpay-cm' ); ?></option>
								<option value="live" <?php selected( $opts['environment'], 'live' ); ?>><?php esc_html_e( 'Live', 'formpay-cm' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'API User', 'formpay-cm' ); ?></label></th>
						<td><input type="text" name="<?php echo esc_attr( self::OPTION ); ?>[api_user]" value="<?php echo esc_attr( $opts['api_user'] ); ?>" class="regular-text" autocomplete="off" /></td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'API Key', 'formpay-cm' ); ?></label></th>
						<td><input type="password" name="<?php echo esc_attr( self::OPTION ); ?>[api_key]" value="<?php echo esc_attr( $opts['api_key'] ); ?>" class="regular-text" autocomplete="off" /></td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Webhook Secret', 'formpay-cm' ); ?></label></th>
						<td>
							<input type="password" name="<?php echo esc_attr( self::OPTION ); ?>[webhook_secret]" value="<?php echo esc_attr( $opts['webhook_secret'] ); ?>" class="regular-text" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Set the same value as the webhook secret in your Fapshi dashboard. Fapshi cannot display an existing secret, so keep this in sync yourself.', 'formpay-cm' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook URL', 'formpay-cm' ); ?></th>
						<td><code><?php echo esc_html( $webhook_url ); ?></code><p class="description"><?php esc_html_e( 'Paste this into your Fapshi service\'s webhook field.', 'formpay-cm' ); ?></p></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */

	public static function all() {
		$defaults = array(
			'environment'    => 'sandbox',
			'api_user'       => '',
			'api_key'        => '',
			'webhook_secret' => '',
		);
		return wp_parse_args( get_option( self::OPTION, array() ), $defaults );
	}

	/**
	 * Config slice the gateway needs.
	 *
	 * @return array
	 */
	public static function get_gateway_config() {
		$o = self::all();
		return array(
			'api_user'    => $o['api_user'],
			'api_key'     => $o['api_key'],
			'environment' => $o['environment'],
		);
	}

	public static function get_webhook_secret() {
		$o = self::all();
		return $o['webhook_secret'];
	}
}

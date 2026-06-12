<?php
/**
 * Connection tab: choose the active payment provider, the environment, and
 * each provider's credentials.
 *
 * The form is provider-agnostic — it renders whatever fields each registered
 * gateway declares via config_fields(), so adding an operator needs no change
 * here. Credentials are stored server-side under a single option:
 *
 *   [ 'active_provider' => 'fapshi', 'environment' => 'sandbox',
 *     'providers' => [ 'fapshi' => [ 'api_user' => '', ... ] ] ]
 *
 * @package FormPayCM
 */

namespace FormPayCM\Admin;

use FormPayCM\Payment\GatewayRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	const OPTION = 'formpay_cm_settings';
	const CAP    = 'manage_options';

	/** @var GatewayRegistry */
	private $registry;

	public function __construct( GatewayRegistry $registry = null ) {
		$this->registry = $registry ?? new GatewayRegistry();
	}

	/**
	 * Only registers the setting; the menu/page is owned by AdminPage.
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_settings() {
		register_setting(
			self::OPTION,
			self::OPTION,
			array( 'sanitize_callback' => array( $this, 'sanitize' ) )
		);
	}

	/**
	 * Validate and normalise the submitted settings against the registered
	 * gateways' declared fields.
	 *
	 * @param mixed $input
	 * @return array
	 */
	public function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$registry = $this->registry;
		$ids      = $registry->ids();

		$active = isset( $input['active_provider'] ) ? sanitize_key( $input['active_provider'] ) : '';
		if ( ! in_array( $active, $ids, true ) ) {
			$active = $registry->default_id();
		}

		$out = array(
			'active_provider' => $active,
			'environment'     => ( isset( $input['environment'] ) && 'live' === $input['environment'] ) ? 'live' : 'sandbox',
			'providers'       => array(),
		);

		foreach ( $registry->classes() as $id => $class ) {
			$fields    = ( new $class() )->config_fields();
			$submitted = isset( $input['providers'][ $id ] ) && is_array( $input['providers'][ $id ] ) ? $input['providers'][ $id ] : array();
			$clean     = array();
			foreach ( $fields as $key => $field ) {
				$clean[ $key ] = isset( $submitted[ $key ] ) ? sanitize_text_field( $submitted[ $key ] ) : '';
			}
			$out['providers'][ $id ] = $clean;
		}

		return $out;
	}

	/**
	 * Renders the Connection tab body (no page wrapper — AdminPage provides it).
	 */
	public function render_tab() {
		$opts   = self::all();
		$labels = $this->registry->labels();
		?>
		<p class="description" style="max-width:50em;margin:1em 0;">
			<?php esc_html_e( 'Choose your payment provider and connect your account. Use Sandbox credentials while testing, then switch to Live for real payments. Credentials are stored only on your server.', 'formpay-cm' ); ?>
		</p>
		<form method="post" action="options.php">
			<?php settings_fields( self::OPTION ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="formpay-cm-active-provider"><?php esc_html_e( 'Payment provider', 'formpay-cm' ); ?></label></th>
					<td>
						<select name="<?php echo esc_attr( self::OPTION ); ?>[active_provider]" id="formpay-cm-active-provider">
							<?php foreach ( $labels as $id => $label ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $opts['active_provider'], $id ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'The provider used to collect new payments. In-progress payments are always settled with the provider that started them.', 'formpay-cm' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Environment', 'formpay-cm' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( self::OPTION ); ?>[environment]">
							<option value="sandbox" <?php selected( $opts['environment'], 'sandbox' ); ?>><?php esc_html_e( 'Sandbox', 'formpay-cm' ); ?></option>
							<option value="live" <?php selected( $opts['environment'], 'live' ); ?>><?php esc_html_e( 'Live', 'formpay-cm' ); ?></option>
						</select>
					</td>
				</tr>
			</table>

			<?php
			foreach ( $this->registry->classes() as $id => $class ) {
				$this->render_provider_section( $id, ( new $class() ), $opts );
			}
			?>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render one provider's credential fields plus its webhook URL.
	 *
	 * @param string                                     $id
	 * @param \FormPayCM\Payment\GatewayInterface        $gateway
	 * @param array                                      $opts
	 */
	private function render_provider_section( $id, $gateway, $opts ) {
		$fields      = $gateway->config_fields();
		$values      = isset( $opts['providers'][ $id ] ) ? $opts['providers'][ $id ] : array();
		$webhook_url = rest_url( 'formpay-cm/v1/webhook/' . $id );
		?>
		<h3><?php echo esc_html( $gateway->label() ); ?></h3>
		<table class="form-table" role="presentation">
			<?php foreach ( $fields as $key => $field ) : ?>
				<tr>
					<th scope="row">
						<label for="<?php echo esc_attr( 'formpay-cm-' . $id . '-' . $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
					</th>
					<td>
						<input
							type="<?php echo esc_attr( isset( $field['type'] ) ? $field['type'] : 'text' ); ?>"
							id="<?php echo esc_attr( 'formpay-cm-' . $id . '-' . $key ); ?>"
							name="<?php echo esc_attr( self::OPTION . '[providers][' . $id . '][' . $key . ']' ); ?>"
							value="<?php echo esc_attr( isset( $values[ $key ] ) ? $values[ $key ] : '' ); ?>"
							class="regular-text" autocomplete="off" />
						<?php if ( ! empty( $field['description'] ) ) : ?>
							<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Webhook URL', 'formpay-cm' ); ?></th>
				<td>
					<code><?php echo esc_html( $webhook_url ); ?></code>
					<p class="description"><?php esc_html_e( 'Paste this into this provider\'s webhook/callback setting.', 'formpay-cm' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/* ------------------------------------------------------------------ */

	/**
	 * All settings with defaults applied.
	 *
	 * @return array
	 */
	public static function all() {
		$defaults = array(
			'active_provider' => 'fapshi',
			'environment'     => 'sandbox',
			'providers'       => array(),
		);
		return wp_parse_args( get_option( self::OPTION, array() ), $defaults );
	}

	/**
	 * The active provider id, falling back to the registry default.
	 *
	 * @param GatewayRegistry|null $registry
	 * @return string
	 */
	public static function active_provider( GatewayRegistry $registry = null ) {
		$opts     = self::all();
		$registry = $registry ?? new GatewayRegistry();
		$active   = $opts['active_provider'];
		return $registry->has( $active ) ? $active : $registry->default_id();
	}

	/**
	 * The selected environment ('sandbox' | 'live').
	 *
	 * @return string
	 */
	public static function environment() {
		$opts = self::all();
		return 'live' === $opts['environment'] ? 'live' : 'sandbox';
	}

	/**
	 * Config slice for a provider: its stored fields plus the common
	 * environment, as the gateway expects.
	 *
	 * @param string $provider_id
	 * @return array
	 */
	public static function provider_config( $provider_id ) {
		$opts                  = self::all();
		$config                = isset( $opts['providers'][ $provider_id ] ) && is_array( $opts['providers'][ $provider_id ] )
			? $opts['providers'][ $provider_id ]
			: array();
		$config['environment'] = self::environment();
		return $config;
	}
}

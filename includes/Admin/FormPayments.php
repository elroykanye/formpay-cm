<?php
/**
 * Central "Form Payments" admin screen.
 *
 * Authors a PriceRule per (source, form id). Primarily for MetForm, whose
 * Elementor widget can't host our controls — but works for any source. The
 * value→amount table and conditional rules use the same text formats as the
 * WPForms builder, parsed by RuleParser.
 *
 * @package FormPayCM
 */

namespace FormPayCM\Admin;

use FormPayCM\Pricing\PriceRule;
use FormPayCM\Pricing\PriceRuleRepository;
use FormPayCM\Pricing\RuleParser;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FormPayments {

	const CAP  = 'manage_options';
	const PAGE = 'formpay-cm-rules';

	/** @var PriceRuleRepository */
	private $repo;

	public function __construct( PriceRuleRepository $repo = null ) {
		$this->repo = $repo ?? new PriceRuleRepository();
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_formpay_cm_save_rule', array( $this, 'handle_save' ) );
		add_action( 'admin_post_formpay_cm_delete_rule', array( $this, 'handle_delete' ) );
	}

	public function add_menu() {
		add_submenu_page(
			'options-general.php',
			__( 'Form Payments', 'formpay-cm' ),
			__( 'Form Payments', 'formpay-cm' ),
			self::CAP,
			self::PAGE,
			array( $this, 'render' )
		);
	}

	// --- Save / delete ---

	public function handle_save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'formpay-cm' ) );
		}
		check_admin_referer( 'formpay_cm_save_rule' );

		$source  = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$form_id = isset( $_POST['form_id'] ) ? sanitize_text_field( wp_unslash( $_POST['form_id'] ) ) : '';

		if ( '' === $source || '' === $form_id ) {
			$this->redirect_back( 'missing_key' );
		}

		$rule = PriceRule::from_array(
			array(
				'mode'         => isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : PriceRule::MODE_FIXED,
				'amount'       => isset( $_POST['amount'] ) ? (int) $_POST['amount'] : 0,
				'field'        => isset( $_POST['field'] ) ? sanitize_text_field( wp_unslash( $_POST['field'] ) ) : '',
				'default'      => ( isset( $_POST['default'] ) && '' !== trim( wp_unslash( $_POST['default'] ) ) ) ? (int) $_POST['default'] : null,
				'currency'     => 'XAF',
				'map'          => RuleParser::parse_map( isset( $_POST['map'] ) ? wp_unslash( $_POST['map'] ) : '' ),
				'rules'        => RuleParser::parse_conditions( isset( $_POST['conditions'] ) ? wp_unslash( $_POST['conditions'] ) : '' ),
				'payer_fields' => array(
					'email' => isset( $_POST['email_field'] ) ? sanitize_text_field( wp_unslash( $_POST['email_field'] ) ) : '',
					'phone' => isset( $_POST['phone_field'] ) ? sanitize_text_field( wp_unslash( $_POST['phone_field'] ) ) : '',
				),
			)
		);

		$this->repo->save( $source, $form_id, $rule );
		$this->redirect_back( 'saved' );
	}

	public function handle_delete() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'formpay-cm' ) );
		}
		check_admin_referer( 'formpay_cm_delete_rule' );

		$source  = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '';
		$form_id = isset( $_GET['form_id'] ) ? sanitize_text_field( wp_unslash( $_GET['form_id'] ) ) : '';
		if ( $source && $form_id ) {
			$this->repo->delete( $source, $form_id );
		}
		$this->redirect_back( 'deleted' );
	}

	private function redirect_back( $notice ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::PAGE,
					'notice' => $notice,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	// --- Render ---

	public function render() {
		$all = get_option( PriceRuleRepository::OPTION, array() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'FormPay CM — Form Payments', 'formpay-cm' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Attach a pricing rule to a form. Use this for MetForm (and any form whose payment config you prefer to manage here). Elementor and WPForms can also be configured directly in their own form editors.', 'formpay-cm' ); ?></p>

			<?php $this->render_existing( $all ); ?>

			<h2><?php esc_html_e( 'Add / update a rule', 'formpay-cm' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="formpay_cm_save_rule" />
				<?php wp_nonce_field( 'formpay_cm_save_rule' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Form plugin', 'formpay-cm' ); ?></th>
						<td>
							<select name="source">
								<option value="metform">MetForm</option>
								<option value="elementor">Elementor</option>
								<option value="wpforms">WPForms</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Form ID', 'formpay-cm' ); ?></th>
						<td><input type="text" name="form_id" class="regular-text" required /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Pricing mode', 'formpay-cm' ); ?></th>
						<td>
							<select name="mode">
								<option value="<?php echo esc_attr( PriceRule::MODE_FIXED ); ?>"><?php esc_html_e( 'Fixed amount', 'formpay-cm' ); ?></option>
								<option value="<?php echo esc_attr( PriceRule::MODE_FIELD_MAP ); ?>"><?php esc_html_e( 'From a field (Faculty → amount)', 'formpay-cm' ); ?></option>
								<option value="<?php echo esc_attr( PriceRule::MODE_CONDITIONAL ); ?>"><?php esc_html_e( 'Conditional rules', 'formpay-cm' ); ?></option>
								<option value="<?php echo esc_attr( PriceRule::MODE_FIELD_VALUE ); ?>"><?php esc_html_e( 'Amount typed by user', 'formpay-cm' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Fixed amount (XAF)', 'formpay-cm' ); ?></th>
						<td><input type="number" name="amount" min="100" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Selector field name', 'formpay-cm' ); ?></th>
						<td><input type="text" name="field" class="regular-text" />
							<p class="description"><?php esc_html_e( 'MetForm input name, e.g. "faculty" or "mf-faculty".', 'formpay-cm' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Value → amount table', 'formpay-cm' ); ?></th>
						<td><textarea name="map" rows="5" class="large-text code" placeholder="medicine:75000&#10;law:50000&#10;arts:40000"></textarea></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Conditional rules', 'formpay-cm' ); ?></th>
						<td><textarea name="conditions" rows="4" class="large-text code" placeholder="faculty=medicine & level=100 : 80000"></textarea></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Default amount (blank = reject)', 'formpay-cm' ); ?></th>
						<td><input type="number" name="default" min="100" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email field name', 'formpay-cm' ); ?></th>
						<td><input type="text" name="email_field" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Phone field name', 'formpay-cm' ); ?></th>
						<td><input type="text" name="phone_field" class="regular-text" /></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save rule', 'formpay-cm' ) ); ?>
			</form>
		</div>
		<?php
	}

	private function render_existing( $all ) {
		if ( empty( $all ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Configured rules', 'formpay-cm' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>Form</th><th>Mode</th><th>Summary</th><th></th></tr></thead><tbody>';
		foreach ( $all as $key => $data ) {
			list( $source, $form_id ) = array_pad( explode( ':', $key, 2 ), 2, '' );
			$rule                     = PriceRule::from_array( (array) $data );
			$summary                  = self::mode_summary( $rule );
			$del                      = wp_nonce_url(
				add_query_arg(
					array(
						'action'  => 'formpay_cm_delete_rule',
						'source'  => $source,
						'form_id' => $form_id,
					),
					admin_url( 'admin-post.php' )
				),
				'formpay_cm_delete_rule'
			);
			printf(
				'<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td><a href="%s" onclick="return confirm(\'%s\')">%s</a></td></tr>',
				esc_html( $key ),
				esc_html( $rule->mode ),
				esc_html( $summary ),
				esc_url( $del ),
				esc_js( __( 'Delete this rule?', 'formpay-cm' ) ),
				esc_html__( 'Delete', 'formpay-cm' )
			);
		}
		echo '</tbody></table>';
	}

	private static function mode_summary( PriceRule $rule ) {
		switch ( $rule->mode ) {
			case PriceRule::MODE_FIXED:
				return sprintf( '%d XAF', $rule->amount );
			case PriceRule::MODE_FIELD_MAP:
				return sprintf( '%s → %d options', $rule->field, count( $rule->map ) );
			case PriceRule::MODE_CONDITIONAL:
				return sprintf( '%d rules', count( $rule->rules ) );
			case PriceRule::MODE_FIELD_VALUE:
				return sprintf( 'amount from "%s"', $rule->field );
		}
		return '';
	}
}

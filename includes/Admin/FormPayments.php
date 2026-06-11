<?php
/**
 * "Payment Rules" tab of the FormPay CM settings page.
 *
 * A rule connects one form to an amount: when that form is submitted, FormPay
 * CM works out how much to charge and sends the visitor to Fapshi to pay.
 * Primarily used for MetForm (whose editor can't host our controls), but works
 * for any source. Stored per (source, form id) via PriceRuleRepository.
 *
 * Pricing is configured with simple add-a-row tables — no special syntax.
 *
 * @package FormPayCM
 */

namespace FormPayCM\Admin;

use FormPayCM\Pricing\PriceRule;
use FormPayCM\Pricing\PriceRuleRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FormPayments {

	const CAP  = 'manage_options';
	const PAGE = 'formpay-cm';
	const TAB  = 'rules';

	/** @var PriceRuleRepository */
	private $repo;

	public function __construct( PriceRuleRepository $repo = null ) {
		$this->repo = $repo ?? new PriceRuleRepository();
	}

	/**
	 * Only the save/delete handlers — the menu/page is owned by AdminPage.
	 */
	public function register() {
		add_action( 'admin_post_formpay_cm_save_rule', array( $this, 'handle_save' ) );
		add_action( 'admin_post_formpay_cm_delete_rule', array( $this, 'handle_delete' ) );
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
				'map'          => $this->collect_map( isset( $_POST['map'] ) ? wp_unslash( $_POST['map'] ) : array() ),
				'rules'        => $this->collect_conditions( isset( $_POST['conditions'] ) ? wp_unslash( $_POST['conditions'] ) : array() ),
				'payer_fields' => array(
					'email' => isset( $_POST['email_field'] ) ? sanitize_text_field( wp_unslash( $_POST['email_field'] ) ) : '',
					'phone' => isset( $_POST['phone_field'] ) ? sanitize_text_field( wp_unslash( $_POST['phone_field'] ) ) : '',
				),
			)
		);

		$this->repo->save( $source, $form_id, $rule );
		$this->redirect_back( 'saved' );
	}

	/**
	 * Build the value => amount map from the submitted table rows.
	 *
	 * @param mixed $rows Array of [ 'value' => string, 'amount' => string ].
	 * @return array<string,int>
	 */
	private function collect_map( $rows ) {
		$map = array();
		foreach ( (array) $rows as $row ) {
			$value = isset( $row['value'] ) ? sanitize_text_field( $row['value'] ) : '';
			if ( '' === $value ) {
				continue;
			}
			$map[ $value ] = isset( $row['amount'] ) ? (int) $row['amount'] : 0;
		}
		return $map;
	}

	/**
	 * Build conditional rules from the submitted table rows. Each row has up to
	 * two field/value pairs (ANDed) and an amount.
	 *
	 * @param mixed $rows
	 * @return array<int,array>
	 */
	private function collect_conditions( $rows ) {
		$rules = array();
		foreach ( (array) $rows as $row ) {
			$when    = array();
			$field_a = isset( $row['field_a'] ) ? sanitize_text_field( $row['field_a'] ) : '';
			$field_b = isset( $row['field_b'] ) ? sanitize_text_field( $row['field_b'] ) : '';
			if ( '' !== $field_a ) {
				$when[ $field_a ] = isset( $row['value_a'] ) ? sanitize_text_field( $row['value_a'] ) : '';
			}
			if ( '' !== $field_b ) {
				$when[ $field_b ] = isset( $row['value_b'] ) ? sanitize_text_field( $row['value_b'] ) : '';
			}
			if ( ! empty( $when ) ) {
				$rules[] = array(
					'when'   => $when,
					'amount' => isset( $row['amount'] ) ? (int) $row['amount'] : 0,
				);
			}
		}
		return $rules;
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
					'tab'    => self::TAB,
					'notice' => $notice,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	// --- Render ---

	/**
	 * Renders the Payment Rules tab body (no page wrapper — AdminPage provides it).
	 */
	public function render_tab() {
		$all = get_option( PriceRuleRepository::OPTION, array() );
		$this->render_notice();
		?>
		<p class="description" style="max-width:50em;margin:1em 0;">
			<?php esc_html_e( 'A payment rule connects one form to an amount. When that form is submitted, FormPay CM works out how much to charge, records it, and sends the visitor to Fapshi to pay with MTN Mobile Money or Orange Money.', 'formpay-cm' ); ?>
		</p>
		<p class="description" style="max-width:50em;margin:1em 0;">
			<strong><?php esc_html_e( 'Tip:', 'formpay-cm' ); ?></strong>
			<?php esc_html_e( 'For Elementor and WPForms you can set this up directly inside the form editor. Use this screen for MetForm, or whenever you prefer to manage all payments in one place.', 'formpay-cm' ); ?>
		</p>

		<?php
		$this->render_existing( $all );
		$this->render_form( $this->editing_rule() );
		$this->render_script();
	}

	/**
	 * The rule currently being edited, from ?edit=source:form_id, or null.
	 *
	 * @return array{source:string,form_id:string,rule:PriceRule}|null
	 */
	private function editing_rule() {
		$key = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $key || false === strpos( $key, ':' ) ) {
			return null;
		}
		list( $source, $form_id ) = array_pad( explode( ':', $key, 2 ), 2, '' );
		$rule                     = $this->repo->get( $source, $form_id );
		if ( ! $rule ) {
			return null;
		}
		return array(
			'source'  => $source,
			'form_id' => $form_id,
			'rule'    => $rule,
		);
	}

	private function render_notice() {
		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $notice ) {
			return;
		}
		$messages = array(
			'saved'       => array( 'updated', __( 'Payment rule saved.', 'formpay-cm' ) ),
			'deleted'     => array( 'updated', __( 'Payment rule deleted.', 'formpay-cm' ) ),
			'missing_key' => array( 'error', __( 'Please choose a form plugin and enter a form ID.', 'formpay-cm' ) ),
		);
		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $messages[ $notice ][0] ),
			esc_html( $messages[ $notice ][1] )
		);
	}

	private function render_existing( $all ) {
		if ( empty( $all ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Configured payments', 'formpay-cm' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:60em;"><thead><tr>';
		echo '<th>' . esc_html__( 'Form', 'formpay-cm' ) . '</th>';
		echo '<th>' . esc_html__( 'How much is charged', 'formpay-cm' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';

		foreach ( $all as $key => $data ) {
			list( $source, $form_id ) = array_pad( explode( ':', $key, 2 ), 2, '' );
			$rule                     = PriceRule::from_array( (array) $data );
			$edit                     = add_query_arg(
				array(
					'page' => self::PAGE,
					'tab'  => self::TAB,
					'edit' => rawurlencode( $key ),
				),
				admin_url( 'options-general.php' )
			);
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
				'<tr><td><strong>%s</strong><br><span class="description">%s</span></td><td>%s</td><td>'
				. '<a href="%s">%s</a> | <a href="%s" onclick="return confirm(\'%s\')">%s</a></td></tr>',
				esc_html( ucfirst( $source ) ),
				esc_html( sprintf( /* translators: %s form id */ __( 'form %s', 'formpay-cm' ), $form_id ) ),
				esc_html( $this->human_summary( $rule ) ),
				esc_url( $edit ),
				esc_html__( 'Edit', 'formpay-cm' ),
				esc_url( $del ),
				esc_js( __( 'Delete this payment rule?', 'formpay-cm' ) ),
				esc_html__( 'Delete', 'formpay-cm' )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * @param array|null $editing source/form_id/rule being edited, or null.
	 */
	private function render_form( $editing ) {
		$rule    = $editing ? $editing['rule'] : new PriceRule();
		$source  = $editing ? $editing['source'] : 'metform';
		$form_id = $editing ? $editing['form_id'] : '';

		$modes = array(
			PriceRule::MODE_FIXED       => __( 'Always the same amount', 'formpay-cm' ),
			PriceRule::MODE_FIELD_MAP   => __( 'Based on one field (e.g. Faculty)', 'formpay-cm' ),
			PriceRule::MODE_CONDITIONAL => __( 'Based on several fields (advanced)', 'formpay-cm' ),
			PriceRule::MODE_FIELD_VALUE => __( 'Amount the visitor types (donations)', 'formpay-cm' ),
		);
		?>
		<h2><?php echo $editing ? esc_html__( 'Edit payment', 'formpay-cm' ) : esc_html__( 'Add a payment', 'formpay-cm' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="formpay-cm-rule-form">
			<input type="hidden" name="action" value="formpay_cm_save_rule" />
			<?php wp_nonce_field( 'formpay_cm_save_rule' ); ?>

			<h3><?php esc_html_e( 'Step 1 — Which form?', 'formpay-cm' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="formpay-cm-source"><?php esc_html_e( 'Form plugin', 'formpay-cm' ); ?></label></th>
					<td>
						<select name="source" id="formpay-cm-source">
							<option value="metform" <?php selected( $source, 'metform' ); ?>>MetForm</option>
							<option value="elementor" <?php selected( $source, 'elementor' ); ?>>Elementor</option>
							<option value="wpforms" <?php selected( $source, 'wpforms' ); ?>>WPForms</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="formpay-cm-form-id"><?php esc_html_e( 'Form ID', 'formpay-cm' ); ?></label></th>
					<td>
						<input type="text" name="form_id" id="formpay-cm-form-id" class="regular-text" value="<?php echo esc_attr( $form_id ); ?>" required />
						<p class="description"><?php esc_html_e( 'The form\'s ID inside its plugin (for MetForm/WPForms this is the numeric ID shown in the forms list).', 'formpay-cm' ); ?></p>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Step 2 — How much to charge?', 'formpay-cm' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="formpay-cm-mode"><?php esc_html_e( 'Pricing', 'formpay-cm' ); ?></label></th>
					<td>
						<select name="mode" id="formpay-cm-mode">
							<?php foreach ( $modes as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $rule->mode, $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr class="formpay-cm-row" data-modes="fixed">
					<th scope="row"><label for="formpay-cm-amount"><?php esc_html_e( 'Amount (XAF)', 'formpay-cm' ); ?></label></th>
					<td><input type="number" name="amount" id="formpay-cm-amount" min="100" value="<?php echo $rule->amount ? esc_attr( $rule->amount ) : ''; ?>" /></td>
				</tr>
				<tr class="formpay-cm-row" data-modes="field_map field_value">
					<th scope="row"><label for="formpay-cm-field"><?php esc_html_e( 'Which field decides the price?', 'formpay-cm' ); ?></label></th>
					<td>
						<input type="text" name="field" id="formpay-cm-field" class="regular-text" value="<?php echo esc_attr( $rule->field ); ?>" />
						<p class="description"><?php esc_html_e( 'The form field\'s name/ID, e.g. "faculty".', 'formpay-cm' ); ?></p>
					</td>
				</tr>
			</table>

			<?php $this->render_map_table( $rule ); ?>
			<?php $this->render_conditions_table( $rule ); ?>

			<table class="form-table formpay-cm-row" role="presentation" data-modes="field_map conditional">
				<tr>
					<th scope="row"><label for="formpay-cm-default"><?php esc_html_e( 'If nothing matches', 'formpay-cm' ); ?></label></th>
					<td>
						<input type="number" name="default" id="formpay-cm-default" min="100" value="<?php echo null !== $rule->default ? esc_attr( $rule->default ) : ''; ?>" />
						<p class="description"><?php esc_html_e( 'Fallback amount when no option/rule matches. Leave blank to reject the payment instead.', 'formpay-cm' ); ?></p>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Step 3 — Payer details (optional)', 'formpay-cm' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="formpay-cm-email-field"><?php esc_html_e( 'Email field', 'formpay-cm' ); ?></label></th>
					<td><input type="text" name="email_field" id="formpay-cm-email-field" class="regular-text" value="<?php echo esc_attr( isset( $rule->payer_fields['email'] ) ? $rule->payer_fields['email'] : '' ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="formpay-cm-phone-field"><?php esc_html_e( 'Phone field', 'formpay-cm' ); ?></label></th>
					<td>
						<input type="text" name="phone_field" id="formpay-cm-phone-field" class="regular-text" value="<?php echo esc_attr( isset( $rule->payer_fields['phone'] ) ? $rule->payer_fields['phone'] : '' ); ?>" />
						<p class="description"><?php esc_html_e( 'Field names used to pre-fill the Fapshi checkout. Optional.', 'formpay-cm' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( $editing ? __( 'Update payment', 'formpay-cm' ) : __( 'Save payment', 'formpay-cm' ) ); ?>
		</form>
		<?php
	}

	/**
	 * The value -> amount table (mode: field_map).
	 */
	private function render_map_table( PriceRule $rule ) {
		?>
		<div class="formpay-cm-row" data-modes="field_map" style="max-width:46em;">
			<h4><?php esc_html_e( 'Price for each option', 'formpay-cm' ); ?></h4>
			<p class="description"><?php esc_html_e( 'Add one row per option. The form sends the option value (e.g. "medicine") and FormPay CM charges the matching amount. The price is never taken from the browser.', 'formpay-cm' ); ?></p>
			<table class="widefat striped" id="formpay-cm-map-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Option value', 'formpay-cm' ); ?></th>
						<th style="width:12em;"><?php esc_html_e( 'Amount (XAF)', 'formpay-cm' ); ?></th>
						<th style="width:4em;"></th>
					</tr>
				</thead>
				<tbody id="formpay-cm-map-rows">
					<?php
					$i = 0;
					foreach ( $rule->map as $value => $amount ) :
						?>
						<tr>
							<td><input type="text" name="map[<?php echo (int) $i; ?>][value]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="medicine" /></td>
							<td><input type="number" name="map[<?php echo (int) $i; ?>][amount]" value="<?php echo esc_attr( $amount ); ?>" min="100" placeholder="75000" /></td>
							<td><button type="button" class="button-link formpay-cm-remove-row"><?php esc_html_e( 'Remove', 'formpay-cm' ); ?></button></td>
						</tr>
						<?php
						++$i;
					endforeach;
					?>
				</tbody>
			</table>
			<p><button type="button" class="button" id="formpay-cm-add-map"><?php esc_html_e( '+ Add option', 'formpay-cm' ); ?></button></p>
		</div>
		<?php
	}

	/**
	 * The conditional rules table (mode: conditional).
	 */
	private function render_conditions_table( PriceRule $rule ) {
		?>
		<div class="formpay-cm-row" data-modes="conditional" style="max-width:60em;">
			<h4><?php esc_html_e( 'Rules', 'formpay-cm' ); ?></h4>
			<p class="description"><?php esc_html_e( 'Each row charges its amount when the field(s) match. The first matching row wins. Leave the second field blank to match on one field only.', 'formpay-cm' ); ?></p>
			<table class="widefat striped" id="formpay-cm-conditions-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'If field', 'formpay-cm' ); ?></th>
						<th><?php esc_html_e( 'equals', 'formpay-cm' ); ?></th>
						<th><?php esc_html_e( 'and field (optional)', 'formpay-cm' ); ?></th>
						<th><?php esc_html_e( 'equals', 'formpay-cm' ); ?></th>
						<th style="width:10em;"><?php esc_html_e( 'Amount (XAF)', 'formpay-cm' ); ?></th>
						<th style="width:4em;"></th>
					</tr>
				</thead>
				<tbody id="formpay-cm-conditions-rows">
					<?php
					$i = 0;
					foreach ( $rule->rules as $entry ) :
						$when  = isset( $entry['when'] ) ? (array) $entry['when'] : array();
						$pairs = array();
						foreach ( $when as $f => $v ) {
							$pairs[] = array( $f, $v );
						}
						$field_a = isset( $pairs[0] ) ? $pairs[0][0] : '';
						$value_a = isset( $pairs[0] ) ? $pairs[0][1] : '';
						$field_b = isset( $pairs[1] ) ? $pairs[1][0] : '';
						$value_b = isset( $pairs[1] ) ? $pairs[1][1] : '';
						$amount  = isset( $entry['amount'] ) ? $entry['amount'] : '';
						?>
						<tr>
							<td><input type="text" name="conditions[<?php echo (int) $i; ?>][field_a]" value="<?php echo esc_attr( $field_a ); ?>" placeholder="faculty" /></td>
							<td><input type="text" name="conditions[<?php echo (int) $i; ?>][value_a]" value="<?php echo esc_attr( $value_a ); ?>" placeholder="medicine" /></td>
							<td><input type="text" name="conditions[<?php echo (int) $i; ?>][field_b]" value="<?php echo esc_attr( $field_b ); ?>" placeholder="level" /></td>
							<td><input type="text" name="conditions[<?php echo (int) $i; ?>][value_b]" value="<?php echo esc_attr( $value_b ); ?>" placeholder="100" /></td>
							<td><input type="number" name="conditions[<?php echo (int) $i; ?>][amount]" value="<?php echo esc_attr( $amount ); ?>" min="100" placeholder="80000" /></td>
							<td><button type="button" class="button-link formpay-cm-remove-row"><?php esc_html_e( 'Remove', 'formpay-cm' ); ?></button></td>
						</tr>
						<?php
						++$i;
					endforeach;
					?>
				</tbody>
			</table>
			<p><button type="button" class="button" id="formpay-cm-add-condition"><?php esc_html_e( '+ Add rule', 'formpay-cm' ); ?></button></p>
		</div>
		<?php
	}

	/**
	 * Inline JS: show only the section relevant to the chosen pricing mode, and
	 * add/remove rows in the option and rule tables.
	 */
	private function render_script() {
		$remove = esc_js( __( 'Remove', 'formpay-cm' ) );
		?>
		<script>
		( function () {
			var form = document.getElementById( 'formpay-cm-rule-form' );
			if ( ! form ) { return; }
			var modeSelect = document.getElementById( 'formpay-cm-mode' );

			function refresh() {
				var mode = modeSelect.value;
				form.querySelectorAll( '.formpay-cm-row' ).forEach( function ( row ) {
					var modes = ( row.getAttribute( 'data-modes' ) || '' ).split( ' ' );
					row.style.display = modes.indexOf( mode ) !== -1 ? '' : 'none';
				} );
			}
			modeSelect.addEventListener( 'change', refresh );
			refresh();

			function addRow( tbody, cells ) {
				var i  = tbody.children.length;
				var tr = document.createElement( 'tr' );
				var html = '';
				cells.forEach( function ( c ) {
					html += '<td><input type="' + ( c.type || 'text' ) + '" name="' + c.name.replace( '%i', i ) + '"'
						+ ( c.min ? ' min="' + c.min + '"' : '' )
						+ ( c.placeholder ? ' placeholder="' + c.placeholder + '"' : '' ) + ' /></td>';
				} );
				html += '<td><button type="button" class="button-link formpay-cm-remove-row"><?php echo $remove; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button></td>';
				tr.innerHTML = html;
				tbody.appendChild( tr );
			}

			var addMap = document.getElementById( 'formpay-cm-add-map' );
			if ( addMap ) {
				addMap.addEventListener( 'click', function () {
					addRow( document.getElementById( 'formpay-cm-map-rows' ), [
						{ name: 'map[%i][value]', placeholder: 'medicine' },
						{ name: 'map[%i][amount]', type: 'number', min: 100, placeholder: '75000' }
					] );
				} );
			}

			var addCond = document.getElementById( 'formpay-cm-add-condition' );
			if ( addCond ) {
				addCond.addEventListener( 'click', function () {
					addRow( document.getElementById( 'formpay-cm-conditions-rows' ), [
						{ name: 'conditions[%i][field_a]', placeholder: 'faculty' },
						{ name: 'conditions[%i][value_a]', placeholder: 'medicine' },
						{ name: 'conditions[%i][field_b]', placeholder: 'level' },
						{ name: 'conditions[%i][value_b]', placeholder: '100' },
						{ name: 'conditions[%i][amount]', type: 'number', min: 100, placeholder: '80000' }
					] );
				} );
			}

			form.addEventListener( 'click', function ( e ) {
				if ( e.target && e.target.classList.contains( 'formpay-cm-remove-row' ) ) {
					e.preventDefault();
					var tr = e.target.closest( 'tr' );
					if ( tr ) { tr.parentNode.removeChild( tr ); }
				}
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Plain-language description of what a rule charges.
	 */
	private function human_summary( PriceRule $rule ) {
		switch ( $rule->mode ) {
			case PriceRule::MODE_FIXED:
				return sprintf( /* translators: %d amount */ __( '%d XAF (fixed)', 'formpay-cm' ), $rule->amount );
			case PriceRule::MODE_FIELD_MAP:
				return sprintf(
					/* translators: 1: field name, 2: number of options */
					__( 'Based on "%1$s" — %2$d priced options', 'formpay-cm' ),
					$rule->field,
					count( $rule->map )
				);
			case PriceRule::MODE_CONDITIONAL:
				return sprintf( /* translators: %d number of rules */ __( '%d conditional rules', 'formpay-cm' ), count( $rule->rules ) );
			case PriceRule::MODE_FIELD_VALUE:
				return sprintf( /* translators: %s field name */ __( 'Amount typed in "%s"', 'formpay-cm' ), $rule->field );
		}
		return '';
	}
}

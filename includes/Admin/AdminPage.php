<?php
/**
 * The single FormPay CM admin page. Owns the menu entry and renders the tab
 * navigation, delegating each tab body to its provider (Settings, FormPayments)
 * so every FormPay CM setting lives on one screen.
 *
 * @package FormPayCM
 */

namespace FormPayCM\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdminPage {

	const CAP  = 'manage_options';
	const PAGE = 'formpay-cm';

	/** @var Settings */
	private $settings;

	/** @var FormPayments */
	private $form_payments;

	public function __construct( Settings $settings, FormPayments $form_payments ) {
		$this->settings      = $settings;
		$this->form_payments = $form_payments;
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
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

	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$tabs = array(
			'connection' => __( 'Connection', 'formpay-cm' ),
			'rules'      => __( 'Payment Rules', 'formpay-cm' ),
		);

		$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connection'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $tabs[ $active ] ) ) {
			$active = 'connection';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'FormPay CM', 'formpay-cm' ); ?></h1>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="
						<?php
						echo esc_url(
							add_query_arg(
								array(
									'page' => self::PAGE,
									'tab'  => $slug,
								),
								admin_url( 'options-general.php' )
							)
						);
						?>
						"
						class="nav-tab <?php echo esc_attr( $active === $slug ? 'nav-tab-active' : '' ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<?php
			if ( 'rules' === $active ) {
				$this->form_payments->render_tab();
			} else {
				$this->settings->render_tab();
			}
			?>
		</div>
		<?php
	}
}

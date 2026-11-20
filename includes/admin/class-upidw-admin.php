<?php
/**
 * Admin: order metabox, transactions screen, assets.
 *
 * @package UPI_Direct_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Admin integration.
 */
final class UPIDW_Admin {

	const MENU_SLUG = 'upidw-transactions';

	/** @var UPIDW_Payment_Service */
	private $service;

	/** @var UPIDW_Repository */
	private $repo;

	/**
	 * Constructor.
	 *
	 * @param UPIDW_Payment_Service $service Service.
	 * @param UPIDW_Repository      $repo    Repository.
	 */
	public function __construct( UPIDW_Payment_Service $service, UPIDW_Repository $repo ) {
		$this->service = $service;
		$this->repo    = $repo;
	}

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_metabox' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'maybe_setup_notice' ) );
	}

	/**
	 * WooCommerce → UPI Transactions.
	 */
	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'UPI Transactions', 'upi-direct-for-woocommerce' ),
			__( 'UPI Transactions', 'upi-direct-for-woocommerce' ),
			UPIDW_Capabilities::verify_capability(),
			self::MENU_SLUG,
			array( $this, 'render_transactions_page' )
		);
	}

	/**
	 * Nudge until the gateway is configured.
	 */
	public function maybe_setup_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) || UPIDW_UPI::is_valid_vpa( (string) UPIDW_Settings::get( 'vpa' ) ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'plugins' !== $screen->id ) {
			return;
		}
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . UPIDW_Settings::GATEWAY_ID );
		printf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'UPI Direct is installed. Add your UPI ID to start accepting payments.', 'upi-direct-for-woocommerce' ),
			esc_url( $url ),
			esc_html__( 'Open settings', 'upi-direct-for-woocommerce' )
		);
	}

	/**
	 * Which screen ID hosts the order edit page (HPOS aware).
	 *
	 * @return string
	 */
	private function order_screen_id() {
		return OrderUtil::custom_orders_table_usage_is_enabled() ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
	}

	/**
	 * Resolve an order from the metabox callback argument (WP_Post on legacy storage, WC_Order on HPOS).
	 *
	 * @param WP_Post|WC_Order|null $post_or_order Argument.
	 * @return WC_Order|null
	 */
	private function resolve_order( $post_or_order ) {
		if ( $post_or_order instanceof WC_Order ) {
			return $post_or_order;
		}
		if ( $post_or_order instanceof WP_Post ) {
			$order = wc_get_order( $post_or_order->ID );
			return $order instanceof WC_Order ? $order : null;
		}
		return null;
	}

	/**
	 * Add the payment box to UPI orders.
	 *
	 * @param string                $screen_id     Screen / post type.
	 * @param WP_Post|WC_Order|null $post_or_order Post or order.
	 */
	public function add_metabox( $screen_id, $post_or_order = null ) {
		if ( ! in_array( $screen_id, array( 'shop_order', wc_get_page_screen_id( 'shop-order' ) ), true ) ) {
			return;
		}
		$order = $this->resolve_order( $post_or_order );
		if ( ! $order || UPIDW_Settings::GATEWAY_ID !== $order->get_payment_method() || ! UPIDW_Capabilities::can_verify() ) {
			return;
		}
		add_meta_box(
			'upidw-payment',
			__( 'UPI payment', 'upi-direct-for-woocommerce' ),
			array( $this, 'render_metabox' ),
			$this->order_screen_id(),
			'side',
			'high'
		);
	}

	/**
	 * Metabox body.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post or order.
	 */
	public function render_metabox( $post_or_order ) {
		$order = $this->resolve_order( $post_or_order );
		if ( ! $order ) {
			return;
		}
		$txn = $this->repo->get_latest_for_order( $order->get_id() );
		if ( ! $txn ) {
			echo '<p>' . esc_html__( 'No UPI transaction has been created for this order yet.', 'upi-direct-for-woocommerce' ) . '</p>';
			return;
		}

		$status_class = 'upidw-pill upidw-pill--' . sanitize_html_class( $txn->status );
		?>
		<div class="upidw-box" data-order="<?php echo esc_attr( $order->get_id() ); ?>">
			<p><span class="<?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( UPIDW_Status::label( $txn->status ) ); ?></span></p>
			<dl class="upidw-dl">
				<dt><?php esc_html_e( 'Amount', 'upi-direct-for-woocommerce' ); ?></dt>
				<dd><?php echo wp_kses_post( wc_price( $txn->amount, array( 'currency' => $txn->currency ) ) ); ?></dd>
				<dt><?php esc_html_e( 'Customer reference (UTR)', 'upi-direct-for-woocommerce' ); ?></dt>
				<dd><code><?php echo esc_html( $txn->customer_reference ? $txn->customer_reference : '—' ); ?></code></dd>
				<dt><?php esc_html_e( 'Our transaction ID', 'upi-direct-for-woocommerce' ); ?></dt>
				<dd><code><?php echo esc_html( $txn->txn_ref ); ?></code></dd>
				<dt><?php esc_html_e( 'Paid to', 'upi-direct-for-woocommerce' ); ?></dt>
				<dd><code><?php echo esc_html( $txn->vpa ); ?></code></dd>
				<?php if ( $txn->verified_at ) : ?>
					<dt><?php esc_html_e( 'Verified', 'upi-direct-for-woocommerce' ); ?></dt>
					<dd>
						<?php
						$by = $txn->verified_by ? get_userdata( (int) $txn->verified_by ) : false;
						echo esc_html( $this->format_time( $txn->verified_at ) . ( $by ? ' · ' . $by->display_name : '' ) );
						?>
					</dd>
				<?php endif; ?>
			</dl>

			<?php if ( in_array( $txn->status, UPIDW_Status::open(), true ) && ! $order->is_paid() ) : ?>
				<p class="upidw-warn">
					<?php esc_html_e( 'Confirm only after you see this exact amount credited in your bank account.', 'upi-direct-for-woocommerce' ); ?>
				</p>
				<label for="upidw-note" class="screen-reader-text"><?php esc_html_e( 'Note (optional)', 'upi-direct-for-woocommerce' ); ?></label>
				<textarea id="upidw-note" class="upidw-note" rows="2" placeholder="<?php esc_attr_e( 'Note (optional), saved on the order', 'upi-direct-for-woocommerce' ); ?>"></textarea>
				<p class="upidw-actions">
					<button type="button" class="button button-primary" data-upidw-action="verify" data-order="<?php echo esc_attr( $order->get_id() ); ?>"><?php esc_html_e( 'Confirm payment received', 'upi-direct-for-woocommerce' ); ?></button>
					<button type="button" class="button" data-upidw-action="reject" data-order="<?php echo esc_attr( $order->get_id() ); ?>"><?php esc_html_e( 'Reject', 'upi-direct-for-woocommerce' ); ?></button>
				</p>
				<p class="upidw-feedback" role="status" aria-live="polite"></p>
			<?php endif; ?>

			<?php $events = $this->repo->get_events( $order->get_id(), 20 ); ?>
			<?php if ( $events ) : ?>
				<details class="upidw-history">
					<summary><?php esc_html_e( 'Payment history', 'upi-direct-for-woocommerce' ); ?></summary>
					<ol>
						<?php foreach ( $events as $event ) : ?>
							<li>
								<time><?php echo esc_html( $this->format_time( $event->created_at ) ); ?></time>
								<?php echo esc_html( $event->message ? $event->message : $event->event ); ?>
							</li>
						<?php endforeach; ?>
					</ol>
				</details>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Format a GMT MySQL time in the site's timezone.
	 *
	 * @param string $mysql_gmt Datetime.
	 * @return string
	 */
	private function format_time( $mysql_gmt ) {
		return get_date_from_gmt( $mysql_gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}

	/**
	 * Admin assets.
	 */
	public function enqueue() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		$ids = array( $this->order_screen_id(), 'woocommerce_page_' . self::MENU_SLUG );
		if ( ! in_array( $screen->id, $ids, true ) ) {
			return;
		}
		wp_enqueue_style( 'upidw-admin', UPIDW_URL . 'assets/css/admin.css', array(), UPIDW_VERSION );
		wp_enqueue_script( 'upidw-admin', UPIDW_URL . 'assets/js/admin.js', array( 'wp-api-fetch', 'wp-i18n' ), UPIDW_VERSION, true );
		wp_set_script_translations( 'upidw-admin', 'upi-direct-for-woocommerce', UPIDW_PATH . 'languages' );
	}

	/**
	 * Transactions screen.
	 */
	public function render_transactions_page() {
		if ( ! UPIDW_Capabilities::can_verify() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'upi-direct-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		$table = new UPIDW_Transactions_List_Table( $this->repo );
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'UPI Transactions', 'upi-direct-for-woocommerce' ); ?></h1>
			<hr class="wp-header-end">
			<p class="description"><?php esc_html_e( 'Every UPI payment request and its history. "Awaiting verification" rows are waiting for you to check your bank statement.', 'upi-direct-for-woocommerce' ); ?></p>
			<?php $table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
				<?php
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( isset( $_GET['status'] ) ) {
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended
					echo '<input type="hidden" name="status" value="' . esc_attr( sanitize_key( wp_unslash( $_GET['status'] ) ) ) . '">';
				}
				$table->search_box( __( 'Search orders or references', 'upi-direct-for-woocommerce' ), 'upidw' );
				$table->display();
				?>
			</form>
			<p class="upidw-feedback" role="status" aria-live="polite"></p>
		</div>
		<?php
	}
}

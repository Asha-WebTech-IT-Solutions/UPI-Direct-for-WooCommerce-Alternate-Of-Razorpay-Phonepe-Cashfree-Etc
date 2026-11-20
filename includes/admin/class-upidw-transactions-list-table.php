<?php
/**
 * Transactions list table.
 *
 * @package UPI_Direct_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists UPI transactions.
 */
final class UPIDW_Transactions_List_Table extends WP_List_Table {

	/** @var UPIDW_Repository */
	private $repo;

	/**
	 * Constructor.
	 *
	 * @param UPIDW_Repository $repo Repository.
	 */
	public function __construct( UPIDW_Repository $repo ) {
		parent::__construct(
			array(
				'singular' => 'transaction',
				'plural'   => 'transactions',
				'ajax'     => false,
			)
		);
		$this->repo = $repo;
	}

	/**
	 * Current status filter (whitelisted).
	 *
	 * @return string
	 */
	private function current_status() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		return array_key_exists( $status, UPIDW_Status::labels() ) ? $status : '';
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'created_at' => __( 'Date', 'upi-direct-for-woocommerce' ),
			'order_id'   => __( 'Order', 'upi-direct-for-woocommerce' ),
			'amount'     => __( 'Amount', 'upi-direct-for-woocommerce' ),
			'reference'  => __( 'Reference (UTR)', 'upi-direct-for-woocommerce' ),
			'status'     => __( 'Status', 'upi-direct-for-woocommerce' ),
			'verified'   => __( 'Verified by', 'upi-direct-for-woocommerce' ),
			'actions'    => __( 'Actions', 'upi-direct-for-woocommerce' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'created_at' => array( 'created_at', true ),
			'order_id'   => array( 'order_id', false ),
			'amount'     => array( 'amount', false ),
			'status'     => array( 'status', false ),
		);
	}

	/**
	 * Status filter links.
	 *
	 * @return array<string,string>
	 */
	protected function get_views() {
		$counts  = $this->repo->counts_by_status();
		$current = $this->current_status();
		$base    = admin_url( 'admin.php?page=' . UPIDW_Admin::MENU_SLUG );
		$views   = array();

		$views['all'] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( $base ),
			'' === $current ? ' class="current" aria-current="page"' : '',
			esc_html__( 'All', 'upi-direct-for-woocommerce' ),
			array_sum( $counts )
		);
		foreach ( UPIDW_Status::labels() as $slug => $label ) {
			$views[ $slug ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'status', $slug, $base ) ),
				$current === $slug ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				isset( $counts[ $slug ] ) ? (int) $counts[ $slug ] : 0
			);
		}
		return $views;
	}

	/**
	 * Load rows.
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$args = array(
			'status'   => $this->current_status(),
			'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'orderby'  => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'id',
			'order'    => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc',
			'per_page' => 20,
			'page'     => $this->get_pagenum(),
		);
		// phpcs:enable

		$this->items = $this->repo->query( $args );
		$total       = $this->repo->count( $args );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $args['per_page'],
			)
		);
	}

	/**
	 * Empty state.
	 */
	public function no_items() {
		esc_html_e( 'No UPI transactions yet. They appear here as soon as a customer places an order with UPI Direct.', 'upi-direct-for-woocommerce' );
	}

	/**
	 * Default cell renderer.
	 *
	 * @param object $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'created_at':
				return esc_html( get_date_from_gmt( $item->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );
			case 'order_id':
				$order = wc_get_order( (int) $item->order_id );
				if ( ! $order ) {
					return esc_html( '#' . $item->order_id );
				}
				return sprintf( '<a href="%s"><strong>#%s</strong></a>', esc_url( $order->get_edit_order_url() ), esc_html( $order->get_order_number() ) );
			case 'amount':
				return wp_kses_post( wc_price( $item->amount, array( 'currency' => $item->currency ) ) );
			case 'reference':
				return $item->customer_reference ? '<code>' . esc_html( $item->customer_reference ) . '</code>' : '<span aria-hidden="true">—</span>';
			case 'status':
				return sprintf(
					'<span class="upidw-pill upidw-pill--%s">%s</span>',
					esc_attr( sanitize_html_class( $item->status ) ),
					esc_html( UPIDW_Status::label( $item->status ) )
				);
			case 'verified':
				if ( ! $item->verified_at ) {
					return '<span aria-hidden="true">—</span>';
				}
				$user = $item->verified_by ? get_userdata( (int) $item->verified_by ) : false;
				return esc_html( $user ? $user->display_name : ( $item->provider ? $item->provider : __( 'system', 'upi-direct-for-woocommerce' ) ) );
			case 'actions':
				if ( ! in_array( $item->status, UPIDW_Status::open(), true ) ) {
					return '';
				}
				return sprintf(
					'<button type="button" class="button button-small button-primary" data-upidw-action="verify" data-order="%1$d" data-confirm="%3$s">%2$s</button> <button type="button" class="button button-small" data-upidw-action="reject" data-order="%1$d">%4$s</button>',
					(int) $item->order_id,
					esc_html__( 'Confirm', 'upi-direct-for-woocommerce' ),
					esc_attr__( 'Confirm only if this exact amount is credited in your bank account. Continue?', 'upi-direct-for-woocommerce' ),
					esc_html__( 'Reject', 'upi-direct-for-woocommerce' )
				);
		}
		return '';
	}
}

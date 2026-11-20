<?php
/**
 * Data access.
 *
 * @package UPI_Direct_For_WooCommerce
 */

// Custom tables: direct queries are required. Every dynamic value goes through $wpdb->prepare()
// or $wpdb->insert()/update(); table names come from $wpdb->prefix only.
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

defined( 'ABSPATH' ) || exit;

/**
 * Repository for transactions and events.
 */
class UPIDW_Repository {

	const COLUMNS = array(
		'order_id',
		'txn_ref',
		'amount',
		'currency',
		'vpa',
		'status',
		'customer_reference',
		'active_reference',
		'attempts',
		'provider',
		'provider_reference',
		'verified_by',
		'verified_at',
		'submitted_at',
	);

	/**
	 * Transactions table.
	 *
	 * @return string
	 */
	public function table() {
		global $wpdb;
		return $wpdb->prefix . 'upidw_transactions';
	}

	/**
	 * Events table.
	 *
	 * @return string
	 */
	public function events_table() {
		global $wpdb;
		return $wpdb->prefix . 'upidw_events';
	}

	/**
	 * Current UTC time in MySQL format.
	 *
	 * @return string
	 */
	public function now() {
		return current_time( 'mysql', true );
	}

	/**
	 * Create a transaction.
	 *
	 * @param array $data Column => value.
	 * @return int|false Insert ID.
	 */
	public function create( array $data ) {
		global $wpdb;
		$row = array_merge(
			array(
				'currency'           => 'INR',
				'status'             => UPIDW_Status::INITIATED,
				'customer_reference' => null,
				'active_reference'   => null,
				'attempts'           => 0,
				'provider'           => '',
			),
			array_intersect_key( $data, array_flip( self::COLUMNS ) )
		);

		$row['created_at'] = $this->now();
		$row['updated_at'] = $row['created_at'];

		$formats = array();
		foreach ( $row as $key => $value ) {
			$formats[] = in_array( $key, array( 'order_id', 'attempts', 'verified_by' ), true ) ? '%d' : '%s';
		}

		$wpdb->suppress_errors( true );
		$ok = $wpdb->insert( $this->table(), $row, $formats );
		$wpdb->suppress_errors( false );
		if ( ! $ok ) {
			return false;
		}
		$id = (int) $wpdb->insert_id;

		/**
		 * Fires after a transaction row is created.
		 *
		 * @param int   $id  Transaction ID.
		 * @param array $row Row data.
		 */
		do_action( 'upidw_transaction_created', $id, $row );
		return $id;
	}

	/**
	 * Fetch by ID.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public function get( $id ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );
	}

	/**
	 * Fetch by our transaction reference.
	 *
	 * @param string $txn_ref The "tr" value.
	 * @return object|null
	 */
	public function get_by_txn_ref( $txn_ref ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE txn_ref = %s", (string) $txn_ref ) );
	}

	/**
	 * Latest transaction of an order.
	 *
	 * @param int $order_id Order ID.
	 * @return object|null
	 */
	public function get_latest_for_order( $order_id ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id DESC LIMIT 1", (int) $order_id ) );
	}

	/**
	 * Atomic state transition. Only succeeds if the row is still in one of $from.
	 * This is what prevents double-completion under concurrent requests.
	 *
	 * @param int      $id   Transaction ID.
	 * @param string[] $from Allowed current statuses.
	 * @param string   $to   New status.
	 * @param array    $data Extra columns to set (null => SQL NULL).
	 * @return bool True when exactly one row changed.
	 */
	public function transition( $id, array $from, $to, array $data = array() ) {
		global $wpdb;
		$table = $this->table();
		$set   = array( 'status = %s', 'updated_at = %s' );
		$args  = array( $to, $this->now() );

		foreach ( $data as $column => $value ) {
			if ( ! in_array( $column, self::COLUMNS, true ) || 'status' === $column ) {
				continue;
			}
			if ( null === $value ) {
				$set[] = "{$column} = NULL";
			} else {
				$set[]  = "{$column} = %s";
				$args[] = $value;
			}
		}

		$args[] = (int) $id;
		$args   = array_merge( $args, array_values( $from ) );
		$in     = implode( ',', array_fill( 0, count( $from ), '%s' ) );
		$sql    = "UPDATE {$table} SET " . implode( ', ', $set ) . " WHERE id = %d AND status IN ({$in})";

		return 1 === $wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Count a submission attempt and return the new total.
	 *
	 * @param int $id Transaction ID.
	 * @return int
	 */
	public function bump_attempts( $id ) {
		global $wpdb;
		$table = $this->table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts = attempts + 1, updated_at = %s WHERE id = %d", $this->now(), (int) $id ) );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM {$table} WHERE id = %d", (int) $id ) );
	}

	/**
	 * Attach a customer reference. The UNIQUE index on active_reference rejects duplicates.
	 *
	 * @param int    $id        Transaction ID.
	 * @param string $reference Normalised reference.
	 * @return true|string True on success, or "duplicate" | "state" | "error".
	 */
	public function attach_reference( $id, $reference ) {
		global $wpdb;
		$table = $this->table();
		$now   = $this->now();

		$wpdb->suppress_errors( true );
		$rows  = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET customer_reference = %s, active_reference = %s, status = %s, submitted_at = %s, updated_at = %s WHERE id = %d AND status IN (%s, %s)",
				$reference,
				$reference,
				UPIDW_Status::SUBMITTED,
				$now,
				$now,
				(int) $id,
				UPIDW_Status::INITIATED,
				UPIDW_Status::SUBMITTED
			)
		);
		$error = (string) $wpdb->last_error;
		$wpdb->suppress_errors( false );

		if ( false === $rows ) {
			return false !== stripos( $error, 'duplicate' ) ? 'duplicate' : 'error';
		}
		return $rows > 0 ? true : 'state';
	}

	/**
	 * Build WHERE clause for list queries.
	 *
	 * @param array $args status, search.
	 * @return array{0:string,1:array}
	 */
	private function where( array $args ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) && array_key_exists( $args['status'], UPIDW_Status::labels() ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['search'] ) ) {
			$term = trim( (string) $args['search'] );
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			if ( ctype_digit( $term ) ) {
				$where[]  = '(order_id = %d OR customer_reference LIKE %s OR txn_ref LIKE %s)';
				$params[] = (int) $term;
			} else {
				$where[] = '(customer_reference LIKE %s OR txn_ref LIKE %s OR provider_reference LIKE %s)';
			}
			$params[] = $like;
			$params[] = $like;
			if ( ! ctype_digit( $term ) ) {
				$params[] = $like;
			}
		}
		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * Paged list.
	 *
	 * @param array $args status, search, orderby, order, per_page, page.
	 * @return object[]
	 */
	public function query( array $args = array() ) {
		global $wpdb;
		$table                = $this->table();
		list( $where, $params ) = $this->where( $args );

		$orderby  = isset( $args['orderby'] ) && in_array( $args['orderby'], array( 'id', 'order_id', 'amount', 'status', 'created_at' ), true ) ? $args['orderby'] : 'id';
		$order    = isset( $args['order'] ) && 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$per_page = isset( $args['per_page'] ) ? max( 1, min( 200, (int) $args['per_page'] ) ) : 20;
		$offset   = ( max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 ) - 1 ) * $per_page;

		$sql      = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;

		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Count rows for a filter.
	 *
	 * @param array $args status, search.
	 * @return int
	 */
	public function count( array $args = array() ) {
		global $wpdb;
		$table                = $this->table();
		list( $where, $params ) = $this->where( $args );
		$sql                  = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Row counts keyed by status.
	 *
	 * @return array<string,int>
	 */
	public function counts_by_status() {
		global $wpdb;
		$table  = $this->table();
		$rows   = (array) $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status" );
		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ $row->status ] = (int) $row->total;
		}
		return $counts;
	}

	/**
	 * Add a history event.
	 *
	 * @param int    $transaction_id Transaction ID (0 if none).
	 * @param int    $order_id       Order ID.
	 * @param string $event          Event slug.
	 * @param string $message        Human message.
	 * @param int    $user_id        Acting user (0 = system/customer).
	 */
	public function add_event( $transaction_id, $order_id, $event, $message = '', $user_id = 0 ) {
		global $wpdb;
		$wpdb->insert(
			$this->events_table(),
			array(
				'transaction_id' => (int) $transaction_id,
				'order_id'       => (int) $order_id,
				'event'          => substr( sanitize_key( $event ), 0, 40 ),
				'message'        => wp_strip_all_tags( (string) $message ),
				'user_id'        => (int) $user_id,
				'created_at'     => $this->now(),
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Events for an order, newest first.
	 *
	 * @param int $order_id Order ID.
	 * @param int $limit    Max rows.
	 * @return object[]
	 */
	public function get_events( $order_id, $limit = 50 ) {
		global $wpdb;
		$table = $this->events_table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id DESC LIMIT %d", (int) $order_id, (int) $limit ) );
	}
}

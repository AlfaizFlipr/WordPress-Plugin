<?php
/**
 * Stats helper — dashboard cards + filtered order queries.
 *
 * All methods accept an optional $store_ids scope (array of website ids). When
 * provided, only orders from those connected stores are counted/returned — this
 * powers the per-client portal so each client sees only their own orders.
 *
 * @package CourierConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CC_Stats {

	/**
	 * Build the meta clause that scopes a query to specific stores.
	 *
	 * @param array|null $store_ids Website ids, or null for no scope.
	 * @return array|null
	 */
	protected static function store_clause( $store_ids ) {
		if ( null === $store_ids ) {
			return null;
		}
		$store_ids = array_map( 'intval', (array) $store_ids );
		if ( empty( $store_ids ) ) {
			// Client with no stores — force an impossible match.
			$store_ids = array( 0 );
		}
		return array(
			'key'     => '_cc_source_store',
			'value'   => $store_ids,
			'compare' => 'IN',
		);
	}

	/**
	 * Counts for the stat cards, keyed by internal shipment status.
	 *
	 * @param array|null $store_ids Optional store scope.
	 * @return array
	 */
	public static function cards( $store_ids = null ) {
		$store_id = is_array( $store_ids ) && count( $store_ids ) === 1 ? (int) $store_ids[0] : null;

		$counts = array(
			'total'      => self::count_by_status( null,         false, $store_id ),
			'pending'    => self::count_by_status( null,         true,  $store_id ),
			'booked'     => self::count_by_status( 'booked',     false, $store_id ),
			'in-transit' => self::count_by_status( 'in-transit', false, $store_id ),
			'delivered'  => self::count_by_status( 'delivered',  false, $store_id ),
		);

		return $counts;
	}

	/**
	 * Build the store JOIN clause.
	 * Always joins cc_websites to ensure only active-store orders are counted.
	 * When $store_id is provided, also restricts to that specific store.
	 *
	 * @param int|null $store_id Specific store id, or null for all active stores.
	 * @return string SQL fragment (already prepared if store_id given).
	 */
	protected static function build_store_join( $store_id = null ) {
		global $wpdb;
		$cc_websites = $wpdb->prefix . 'cc_websites';

		if ( $store_id ) {
			return $wpdb->prepare(
				"INNER JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_cc_source_store' AND ms.meta_value = %d
				 INNER JOIN {$cc_websites} cw ON cw.id = %d AND cw.status = 'active'",
				$store_id,
				$store_id
			);
		}

		// All active stores — join on store meta value matching an active cc_websites row.
		return "INNER JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_cc_source_store'
		        INNER JOIN {$cc_websites} cw ON cw.id = CAST(ms.meta_value AS UNSIGNED) AND cw.status = 'active'";
	}

	/**
	 * Count orders by ship_status meta using direct DB query.
	 * Only counts orders belonging to active connected stores.
	 *
	 * @param string|null $ship_status  _cc_ship_status value, or null for all orders.
	 * @param bool        $pending_only When true, counts orders with no AWB or status=pending.
	 * @param int|null    $store_id     Optional _cc_source_store filter.
	 * @return int
	 */
	protected static function count_by_status( $ship_status, $pending_only = false, $store_id = null ) {
		global $wpdb;

		$store_join = self::build_store_join( $store_id );

		if ( $pending_only ) {
			$sql = "
				SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				$store_join
				WHERE p.post_type = 'shop_order'
				  AND p.post_status != 'trash'
				  AND (
				      p.ID NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_cc_awb')
				      OR p.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_cc_ship_status' AND meta_value = 'pending')
				  )
			";
			return (int) $wpdb->get_var( $sql );
		}

		if ( null === $ship_status ) {
			$sql = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p $store_join WHERE p.post_type='shop_order' AND p.post_status!='trash'";
			return (int) $wpdb->get_var( $sql );
		}

		// Specific ship status.
		$sql = $wpdb->prepare( "
			SELECT COUNT(DISTINCT p.ID)
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_cc_ship_status' AND m.meta_value = %s
			$store_join
			WHERE p.post_type = 'shop_order' AND p.post_status != 'trash'
		", $ship_status );
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Get order IDs matching meta filters — direct DB, bypasses broken wc_get_orders meta_query.
	 * Only returns orders belonging to active connected stores.
	 *
	 * @param string|null $ship_status  _cc_ship_status value or null for all.
	 * @param bool        $pending_only Pending logic (no AWB or status=pending).
	 * @param int|null    $store_id     Optional store scope.
	 * @param int         $limit        Max rows.
	 * @param int         $offset       Pagination offset.
	 * @param string      $search       Optional billing name/email search.
	 * @return array {ids: int[], total: int}
	 */
	protected static function get_order_ids( $ship_status, $pending_only = false, $store_id = null, $limit = 20, $offset = 0, $search = '' ) {
		global $wpdb;

		$store_join = self::build_store_join( $store_id );

		$status_join  = '';
		$status_where = '';
		if ( $pending_only ) {
			$status_where = "AND ( p.ID NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_cc_awb')
			                   OR p.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_cc_ship_status' AND meta_value='pending') )";
		} elseif ( null !== $ship_status ) {
			$status_join = $wpdb->prepare( "INNER JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key='_cc_ship_status' AND m.meta_value=%s", $ship_status );
		}

		$search_where = '';
		if ( $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$search_where = $wpdb->prepare(
				"AND p.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_billing_first_name','_billing_last_name','_billing_email','_billing_phone') AND meta_value LIKE %s)",
				$like
			);
		}

		$base = "FROM {$wpdb->posts} p $status_join $store_join WHERE p.post_type='shop_order' AND p.post_status!='trash' $status_where $search_where";

		$total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT p.ID) $base" );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID $base ORDER BY p.ID DESC LIMIT %d OFFSET %d", $limit, $offset ) );

		return array( 'ids' => array_map( 'intval', $ids ), 'total' => $total );
	}

	/**
	 * Query orders for the dashboard table with filters + pagination.
	 *
	 * @param array      $filters   {search, ship_status, payment, date_from, date_to, store, paged, per_page}.
	 * @param array|null $store_ids Optional hard store scope (client portal).
	 * @return array {orders: WC_Order[], total:int, pages:int, paged:int}
	 */
	public static function query_orders( $filters, $store_ids = null ) {
		$paged    = max( 1, (int) ( $filters['paged'] ?? 1 ) );
		$per_page = (int) ( $filters['per_page'] ?? 20 );
		$offset   = ( $paged - 1 ) * $per_page;

		$ship_status  = ! empty( $filters['ship_status'] ) ? sanitize_text_field( $filters['ship_status'] ) : null;
		$pending_only = ( 'pending' === $ship_status );
		$ship_status  = $pending_only ? null : $ship_status;

		// Store scope: hard client scope wins, then admin filter.
		$store_id = null;
		if ( is_array( $store_ids ) && ! empty( $store_ids ) ) {
			$store_id = (int) $store_ids[0];
		} elseif ( null === $store_ids && ! empty( $filters['store'] ) ) {
			$store_id = (int) $filters['store'];
		}

		$search = ! empty( $filters['search'] ) ? sanitize_text_field( $filters['search'] ) : '';

		// Get IDs via direct DB (bypasses broken wc_get_orders meta_query in WC 8+/10+).
		$result = self::get_order_ids( $ship_status, $pending_only, $store_id, $per_page, $offset, $search );

		if ( empty( $result['ids'] ) ) {
			return array( 'orders' => array(), 'total' => 0, 'pages' => 0, 'paged' => $paged );
		}

		// Load full WC_Order objects for the matched IDs.
		$orders = wc_get_orders( array(
			'include' => $result['ids'],
			'limit'   => $per_page,
			'orderby' => 'none',
		) );

		// Sort to match DB order (DESC by ID).
		usort( $orders, fn( $a, $b ) => $b->get_id() - $a->get_id() );

		return array(
			'orders' => $orders,
			'total'  => $result['total'],
			'pages'  => (int) ceil( $result['total'] / $per_page ),
			'paged'  => $paged,
		);
	}
}

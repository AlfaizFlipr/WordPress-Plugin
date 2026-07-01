<?php
/**
 * Client accounts — links connected stores to a WordPress user (role
 * "ns_client") so each store owner can log into the front-end Client Portal
 * and manage only their own shipments.
 *
 * @package CourierConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CC_Clients {

	const ROLE = 'ns_client';

	/**
	 * Store ids owned by a client user.
	 *
	 * @param int $user_id User id.
	 * @return int[]
	 */
	public static function user_store_ids( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return array();
		}
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . $wpdb->prefix . 'cc_websites WHERE client_user_id = %d',
				$user_id
			)
		);
		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Whether the current (or given) user is a client.
	 *
	 * @param WP_User|null $user Optional user.
	 * @return bool
	 */
	public static function is_client( $user = null ) {
		$user = $user ? $user : wp_get_current_user();
		return $user && in_array( self::ROLE, (array) $user->roles, true );
	}

	/**
	 * Does the current user own the store that produced this order?
	 * Admins always pass.
	 *
	 * @param int $order_id WC order id.
	 * @return bool
	 */
	public static function can_manage_order( $order_id ) {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		if ( ! self::is_client() ) {
			return false;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}
		$store = (int) $order->get_meta( '_cc_source_store' );
		return in_array( $store, self::user_store_ids( get_current_user_id() ), true );
	}

	/**
	 * Create (or find) a client user and link them to a store.
	 *
	 * @param string $email    Client email.
	 * @param string $name     Display / store name.
	 * @param int    $store_id Website id.
	 * @return int|WP_Error User id.
	 */
	public static function create_and_link( $email, $name, $store_id ) {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'cc_bad_email', 'A valid client email is required.' );
		}

		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$user_id = $user->ID;
			// Ensure they carry the client role (without stripping existing roles).
			$wp_user = new WP_User( $user_id );
			if ( ! self::is_client( $wp_user ) && ! user_can( $user_id, 'manage_woocommerce' ) ) {
				$wp_user->add_role( self::ROLE );
			}
		} else {
			$password = wp_generate_password( 16, true );
			$username = self::unique_username( $email );
			$user_id  = wp_insert_user(
				array(
					'user_login'   => $username,
					'user_email'   => $email,
					'user_pass'    => $password,
					'display_name' => $name ? $name : $username,
					'role'         => self::ROLE,
				)
			);
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
			// Send the new client their login (best-effort).
			wp_new_user_notification( $user_id, null, 'user' );
		}

		self::link_store( $store_id, $user_id );
		return $user_id;
	}

	/**
	 * Link an existing store to a user id.
	 *
	 * @param int $store_id Website id.
	 * @param int $user_id  User id.
	 */
	public static function link_store( $store_id, $user_id ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'cc_websites',
			array( 'client_user_id' => (int) $user_id ),
			array( 'id' => (int) $store_id )
		);
	}

	/**
	 * Generate a unique username from an email.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	protected static function unique_username( $email ) {
		$base = sanitize_user( current( explode( '@', $email ) ), true );
		$base = $base ? $base : 'client';
		$name = $base;
		$i    = 1;
		while ( username_exists( $name ) ) {
			$name = $base . $i;
			$i++;
		}
		return $name;
	}

	/**
	 * Display name for a client user id.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function label( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return '—';
		}
		$user = get_user_by( 'id', $user_id );
		return $user ? $user->display_name . ' (' . $user->user_email . ')' : '#' . $user_id;
	}
}

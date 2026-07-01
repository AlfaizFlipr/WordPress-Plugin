<?php
/**
 * Admin controller — menus, asset loading, page routing, settings save.
 *
 * @package CourierConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CC_Admin {

	const CAP = 'manage_woocommerce';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_init', array( $this, 'handle_post' ) );
	}

	/* --------------------------------------------------------------------- */
	/* Menu                                                                  */
	/* --------------------------------------------------------------------- */

	public function menu() {
		$icon = 'dashicons-airplane';

		add_menu_page(
			'Naya Setu Courier',
			'Naya Setu',
			self::CAP,
			'cc-dashboard',
			array( $this, 'page_dashboard' ),
			$icon,
			56
		);

		add_submenu_page( 'cc-dashboard', 'Dashboard', 'Dashboard', self::CAP, 'cc-dashboard', array( $this, 'page_dashboard' ) );
		add_submenu_page( 'cc-dashboard', 'Orders', 'Orders', self::CAP, 'cc-orders', array( $this, 'page_orders' ) );
		add_submenu_page( 'cc-dashboard', 'Connected Stores', 'Connected Stores', self::CAP, 'cc-websites', array( $this, 'page_websites' ) );
		add_submenu_page( 'cc-dashboard', 'Settings', 'Settings', self::CAP, 'cc-settings', array( $this, 'page_settings' ) );
		add_submenu_page( 'cc-dashboard', 'Logs', 'Logs', self::CAP, 'cc-logs', array( $this, 'page_logs' ) );
	}

	/* --------------------------------------------------------------------- */
	/* Assets                                                                */
	/* --------------------------------------------------------------------- */

	public function assets( $hook ) {
		if ( false === strpos( $hook, 'cc-' ) ) {
			return;
		}
		wp_enqueue_style( 'cc-admin', CC_PLUGIN_URL . 'admin/assets/admin.css', array(), CC_VERSION );
		wp_enqueue_script( 'cc-admin', CC_PLUGIN_URL . 'admin/assets/admin.js', array( 'jquery' ), CC_VERSION, true );
		wp_localize_script(
			'cc-admin',
			'CC',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'cc_admin' ),
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* POST handlers (settings, stores)                                      */
	/* --------------------------------------------------------------------- */

	public function handle_post() {
		if ( empty( $_POST['cc_action'] ) || ! current_user_can( self::CAP ) ) {
			return;
		}

		$action = sanitize_key( $_POST['cc_action'] );

		if ( 'save_settings' === $action ) {
			check_admin_referer( 'cc_settings' );
			$this->save_settings();
		}

		if ( 'register_pickup' === $action ) {
			check_admin_referer( 'cc_settings' );
			$this->register_pickup();
		}

		if ( 'store_action' === $action ) {
			check_admin_referer( 'cc_stores' );
			$this->store_action();
		}
	}

	protected function save_settings() {
		$fields = array(
			'api_token', 'environment', 'pickup_name', 'pickup_phone', 'pickup_address',
			'pickup_city', 'pickup_state', 'pickup_pincode', 'pickup_country',
			'default_weight', 'default_length', 'default_breadth', 'default_height', 'payment_default',
		);
		$values = array();
		foreach ( $fields as $f ) {
			$values[ $f ] = isset( $_POST[ $f ] ) ? sanitize_text_field( wp_unslash( $_POST[ $f ] ) ) : '';
		}
		// Checkbox — only present in $_POST when checked.
		$values['auto_push_on_receive'] = ! empty( $_POST['auto_push_on_receive'] ) ? '1' : '0';
		CC_Settings::update( $values );
		$this->redirect_notice( 'cc-settings', 'saved' );
	}

	protected function register_pickup() {
		$api = new CC_Delhivery_API();
		$res = $api->create_warehouse(
			array(
				'name'    => CC_Settings::get( 'pickup_name' ),
				'phone'   => CC_Settings::get( 'pickup_phone' ),
				'address' => CC_Settings::get( 'pickup_address' ),
				'city'    => CC_Settings::get( 'pickup_city' ),
				'state'   => CC_Settings::get( 'pickup_state' ),
				'pincode' => CC_Settings::get( 'pickup_pincode' ),
				'country' => CC_Settings::get( 'pickup_country', 'India' ),
			)
		);
		$this->redirect_notice( 'cc-settings', $res['ok'] ? 'pickup_ok' : 'pickup_fail' );
	}

	protected function store_action() {
		$sub = sanitize_key( $_POST['sub_action'] ?? '' );
		$id  = (int) ( $_POST['store_id'] ?? 0 );

		if ( 'add' === $sub ) {
			$store = CC_Website::connect(
				array(
					'store_name'   => $_POST['store_name'] ?? '',
					'store_url'    => $_POST['store_url'] ?? '',
					'callback_url' => $_POST['callback_url'] ?? '',
				)
			);
			// Optionally create + link a client account.
			$email = sanitize_email( wp_unslash( $_POST['client_email'] ?? '' ) );
			if ( ! is_wp_error( $store ) && $email ) {
				CC_Clients::create_and_link( $email, $store->store_name, $store->id );
			}
		} elseif ( 'assign_client' === $sub && $id ) {
			$email = sanitize_email( wp_unslash( $_POST['client_email'] ?? '' ) );
			$store = CC_Website::get( $id );
			if ( $email && $store ) {
				CC_Clients::create_and_link( $email, $store->store_name, $id );
			}
		} elseif ( 'disable' === $sub && $id ) {
			CC_Website::update_status( $id, 'disabled' );
		} elseif ( 'enable' === $sub && $id ) {
			CC_Website::update_status( $id, 'active' );
		} elseif ( 'delete' === $sub && $id ) {
			CC_Website::delete( $id );
		}
		$this->redirect_notice( 'cc-websites', 'store_done' );
	}

	protected function redirect_notice( $page, $notice ) {
		wp_safe_redirect( admin_url( 'admin.php?page=' . $page . '&cc_notice=' . $notice ) );
		exit;
	}

	/* --------------------------------------------------------------------- */
	/* Page renderers                                                        */
	/* --------------------------------------------------------------------- */

	protected function view( $file, $data = array() ) {
		extract( $data, EXTR_SKIP ); // phpcs:ignore
		include CC_PLUGIN_DIR . 'admin/views/' . $file . '.php';
	}

	public function page_dashboard() {
		$this->view( 'dashboard', array( 'cards' => CC_Stats::cards() ) );
	}

	public function page_orders() {
		// Single order detail view.
		if ( isset( $_GET['cc_view'] ) ) {
			$this->view( 'order-detail', array( 'order' => new CC_Order( (int) $_GET['cc_view'] ) ) );
			return;
		}

		$filters = array(
			'search'      => sanitize_text_field( $_GET['s'] ?? '' ),
			'ship_status' => sanitize_text_field( $_GET['ship_status'] ?? '' ),
			'payment'     => sanitize_text_field( $_GET['payment'] ?? '' ),
			'store'       => (int) ( $_GET['store'] ?? 0 ),
			'date_from'   => sanitize_text_field( $_GET['date_from'] ?? '' ),
			'date_to'     => sanitize_text_field( $_GET['date_to'] ?? '' ),
			'paged'       => (int) ( $_GET['paged'] ?? 1 ),
			'per_page'    => 20,
		);
		$this->view(
			'orders',
			array(
				'result'  => CC_Stats::query_orders( $filters ),
				'filters' => $filters,
				'stores'  => CC_Website::active(),
			)
		);
	}

	public function page_websites() {
		$this->view( 'websites', array( 'stores' => CC_Website::all() ) );
	}

	public function page_settings() {
		$this->view( 'settings', array( 'settings' => CC_Settings::all() ) );
	}

	public function page_logs() {
		$this->view( 'logs', array( 'logs' => CC_Logger::recent( 150 ) ) );
	}
}

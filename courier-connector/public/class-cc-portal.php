<?php
/**
 * Front-end Client Portal.
 *
 * Shortcode [naya_setu_client_portal] renders a branded, login-gated dashboard
 * where each client (role ns_client) manages only their own stores' shipments.
 * Admins see all stores. Reuses the dashboard CSS/JS for consistent actions.
 *
 * @package CourierConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CC_Portal {

	public function register() {
		add_shortcode( 'naya_setu_client_portal', array( $this, 'render' ) );
	}

	/**
	 * Enqueue the dashboard assets on the front-end (footer-safe).
	 */
	protected function assets() {
		wp_enqueue_style( 'cc-admin', CC_PLUGIN_URL . 'admin/assets/admin.css', array(), CC_VERSION );
		wp_enqueue_style( 'cc-portal', CC_PLUGIN_URL . 'public/portal.css', array( 'cc-admin' ), CC_VERSION );
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

	/**
	 * Store ids the current viewer may see (null = all, for admins).
	 *
	 * @return array|null
	 */
	protected function scope() {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return null;
		}
		return CC_Clients::user_store_ids( get_current_user_id() );
	}

	/**
	 * Render the portal.
	 *
	 * @return string
	 */
	public function render() {
		$this->assets();

		// Not logged in → login form.
		if ( ! is_user_logged_in() ) {
			return $this->login_view();
		}

		// Logged in but neither admin nor client → no access.
		if ( ! current_user_can( 'manage_woocommerce' ) && ! CC_Clients::is_client() ) {
			return $this->no_access_view();
		}

		$scope = $this->scope();

		ob_start();

		$order_id = isset( $_GET['ns_order'] ) ? (int) $_GET['ns_order'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $order_id && CC_Clients::can_manage_order( $order_id ) ) {
			$this->detail_view( new CC_Order( $order_id ) );
		} else {
			$this->list_view( $scope );
		}

		return ob_get_clean();
	}

	/* --------------------------------------------------------------------- */
	/* Views                                                                 */
	/* --------------------------------------------------------------------- */

	protected function login_view() {
		ob_start();
		include CC_PLUGIN_DIR . 'public/portal-login.php';
		return ob_get_clean();
	}

	protected function no_access_view() {
		return '<div class="ns-portal cc-wrap"><div class="cc-alert cc-alert-warn">Your account is not linked to any store. Please contact Naya Setu support.</div></div>';
	}

	protected function list_view( $scope ) {
		$filters = array(
			'search'      => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'ship_status' => isset( $_GET['ship_status'] ) ? sanitize_text_field( wp_unslash( $_GET['ship_status'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'paged'       => isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'per_page'    => 20,
		);
		$cards  = CC_Stats::cards( $scope );
		$result = CC_Stats::query_orders( $filters, $scope );
		include CC_PLUGIN_DIR . 'public/portal.php';
	}

	protected function detail_view( $order ) {
		include CC_PLUGIN_DIR . 'public/portal-detail.php';
	}
}

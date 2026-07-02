<?php

if (!defined('ABSPATH')) {
	exit;
}

class CC_Portal
{

	public function register()
	{
		add_shortcode('naya_setu_client_portal', array($this, 'render'));
	}

	protected function assets()
	{
		wp_enqueue_style('cc-admin', CC_PLUGIN_URL . 'admin/assets/admin.css', array(), CC_VERSION);
		wp_enqueue_style('cc-portal', CC_PLUGIN_URL . 'public/portal.css', array('cc-admin'), CC_VERSION);
		wp_enqueue_script('cc-admin', CC_PLUGIN_URL . 'admin/assets/admin.js', array('jquery'), CC_VERSION, true);
		wp_localize_script(
			'cc-admin',
			'CC',
			array(
				'ajax' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('cc_admin'),
			)
		);
	}

	protected function scope()
	{
		if (current_user_can('manage_woocommerce')) {
			return null;
		}
		return CC_Clients::user_store_ids(get_current_user_id());
	}

	public function render()
	{
		$this->assets();

		if (!is_user_logged_in()) {
			return $this->login_view();
		}

		if (!current_user_can('manage_woocommerce') && !CC_Clients::is_client()) {
			return $this->no_access_view();
		}

		$scope = $this->scope();

		ob_start();

		$order_id = isset($_GET['ns_order']) ? (int) $_GET['ns_order'] : 0;
		if ($order_id && CC_Clients::can_manage_order($order_id)) {
			$this->detail_view(new CC_Order($order_id));
		} else {
			$this->list_view($scope);
		}

		return ob_get_clean();
	}

	protected function login_view()
	{
		ob_start();
		include CC_PLUGIN_DIR . 'public/portal-login.php';
		return ob_get_clean();
	}

	protected function no_access_view()
	{
		return '<div class="ns-portal cc-wrap"><div class="cc-alert cc-alert-warn">Your account is not linked to any store. Please contact Naya Setu support.</div></div>';
	}

	protected function list_view($scope)
	{
		$filters = array(
			'search' => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
			'ship_status' => isset($_GET['ship_status']) ? sanitize_text_field(wp_unslash($_GET['ship_status'])) : '',
			'paged' => isset($_GET['paged']) ? (int) $_GET['paged'] : 1,
			'per_page' => 20,
		);
		$cards = CC_Stats::cards($scope);
		$result = CC_Stats::query_orders($filters, $scope);
		include CC_PLUGIN_DIR . 'public/portal.php';
	}

	protected function detail_view($order)
	{
		include CC_PLUGIN_DIR . 'public/portal-detail.php';
	}
}

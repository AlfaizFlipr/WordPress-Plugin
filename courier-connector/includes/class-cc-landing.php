<?php

if (!defined('ABSPATH')) {
	exit;
}

class CC_Landing
{

	public function register()
	{
		add_shortcode('naya_setu_landing', array($this, 'render'));
	}

	public function render($atts)
	{
		$atts = shortcode_atts(
			array(
				'connect_url' => admin_url('admin.php?page=ccc-settings'),
				'login_url' => admin_url(),
			),
			$atts,
			'naya_setu_landing'
		);

		ob_start();
		include CC_PLUGIN_DIR . 'public/landing-shortcode.php';
		return ob_get_clean();
	}
}

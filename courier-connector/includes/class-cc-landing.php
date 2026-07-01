<?php
/**
 * Public marketing landing page.
 *
 * Registers the [naya_setu_landing] shortcode so the Naya Setu Courier landing
 * page can be dropped into any WordPress page. The markup is self-contained
 * (scoped under .ns-land) so it renders correctly inside any theme.
 *
 * @package CourierConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CC_Landing {

	public function register() {
		add_shortcode( 'naya_setu_landing', array( $this, 'render' ) );
	}

	/**
	 * Render the landing page markup.
	 *
	 * @param array $atts Shortcode attributes (connect_url, login_url).
	 * @return string
	 */
	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'connect_url' => admin_url( 'admin.php?page=ccc-settings' ),
				'login_url'   => admin_url(),
			),
			$atts,
			'naya_setu_landing'
		);

		ob_start();
		include CC_PLUGIN_DIR . 'public/landing-shortcode.php';
		return ob_get_clean();
	}
}

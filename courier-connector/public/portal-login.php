<?php
/**
 * Client Portal — login screen.
 *
 * @package CourierConnector
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$redirect = get_permalink();
?>
<div class="ns-portal ns-login-screen">
	<div class="ns-login-card">
		<div class="ns-login-head">
			<span class="ns-logo ns-logo-lg">NS</span>
			<h2>Naya Setu Courier</h2>
			<p>Client Portal — sign in to manage your shipments</p>
		</div>
		<?php
		wp_login_form(
			array(
				'redirect'       => $redirect,
				'label_username' => 'Email or Username',
				'label_password' => 'Password',
				'label_log_in'   => 'Sign In',
			)
		);
		?>
		<p class="ns-login-foot">
			<a href="<?php echo esc_url( wp_lostpassword_url( $redirect ) ); ?>">Forgot password?</a>
		</p>
	</div>
</div>

<?php
/**
 * Landing page shortcode template (scoped under .ns-land).
 *
 * @var array $atts {connect_url, login_url}
 * @package CourierConnector
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$connect = esc_url( $atts['connect_url'] );
$login   = esc_url( $atts['login_url'] );
?>
<style>
.ns-land{--navy:#0A2540;--blue:#1A56DB;--blue-d:#1442b0;--saffron:#F97316;--green:#16A34A;--gray:#64748B;--line:#E5EAF1;--bg:#F6F9FC;
	font-family:-apple-system,"Segoe UI",Inter,Roboto,Arial,sans-serif;color:var(--navy);line-height:1.6;}
.ns-land *{box-sizing:border-box;}
.ns-wrap{max-width:1180px;margin:0 auto;padding:0 24px;}
.ns-btn{display:inline-flex;align-items:center;gap:8px;font-weight:700;font-size:15px;padding:13px 24px;border-radius:10px;border:2px solid transparent;cursor:pointer;transition:.18s;text-decoration:none;}
.ns-btn-primary{background:var(--blue);color:#fff;}
.ns-btn-primary:hover{background:var(--blue-d);color:#fff;}
.ns-btn-accent{background:var(--saffron);color:#fff;}
.ns-btn-accent:hover{background:#e1640b;color:#fff;}
.ns-btn-ghost{background:transparent;color:#fff;border-color:rgba(255,255,255,.4);}
.ns-hero{background:linear-gradient(160deg,#0A2540 0%,#13346e 60%,#1A56DB 100%);color:#fff;border-radius:20px;overflow:hidden;position:relative;margin-bottom:60px;}
.ns-hero::after{content:"";position:absolute;right:-100px;top:-100px;width:420px;height:420px;border-radius:50%;background:radial-gradient(circle,rgba(249,115,22,.35),transparent 70%);}
.ns-hero-in{padding:64px 40px;position:relative;z-index:2;max-width:680px;}
.ns-pill{display:inline-block;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25);padding:7px 14px;border-radius:999px;font-size:13px;font-weight:600;margin-bottom:20px;}
.ns-hero h1{font-size:42px;line-height:1.14;font-weight:800;margin:0 0 16px;}
.ns-hero h1 span{color:#FFB87A;}
.ns-hero p{font-size:18px;color:#cfe0ff;margin:0 0 26px;}
.ns-cta{display:flex;gap:14px;flex-wrap:wrap;}
.ns-eyebrow{color:var(--saffron);font-weight:800;letter-spacing:1px;text-transform:uppercase;font-size:13px;text-align:center;}
.ns-title{font-size:32px;font-weight:800;text-align:center;margin:8px 0 40px;}
.ns-features{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:60px;}
.ns-feature{background:#fff;border:1px solid var(--line);border-radius:14px;padding:26px;}
.ns-ic{width:48px;height:48px;border-radius:12px;background:#eef4ff;color:var(--blue);display:flex;align-items:center;justify-content:center;font-size:22px;margin-bottom:14px;}
.ns-feature h3{font-size:17px;margin:0 0 6px;}
.ns-feature p{color:var(--gray);font-size:14.5px;margin:0;}
.ns-steps{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin-bottom:60px;counter-reset:s;}
.ns-step{text-align:center;}
.ns-num{counter-increment:s;width:54px;height:54px;border-radius:50%;background:linear-gradient(135deg,var(--blue),var(--saffron));color:#fff;font-weight:800;font-size:21px;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;}
.ns-num::before{content:counter(s);}
.ns-step h4{margin:0 0 5px;}
.ns-step p{color:var(--gray);font-size:14px;margin:0;}
.ns-band{background:linear-gradient(135deg,var(--blue),var(--navy));color:#fff;border-radius:20px;padding:50px;text-align:center;}
.ns-band h2{font-size:30px;font-weight:800;margin:0 0 10px;}
.ns-band p{color:#cfe0ff;margin:0 0 24px;font-size:17px;}
@media(max-width:860px){.ns-features,.ns-steps{grid-template-columns:1fr;}.ns-hero h1{font-size:30px;}}
</style>

<div class="ns-land">
	<div class="ns-wrap">

		<div class="ns-hero">
			<div class="ns-hero-in">
				<span class="ns-pill">🚚 WooCommerce → Delhivery, fully automated</span>
				<h1>Connect Your WooCommerce Store to Delhivery in <span>Under 2 Minutes</span></h1>
				<p>Naya Setu Courier auto-syncs your orders, creates Delhivery shipments in one click, generates AWB numbers, and tracks every delivery — from one dashboard.</p>
				<div class="ns-cta">
					<a class="ns-btn ns-btn-accent" href="<?php echo $connect; ?>">Connect Store</a>
					<a class="ns-btn ns-btn-ghost" href="<?php echo $login; ?>">Login</a>
				</div>
			</div>
		</div>

		<div class="ns-eyebrow">Everything You Need</div>
		<h2 class="ns-title">Built for fast, reliable shipping</h2>
		<div class="ns-features">
			<div class="ns-feature"><div class="ns-ic">🔄</div><h3>Automatic Order Sync</h3><p>New orders flow into your dashboard the moment a customer checks out.</p></div>
			<div class="ns-feature"><div class="ns-ic">📦</div><h3>One-Click Shipments</h3><p>Push any order to Delhivery and instantly generate an AWB. Bulk-book too.</p></div>
			<div class="ns-feature"><div class="ns-ic">📍</div><h3>Real-Time Tracking</h3><p>Status syncs back to your store and dashboard automatically.</p></div>
			<div class="ns-feature"><div class="ns-ic">🖨️</div><h3>Bulk Label Printing</h3><p>Download Delhivery labels and packing slips in one click.</p></div>
			<div class="ns-feature"><div class="ns-ic">🏬</div><h3>Multi-Store</h3><p>Connect unlimited WooCommerce stores, manage from one place.</p></div>
			<div class="ns-feature"><div class="ns-ic">📊</div><h3>Live Stats</h3><p>Total, Pending, Booked, In-Transit, Delivered — at a glance.</p></div>
		</div>

		<div class="ns-eyebrow">Simple Setup</div>
		<h2 class="ns-title">Live in 4 easy steps</h2>
		<div class="ns-steps">
			<div class="ns-step"><div class="ns-num"></div><h4>Install Plugin</h4><p>Upload the connector to your store.</p></div>
			<div class="ns-step"><div class="ns-num"></div><h4>Connect Store</h4><p>Enter your dashboard URL and connect.</p></div>
			<div class="ns-step"><div class="ns-num"></div><h4>Sync Orders</h4><p>Orders import automatically.</p></div>
			<div class="ns-step"><div class="ns-num"></div><h4>Ship &amp; Track</h4><p>Push to Delhivery, print, track.</p></div>
		</div>

		<div class="ns-band">
			<h2>Ready to ship smarter?</h2>
			<p>Connect your WooCommerce store to Delhivery with Naya Setu Courier today.</p>
			<a class="ns-btn ns-btn-accent" href="<?php echo $connect; ?>">Connect Your Store Now</a>
		</div>

	</div>
</div>

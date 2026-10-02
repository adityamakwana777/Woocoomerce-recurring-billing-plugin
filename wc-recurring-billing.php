<?php
/**
 * Plugin Name:       Recurring Billing for WooCommerce
 * Description:       Stripe-powered subscriptions for WooCommerce: recurring billing, renewal orders, cancellations, and webhook-driven payment sync.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Aditya Makwana
 * License:           MIT
 * Text Domain:       wrb
 *
 * @package RecurringBilling
 */

defined( 'ABSPATH' ) || exit;

define( 'WRB_VERSION', '1.0.0' );
define( 'WRB_FILE', __FILE__ );
define( 'WRB_DIR', plugin_dir_path( __FILE__ ) );
define( 'WRB_URL', plugin_dir_url( __FILE__ ) );

// Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WRB_FILE, true );
		}
	}
);

// The My Account "Subscriptions" page needs its rewrite rule registered once.
register_activation_hook(
	__FILE__,
	static function () {
		add_rewrite_endpoint( 'my-subscriptions', EP_ROOT | EP_PAGES );
		flush_rewrite_rules();
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		require_once WRB_DIR . 'includes/class-wrb-stripe-api.php';
		require_once WRB_DIR . 'includes/class-wrb-product.php';
		require_once WRB_DIR . 'includes/class-wrb-subscription.php';
		require_once WRB_DIR . 'includes/class-wrb-gateway.php';
		require_once WRB_DIR . 'includes/class-wrb-webhook.php';
		require_once WRB_DIR . 'includes/class-wrb-account.php';

		add_filter(
			'woocommerce_payment_gateways',
			static function ( $gateways ) {
				$gateways[] = 'WRB_Gateway';
				return $gateways;
			}
		);

		WRB_Product::init();
		WRB_Subscription::init();
		WRB_Webhook::init();
		WRB_Account::init();
	}
);

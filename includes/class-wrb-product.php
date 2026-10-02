<?php
/**
 * Subscription product settings and cart rules.
 *
 * A simple product becomes a subscription product with a checkbox and a billing
 * interval. To keep billing predictable, a subscription is bought on its own:
 * one subscription product, quantity 1, no coupons, logged-in customer.
 *
 * @package RecurringBilling
 */

defined( 'ABSPATH' ) || exit;

class WRB_Product {

	public static function init() {
		// Admin: product data fields.
		add_action( 'woocommerce_product_options_pricing', array( __CLASS__, 'render_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_fields' ) );

		// Storefront labels.
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 10, 2 );
		add_filter( 'woocommerce_product_single_add_to_cart_text', array( __CLASS__, 'button_text' ), 10, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( __CLASS__, 'button_text' ), 10, 2 );

		// Cart rules.
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 10, 2 );
		add_filter( 'woocommerce_is_sold_individually', array( __CLASS__, 'sold_individually' ), 10, 2 );
		add_filter( 'woocommerce_coupons_enabled', array( __CLASS__, 'coupons_enabled' ) );
		add_filter( 'woocommerce_checkout_registration_required', array( __CLASS__, 'require_account' ) );
		add_filter( 'woocommerce_checkout_registration_enabled', array( __CLASS__, 'enable_registration' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'limit_gateways' ) );
	}

	/* ---------------------------------------------------------------------
	 * Helpers (used across the plugin)
	 * ------------------------------------------------------------------ */

	public static function intervals() {
		return array(
			'week'  => __( 'Weekly', 'wrb' ),
			'month' => __( 'Monthly', 'wrb' ),
			'year'  => __( 'Yearly', 'wrb' ),
		);
	}

	public static function is_subscription( $product_id ) {
		$product = wc_get_product( $product_id );
		return $product && $product->is_type( 'simple' ) && 'yes' === $product->get_meta( '_wrb_is_subscription' );
	}

	public static function get_interval( $product_id ) {
		$product  = wc_get_product( $product_id );
		$interval = $product ? $product->get_meta( '_wrb_interval' ) : '';
		return array_key_exists( $interval, self::intervals() ) ? $interval : 'month';
	}

	public static function cart_has_subscription() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( self::is_subscription( $cart_item['product_id'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The subscription product of an order, or null if the order is not a valid
	 * single-subscription order.
	 *
	 * @return WC_Product|null
	 */
	public static function order_subscription_product( WC_Order $order ) {
		$items = $order->get_items();
		if ( 1 !== count( $items ) ) {
			return null;
		}
		$item = reset( $items );
		if ( 1 !== (int) $item->get_quantity() || ! self::is_subscription( $item->get_product_id() ) ) {
			return null;
		}
		return wc_get_product( $item->get_product_id() );
	}

	/* ---------------------------------------------------------------------
	 * Admin fields
	 * ------------------------------------------------------------------ */

	public static function render_fields() {
		echo '<div class="options_group show_if_simple">';
		woocommerce_wp_checkbox(
			array(
				'id'          => '_wrb_is_subscription',
				'label'       => __( 'Subscription', 'wrb' ),
				'description' => __( 'Bill this product on a recurring schedule via Stripe. Use it for virtual products or memberships.', 'wrb' ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => '_wrb_interval',
				'label'   => __( 'Billing interval', 'wrb' ),
				'options' => self::intervals(),
			)
		);
		echo '</div>';
	}

	public static function save_fields( $post_id ) {
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}

		// WooCommerce has already verified the product-edit nonce and capability.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$is_sub   = isset( $_POST['_wrb_is_subscription'] ) ? 'yes' : 'no';
		$interval = isset( $_POST['_wrb_interval'] ) ? sanitize_key( wp_unslash( $_POST['_wrb_interval'] ) ) : 'month';
		// phpcs:enable

		if ( ! array_key_exists( $interval, self::intervals() ) ) {
			$interval = 'month';
		}

		$product->update_meta_data( '_wrb_is_subscription', $is_sub );
		$product->update_meta_data( '_wrb_interval', $interval );
		$product->save_meta_data();
	}

	/* ---------------------------------------------------------------------
	 * Storefront
	 * ------------------------------------------------------------------ */

	public static function price_html( $html, $product ) {
		if ( self::is_subscription( $product->get_id() ) ) {
			$html .= ' <span class="wrb-interval">/ ' . esc_html( self::get_interval( $product->get_id() ) ) . '</span>';
		}
		return $html;
	}

	public static function button_text( $text, $product ) {
		return self::is_subscription( $product->get_id() ) ? __( 'Subscribe', 'wrb' ) : $text;
	}

	/* ---------------------------------------------------------------------
	 * Cart rules
	 * ------------------------------------------------------------------ */

	public static function validate_add_to_cart( $passed, $product_id ) {
		if ( ! $passed || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $passed;
		}

		if ( self::is_subscription( $product_id ) && ! WC()->cart->is_empty() ) {
			wc_add_notice( __( 'A subscription is purchased on its own. Please check out or empty your cart first.', 'wrb' ), 'error' );
			return false;
		}
		if ( ! self::is_subscription( $product_id ) && self::cart_has_subscription() ) {
			wc_add_notice( __( 'Your cart contains a subscription. Please check out first, then order other products separately.', 'wrb' ), 'error' );
			return false;
		}
		return $passed;
	}

	public static function sold_individually( $value, $product ) {
		return self::is_subscription( $product->get_id() ) ? true : $value;
	}

	public static function coupons_enabled( $enabled ) {
		return self::cart_has_subscription() ? false : $enabled; // recurring price = first payment, so no first-payment-only discounts.
	}

	public static function require_account( $required ) {
		return self::cart_has_subscription() ? true : $required; // customers manage their subscription from My Account.
	}

	public static function enable_registration( $enabled ) {
		return self::cart_has_subscription() ? true : $enabled;
	}

	/**
	 * A subscription cart can only be paid with the subscription gateway.
	 */
	public static function limit_gateways( $gateways ) {
		if ( ! self::cart_has_subscription() ) {
			return $gateways;
		}
		return isset( $gateways['wrb_stripe'] ) ? array( 'wrb_stripe' => $gateways['wrb_stripe'] ) : array();
	}
}

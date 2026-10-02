<?php
/**
 * Stripe gateway that starts a recurring subscription at checkout.
 *
 * Flow: Stripe.js tokenises the card -> we create/reuse a Stripe Customer,
 * attach the card as its default payment method, create a Subscription whose
 * first invoice is left open, then confirm that invoice's PaymentIntent
 * (with a 3-D Secure redirect if the bank asks for it). Later renewals are
 * charged by Stripe and reach the store through webhooks.
 *
 * @package RecurringBilling
 */

defined( 'ABSPATH' ) || exit;

class WRB_Gateway extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'wrb_stripe';
		$this->method_title       = __( 'Stripe Subscriptions', 'wrb' );
		$this->method_description = __( 'Recurring card payments through Stripe Billing. Shown only when the cart contains a subscription product.', 'wrb' );
		$this->has_fields         = true;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'woocommerce_api_wrb_return', array( $this, 'handle_return' ) );
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'                => array(
				'title'   => __( 'Enable/Disable', 'wrb' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Stripe Subscriptions', 'wrb' ),
				'default' => 'no',
			),
			'title'                  => array(
				'title'   => __( 'Title', 'wrb' ),
				'type'    => 'text',
				'default' => __( 'Credit / Debit Card (recurring)', 'wrb' ),
			),
			'description'            => array(
				'title'   => __( 'Description', 'wrb' ),
				'type'    => 'textarea',
				'default' => __( 'Your card will be charged automatically each billing period. You can cancel any time from My Account.', 'wrb' ),
			),
			'testmode'               => array(
				'title'   => __( 'Test mode', 'wrb' ),
				'type'    => 'checkbox',
				'label'   => __( 'Use Stripe test keys', 'wrb' ),
				'default' => 'yes',
			),
			'test_publishable_key'   => array(
				'title' => __( 'Test publishable key', 'wrb' ),
				'type'  => 'text',
			),
			'test_secret_key'        => array(
				'title' => __( 'Test secret key', 'wrb' ),
				'type'  => 'password',
			),
			'test_webhook_secret'    => array(
				'title'       => __( 'Test webhook signing secret', 'wrb' ),
				'type'        => 'password',
				'description' => sprintf(
					/* translators: %s: webhook URL */
					__( 'Webhook endpoint URL: %s', 'wrb' ),
					esc_url( rest_url( 'wrb/v1/webhook' ) )
				),
			),
			'live_publishable_key'   => array(
				'title' => __( 'Live publishable key', 'wrb' ),
				'type'  => 'text',
			),
			'live_secret_key'        => array(
				'title' => __( 'Live secret key', 'wrb' ),
				'type'  => 'password',
			),
			'live_webhook_secret'    => array(
				'title' => __( 'Live webhook signing secret', 'wrb' ),
				'type'  => 'password',
			),
		);
	}

	public static function setting( $key, $default = '' ) {
		$settings = get_option( 'woocommerce_wrb_stripe_settings', array() );
		return ( isset( $settings[ $key ] ) && '' !== $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	public static function is_test() {
		return 'yes' === self::setting( 'testmode', 'yes' );
	}

	public static function secret_key() {
		return self::is_test() ? self::setting( 'test_secret_key' ) : self::setting( 'live_secret_key' );
	}

	public static function publishable_key() {
		return self::is_test() ? self::setting( 'test_publishable_key' ) : self::setting( 'live_publishable_key' );
	}

	public static function webhook_secret() {
		return self::is_test() ? self::setting( 'test_webhook_secret' ) : self::setting( 'live_webhook_secret' );
	}

	public function is_available() {
		if ( ! parent::is_available() || ! self::secret_key() || ! self::publishable_key() ) {
			return false;
		}
		// On the storefront, only offer this gateway for subscription carts.
		if ( function_exists( 'WC' ) && WC()->cart ) {
			return WRB_Product::cart_has_subscription();
		}
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Checkout UI
	 * ------------------------------------------------------------------ */

	public function enqueue_scripts() {
		if ( ! is_checkout() || 'yes' !== $this->enabled || ! self::publishable_key() ) {
			return;
		}

		wp_enqueue_script( 'wrb-stripe-js', 'https://js.stripe.com/v3/', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_script( 'wrb-checkout', WRB_URL . 'assets/js/checkout.js', array( 'jquery', 'wrb-stripe-js' ), WRB_VERSION, true );
		wp_localize_script( 'wrb-checkout', 'wrbData', array( 'publishableKey' => self::publishable_key() ) );
	}

	public function payment_fields() {
		if ( $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}
		if ( self::is_test() ) {
			echo '<p><strong>' . esc_html__( 'Test mode: use card 4242 4242 4242 4242, any future date, any CVC.', 'wrb' ) . '</strong></p>';
		}
		echo '<div id="wrb-card-element" style="padding:10px;border:1px solid #ccc;border-radius:4px;background:#fff;"></div>';
		echo '<div id="wrb-card-errors" role="alert" style="color:#b32d2e;margin-top:6px;"></div>';
		echo '<input type="hidden" id="wrb_payment_method" name="wrb_payment_method" value="" />';
	}

	/* ---------------------------------------------------------------------
	 * Payment processing
	 * ------------------------------------------------------------------ */

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $this->fail( __( 'Order not found.', 'wrb' ) );
		}

		$product = WRB_Product::order_subscription_product( $order );
		if ( ! $product ) {
			return $this->fail( __( 'This gateway can only be used to buy a single subscription product.', 'wrb' ) );
		}
		if ( ! $order->get_customer_id() ) {
			return $this->fail( __( 'Please create an account or log in to buy a subscription.', 'wrb' ) );
		}

		// WooCommerce has already verified its checkout nonce before calling this method.
		$pm_id = isset( $_POST['wrb_payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['wrb_payment_method'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! preg_match( '/^pm_[A-Za-z0-9]+$/', $pm_id ) ) {
			return $this->fail( __( 'Card details are missing. Please re-enter your card and try again.', 'wrb' ) );
		}

		$api      = new WRB_Stripe_API( self::secret_key() );
		$currency = strtolower( $order->get_currency() );
		$interval = WRB_Product::get_interval( $product->get_id() );

		// 1. Customer: reuse the Stripe customer of a returning subscriber.
		$customer_id = $this->get_or_create_customer( $api, $order );
		if ( is_wp_error( $customer_id ) ) {
			return $this->fail_from_error( $customer_id, $order );
		}

		// 2. Attach the card and make it the default for future invoices.
		$attached = $api->post( 'payment_methods/' . $pm_id . '/attach', array( 'customer' => $customer_id ) );
		if ( is_wp_error( $attached ) ) {
			return $this->fail_from_error( $attached, $order );
		}
		$updated = $api->post( 'customers/' . $customer_id, array( 'invoice_settings' => array( 'default_payment_method' => $pm_id ) ) );
		if ( is_wp_error( $updated ) ) {
			return $this->fail_from_error( $updated, $order );
		}

		// 3. Subscription. The recurring price equals the first order total (tax included).
		$stripe_sub = $api->post(
			'subscriptions',
			array(
				'customer'               => $customer_id,
				'items'                  => array(
					array(
						'price_data' => array(
							'currency'     => $currency,
							'unit_amount'  => WRB_Stripe_API::to_minor( $order->get_total(), $order->get_currency() ),
							'recurring'    => array( 'interval' => $interval ),
							'product_data' => array( 'name' => $product->get_name() ),
						),
					),
				),
				'default_payment_method' => $pm_id,
				'payment_behavior'       => 'default_incomplete',
				'payment_settings'       => array( 'save_default_payment_method' => 'on_subscription' ),
				'expand'                 => array( 'latest_invoice.payment_intent' ),
				'metadata'               => array(
					'order_id' => $order->get_id(),
					'user_id'  => $order->get_customer_id(),
				),
			),
			'wrb-sub-' . $order->get_id() . '-' . $pm_id
		);
		if ( is_wp_error( $stripe_sub ) ) {
			return $this->fail_from_error( $stripe_sub, $order );
		}

		$intent = isset( $stripe_sub['latest_invoice']['payment_intent'] ) ? $stripe_sub['latest_invoice']['payment_intent'] : null;
		if ( ! is_array( $intent ) || empty( $intent['id'] ) ) {
			$this->cancel_stripe_subscription( $api, $stripe_sub['id'] );
			return $this->fail( __( 'We could not start your subscription. Please try again.', 'wrb' ) );
		}

		// 4. Save our records BEFORE confirming, so a fast webhook can already find them.
		$order->update_meta_data( '_wrb_subscription_id', $stripe_sub['id'] );
		$order->update_meta_data( '_wrb_intent_id', $intent['id'] );
		$order->save();

		$post_id = WRB_Subscription::create(
			array(
				'stripe_id'    => $stripe_sub['id'],
				'user_id'      => $order->get_customer_id(),
				'parent_order' => $order->get_id(),
				'product_id'   => $product->get_id(),
				'status'       => $stripe_sub['status'],
				'interval'     => $interval,
				'amount'       => $order->get_total(),
				'currency'     => $order->get_currency(),
				'next_payment' => isset( $stripe_sub['current_period_end'] ) ? (int) $stripe_sub['current_period_end'] : 0,
			)
		);
		if ( is_wp_error( $post_id ) ) {
			$this->cancel_stripe_subscription( $api, $stripe_sub['id'] );
			return $this->fail( __( 'We could not save your subscription. Please try again.', 'wrb' ) );
		}

		// 5. Charge the first invoice.
		$confirmed = $api->post(
			'payment_intents/' . $intent['id'] . '/confirm',
			array(
				'payment_method' => $pm_id,
				'return_url'     => add_query_arg(
					array(
						'wc-api'   => 'wrb_return',
						'order_id' => $order->get_id(),
						'key'      => $order->get_order_key(),
					),
					home_url( '/' )
				),
			)
		);
		if ( is_wp_error( $confirmed ) ) {
			$this->cancel_stripe_subscription( $api, $stripe_sub['id'] );
			return $this->fail_from_error( $confirmed, $order );
		}

		if ( 'succeeded' === $confirmed['status'] ) {
			$this->finalize( $order, $confirmed['id'] );
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		// 3-D Secure: the customer authenticates with the bank, then returns to handle_return().
		if ( 'requires_action' === $confirmed['status'] && ! empty( $confirmed['next_action']['redirect_to_url']['url'] ) ) {
			$order->update_status( 'pending', __( 'Awaiting 3-D Secure authentication.', 'wrb' ) );
			return array(
				'result'   => 'success',
				'redirect' => $confirmed['next_action']['redirect_to_url']['url'],
			);
		}

		$this->cancel_stripe_subscription( $api, $stripe_sub['id'] );
		$order->add_order_note( sprintf( 'First payment %s ended with status "%s".', $confirmed['id'], $confirmed['status'] ) );
		return $this->fail( __( 'Your payment could not be completed. Please try another card.', 'wrb' ) );
	}

	/**
	 * Customer returns from 3-D Secure.
	 */
	public function handle_return() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || ! $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		if ( $order->is_paid() ) {
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		$api    = new WRB_Stripe_API( self::secret_key() );
		$intent = $api->get( 'payment_intents/' . rawurlencode( (string) $order->get_meta( '_wrb_intent_id' ) ) );

		if ( ! is_wp_error( $intent ) && 'succeeded' === $intent['status'] ) {
			$this->finalize( $order, $intent['id'] );
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		$this->cancel_stripe_subscription( $api, (string) $order->get_meta( '_wrb_subscription_id' ) );
		$order->update_status( 'failed', __( '3-D Secure authentication failed or was cancelled.', 'wrb' ) );
		wc_add_notice( __( 'Payment authentication failed. Please try again.', 'wrb' ), 'error' );
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * @return string|WP_Error Stripe customer id.
	 */
	private function get_or_create_customer( WRB_Stripe_API $api, WC_Order $order ) {
		$meta_key = '_wrb_stripe_customer_' . ( self::is_test() ? 'test' : 'live' ); // test and live customers are separate in Stripe.
		$existing = get_user_meta( $order->get_customer_id(), $meta_key, true );
		if ( $existing ) {
			return $existing;
		}

		$customer = $api->post(
			'customers',
			array(
				'email'    => $order->get_billing_email(),
				'name'     => trim( $order->get_formatted_billing_full_name() ),
				'metadata' => array( 'user_id' => $order->get_customer_id() ),
			)
		);
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		update_user_meta( $order->get_customer_id(), $meta_key, $customer['id'] );
		return $customer['id'];
	}

	/**
	 * Mark the first order paid and the subscription active (safe to call twice).
	 */
	private function finalize( WC_Order $order, $intent_id ) {
		if ( ! $order->is_paid() ) {
			$order->payment_complete( $intent_id );
			$order->add_order_note( sprintf( 'Stripe payment %s succeeded. Subscription started.', $intent_id ) );
		}

		$post_id = WRB_Subscription::find_by_stripe_id( (string) $order->get_meta( '_wrb_subscription_id' ) );
		if ( $post_id ) {
			WRB_Subscription::set( $post_id, 'status', 'active' );
		}

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}
	}

	/**
	 * Stop an unpaid Stripe subscription so it does not linger as "incomplete".
	 */
	private function cancel_stripe_subscription( WRB_Stripe_API $api, $stripe_sub_id ) {
		if ( ! $stripe_sub_id ) {
			return;
		}
		$api->delete( 'subscriptions/' . rawurlencode( $stripe_sub_id ) );

		$post_id = WRB_Subscription::find_by_stripe_id( $stripe_sub_id );
		if ( $post_id ) {
			WRB_Subscription::set( $post_id, 'status', 'incomplete_expired' );
		}
	}

	private function fail( $message ) {
		wc_add_notice( $message, 'error' );
		return array( 'result' => 'failure' );
	}

	private function fail_from_error( WP_Error $error, WC_Order $order ) {
		$data = $error->get_error_data();
		wc_get_logger()->error( $error->get_error_message(), array( 'source' => 'wrb', 'order_id' => $order->get_id() ) );
		$order->add_order_note( 'Stripe error: ' . $error->get_error_message() );

		// Card errors (declines etc.) are useful to show; anything else stays generic.
		if ( is_array( $data ) && isset( $data['type'] ) && 'card_error' === $data['type'] ) {
			return $this->fail( $error->get_error_message() );
		}
		return $this->fail( __( 'We could not process your payment right now. Please try again.', 'wrb' ) );
	}
}

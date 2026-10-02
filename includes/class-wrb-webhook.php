<?php
/**
 * Stripe webhook receiver.
 *
 * Stripe charges renewals on its own schedule. This class turns those events
 * into WooCommerce data:
 *
 *  invoice.paid                    first invoice -> completes the first order (safety net)
 *                                  renewal invoice -> creates a paid renewal order
 *  invoice.payment_failed          creates/updates a failed renewal order, marks subscription past due
 *  customer.subscription.updated   syncs status, next payment date, cancel-at-period-end
 *  customer.subscription.deleted   marks the subscription cancelled
 *
 * Endpoint: /wp-json/wrb/v1/webhook
 *
 * @package RecurringBilling
 */

defined( 'ABSPATH' ) || exit;

class WRB_Webhook {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	public static function register_route() {
		register_rest_route(
			'wrb/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true', // authenticated by the Stripe signature instead.
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Request handling
	 * ------------------------------------------------------------------ */

	public static function handle( WP_REST_Request $request ) {
		$payload = $request->get_body();

		if ( ! self::verify_signature( $payload, (string) $request->get_header( 'stripe-signature' ) ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid signature' ), 400 );
		}

		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['id'] ) || empty( $event['type'] ) || empty( $event['data']['object'] ) ) {
			return new WP_REST_Response( array( 'error' => 'Malformed event' ), 400 );
		}

		// Stripe may deliver an event more than once; skip ones we already handled.
		$seen_key = 'wrb_evt_' . md5( $event['id'] );
		if ( get_transient( $seen_key ) ) {
			return new WP_REST_Response( array( 'received' => true, 'duplicate' => true ), 200 );
		}

		$object = $event['data']['object'];

		switch ( $event['type'] ) {
			case 'invoice.paid':
				self::on_invoice_paid( $object );
				break;
			case 'invoice.payment_failed':
				self::on_invoice_payment_failed( $object );
				break;
			case 'customer.subscription.updated':
			case 'customer.subscription.deleted':
				self::on_subscription_changed( $object, $event['type'] );
				break;
		}

		set_transient( $seen_key, 1, 3 * DAY_IN_SECONDS );
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Verify the "Stripe-Signature" header (HMAC-SHA256 of "timestamp.payload").
	 */
	private static function verify_signature( $payload, $header ) {
		$secret = WRB_Gateway::webhook_secret();
		if ( ! $secret || ! $header ) {
			return false;
		}

		$timestamp  = 0;
		$signatures = array();
		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] ) {
				$timestamp = (int) $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$signatures[] = $pair[1];
			}
		}

		// Reject old timestamps to stop replayed requests (5 minute tolerance).
		if ( ! $timestamp || ! $signatures || abs( time() - $timestamp ) > 300 ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Event handlers
	 * ------------------------------------------------------------------ */

	private static function on_invoice_paid( array $invoice ) {
		$sub_id  = self::invoice_subscription_id( $invoice );
		$post_id = WRB_Subscription::find_by_stripe_id( $sub_id );
		if ( ! $post_id ) {
			return; // not one of our subscriptions.
		}

		$transaction_id = ! empty( $invoice['payment_intent'] ) ? $invoice['payment_intent'] : $invoice['id'];
		$reason         = isset( $invoice['billing_reason'] ) ? $invoice['billing_reason'] : '';

		if ( 'subscription_create' === $reason ) {
			// First invoice: normally the browser flow already completed the order; this is the safety net.
			$parent = wc_get_order( (int) WRB_Subscription::get( $post_id, 'parent_order', 0 ) );
			if ( $parent && ! $parent->is_paid() ) {
				$parent->payment_complete( $transaction_id );
				$parent->add_order_note( sprintf( 'Stripe invoice %s paid (webhook). Subscription started.', $invoice['id'] ) );
			}
		} else {
			$order = self::find_renewal_order( $invoice['id'] );
			if ( ! $order ) {
				$order = self::create_renewal_order( $post_id, $invoice );
			}
			if ( $order && ! $order->is_paid() ) {
				// A failed attempt earlier may have created this order; Stripe's retry just succeeded.
				$order->payment_complete( $transaction_id );
				$order->add_order_note( sprintf( 'Stripe invoice %s paid.', $invoice['id'] ) );
			}
		}

		WRB_Subscription::set( $post_id, 'status', 'active' );
		$period_end = self::invoice_period_end( $invoice );
		if ( $period_end ) {
			WRB_Subscription::set( $post_id, 'next_payment', $period_end );
		}
	}

	private static function on_invoice_payment_failed( array $invoice ) {
		$sub_id  = self::invoice_subscription_id( $invoice );
		$post_id = WRB_Subscription::find_by_stripe_id( $sub_id );
		if ( ! $post_id ) {
			return;
		}

		// A failed first payment is handled in the checkout flow, not here.
		if ( isset( $invoice['billing_reason'] ) && 'subscription_create' === $invoice['billing_reason'] ) {
			return;
		}

		WRB_Subscription::set( $post_id, 'status', 'past_due' );

		$attempt = isset( $invoice['attempt_count'] ) ? (int) $invoice['attempt_count'] : 1;
		$order   = self::find_renewal_order( $invoice['id'] );

		if ( ! $order ) {
			$order = self::create_renewal_order( $post_id, $invoice );
			if ( $order ) {
				// Moving to "failed" also triggers WooCommerce's failed-order email to the admin.
				$order->update_status( 'failed', sprintf( 'Renewal payment failed (attempt %d).', $attempt ) );
			}
		} elseif ( ! $order->is_paid() ) {
			$order->add_order_note( sprintf( 'Renewal payment failed again (attempt %d).', $attempt ) );
		}
	}

	private static function on_subscription_changed( array $stripe_sub, $event_type ) {
		$post_id = WRB_Subscription::find_by_stripe_id( isset( $stripe_sub['id'] ) ? $stripe_sub['id'] : '' );
		if ( ! $post_id ) {
			return;
		}

		WRB_Subscription::update_from_stripe( $post_id, $stripe_sub );

		if ( 'customer.subscription.deleted' === $event_type ) {
			WRB_Subscription::set( $post_id, 'status', 'canceled' );
			$parent = wc_get_order( (int) WRB_Subscription::get( $post_id, 'parent_order', 0 ) );
			if ( $parent ) {
				$parent->add_order_note( 'Subscription cancelled in Stripe.' );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Renewal orders
	 * ------------------------------------------------------------------ */

	private static function find_renewal_order( $invoice_id ) {
		$ids = wc_get_orders(
			array(
				'limit'      => 1,
				'return'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_wrb_invoice_id',
						'value' => $invoice_id,
					),
				),
			)
		);
		return $ids ? wc_get_order( $ids[0] ) : null;
	}

	/**
	 * Build a renewal order as a copy of the first order (same items, taxes and total),
	 * linked to the subscription and to the Stripe invoice it was paid by.
	 *
	 * @return WC_Order|null
	 */
	private static function create_renewal_order( $post_id, array $invoice ) {
		$parent = wc_get_order( (int) WRB_Subscription::get( $post_id, 'parent_order', 0 ) );
		if ( ! $parent ) {
			return null;
		}

		$renewal = wc_create_order(
			array(
				'customer_id' => $parent->get_customer_id(),
				'status'      => 'pending',
				'created_via' => 'wrb_renewal',
			)
		);
		if ( is_wp_error( $renewal ) ) {
			return null;
		}

		$renewal->set_currency( $parent->get_currency() );
		$renewal->set_prices_include_tax( $parent->get_prices_include_tax() );
		$renewal->set_address( $parent->get_address( 'billing' ), 'billing' );
		$renewal->set_address( $parent->get_address( 'shipping' ), 'shipping' );

		foreach ( $parent->get_items() as $parent_item ) {
			$item = new WC_Order_Item_Product();
			$item->set_props(
				array(
					'product_id'   => $parent_item->get_product_id(),
					'name'         => $parent_item->get_name(),
					'quantity'     => $parent_item->get_quantity(),
					'subtotal'     => $parent_item->get_subtotal(),
					'total'        => $parent_item->get_total(),
					'subtotal_tax' => $parent_item->get_subtotal_tax(),
					'total_tax'    => $parent_item->get_total_tax(),
					'taxes'        => $parent_item->get_taxes(),
				)
			);
			$renewal->add_item( $item );
		}

		foreach ( $parent->get_items( 'tax' ) as $parent_tax ) {
			$tax = new WC_Order_Item_Tax();
			$tax->set_props(
				array(
					'rate_id'            => $parent_tax->get_rate_id(),
					'rate_code'          => $parent_tax->get_rate_code(),
					'label'              => $parent_tax->get_label(),
					'compound'           => $parent_tax->get_compound(),
					'tax_total'          => $parent_tax->get_tax_total(),
					'shipping_tax_total' => 0,
				)
			);
			$renewal->add_item( $tax );
		}

		$renewal->set_cart_tax( $parent->get_cart_tax() );
		$renewal->set_total( $parent->get_total() );
		$renewal->set_payment_method( 'wrb_stripe' );
		$renewal->set_payment_method_title( __( 'Stripe Subscriptions', 'wrb' ) );
		$renewal->update_meta_data( '_wrb_subscription_id', WRB_Subscription::get( $post_id, 'stripe_id' ) );
		$renewal->update_meta_data( '_wrb_invoice_id', $invoice['id'] );
		$renewal->update_meta_data( '_wrb_parent_order', $parent->get_id() );
		$renewal->save();

		// Flag a price mismatch instead of hiding it (for example after a Stripe-side price change).
		$paid_minor = isset( $invoice['amount_due'] ) ? (int) $invoice['amount_due'] : 0;
		if ( $paid_minor && WRB_Stripe_API::to_minor( $parent->get_total(), $parent->get_currency() ) !== $paid_minor ) {
			$renewal->add_order_note(
				sprintf(
					'Warning: Stripe invoice %1$s is for %2$s but this order total is %3$s.',
					$invoice['id'],
					wp_strip_all_tags( wc_price( WRB_Stripe_API::from_minor( $paid_minor, $parent->get_currency() ), array( 'currency' => $parent->get_currency() ) ) ),
					wp_strip_all_tags( wc_price( $parent->get_total(), array( 'currency' => $parent->get_currency() ) ) )
				)
			);
		}

		$renewal->add_order_note( sprintf( 'Renewal order created for Stripe invoice %s.', $invoice['id'] ) );
		return $renewal;
	}

	/* ---------------------------------------------------------------------
	 * Payload helpers (tolerate both older and newer Stripe API shapes)
	 * ------------------------------------------------------------------ */

	private static function invoice_subscription_id( array $invoice ) {
		if ( ! empty( $invoice['subscription'] ) ) {
			return is_array( $invoice['subscription'] ) ? $invoice['subscription']['id'] : $invoice['subscription'];
		}
		if ( ! empty( $invoice['parent']['subscription_details']['subscription'] ) ) {
			return $invoice['parent']['subscription_details']['subscription'];
		}
		return '';
	}

	/**
	 * End of the billing period this invoice paid for = the next payment date.
	 */
	private static function invoice_period_end( array $invoice ) {
		if ( ! empty( $invoice['lines']['data'][0]['period']['end'] ) ) {
			return (int) $invoice['lines']['data'][0]['period']['end'];
		}
		return 0;
	}
}

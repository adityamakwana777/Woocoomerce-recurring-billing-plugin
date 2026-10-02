<?php
/**
 * Local subscription records.
 *
 * Stripe is the source of truth for billing; each Stripe subscription has a
 * matching local record (a non-public custom post type) so the store can list
 * subscriptions and show customers their status without calling Stripe.
 *
 * @package RecurringBilling
 */

defined( 'ABSPATH' ) || exit;

class WRB_Subscription {

	const POST_TYPE = 'wrb_subscription';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
	}

	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Subscriptions', 'wrb' ),
					'singular_name' => __( 'Subscription', 'wrb' ),
					'menu_name'     => __( 'Subscriptions', 'wrb' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'woocommerce',
				'supports'        => array( 'title' ),
				'map_meta_cap'    => true,
				'capability_type' => 'shop_order',
				// Records come from Stripe; they are not created or deleted by hand.
				'capabilities'    => array(
					'create_posts' => 'do_not_allow',
					'delete_posts' => 'do_not_allow',
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Data access
	 * ------------------------------------------------------------------ */

	public static function statuses() {
		return array(
			'incomplete'         => __( 'Incomplete', 'wrb' ),
			'incomplete_expired' => __( 'Expired', 'wrb' ),
			'active'             => __( 'Active', 'wrb' ),
			'past_due'           => __( 'Past due', 'wrb' ),
			'unpaid'             => __( 'Unpaid', 'wrb' ),
			'canceled'           => __( 'Cancelled', 'wrb' ),
		);
	}

	public static function status_label( $status ) {
		$labels = self::statuses();
		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( str_replace( '_', ' ', (string) $status ) );
	}

	/**
	 * @param array $data stripe_id, user_id, parent_order, product_id, status, interval, amount, currency, next_payment.
	 * @return int|WP_Error Post ID.
	 */
	public static function create( array $data ) {
		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				/* translators: %s: Stripe subscription id */
				'post_title'  => sprintf( __( 'Subscription %s', 'wrb' ), $data['stripe_id'] ),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		foreach ( $data as $key => $value ) {
			update_post_meta( $post_id, '_wrb_' . $key, $value );
		}
		update_post_meta( $post_id, '_wrb_cancel_at_period_end', 'no' );

		return $post_id;
	}

	/**
	 * @return int Post ID, or 0 when unknown.
	 */
	public static function find_by_stripe_id( $stripe_id ) {
		if ( ! $stripe_id ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_wrb_stripe_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $stripe_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * @return int[] Post IDs of a customer's subscriptions, newest first.
	 */
	public static function find_by_user( $user_id ) {
		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'meta_key'       => '_wrb_user_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (int) $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
	}

	public static function get( $post_id, $key, $default = '' ) {
		$value = get_post_meta( $post_id, '_wrb_' . $key, true );
		return '' === $value ? $default : $value;
	}

	public static function set( $post_id, $key, $value ) {
		update_post_meta( $post_id, '_wrb_' . $key, $value );
	}

	/**
	 * Copy the fields we care about from a Stripe subscription object.
	 *
	 * Reads the period end from either the subscription or its first item, so it
	 * works whichever Stripe API version the webhook endpoint uses.
	 */
	public static function update_from_stripe( $post_id, array $stripe_sub ) {
		if ( ! empty( $stripe_sub['status'] ) ) {
			self::set( $post_id, 'status', sanitize_key( $stripe_sub['status'] ) );
		}
		self::set( $post_id, 'cancel_at_period_end', ! empty( $stripe_sub['cancel_at_period_end'] ) ? 'yes' : 'no' );

		$period_end = 0;
		if ( ! empty( $stripe_sub['current_period_end'] ) ) {
			$period_end = (int) $stripe_sub['current_period_end'];
		} elseif ( ! empty( $stripe_sub['items']['data'][0]['current_period_end'] ) ) {
			$period_end = (int) $stripe_sub['items']['data'][0]['current_period_end'];
		}
		if ( $period_end ) {
			self::set( $post_id, 'next_payment', $period_end );
		}
	}

	/* ---------------------------------------------------------------------
	 * Admin list
	 * ------------------------------------------------------------------ */

	public static function columns() {
		return array(
			'title'         => __( 'Subscription', 'wrb' ),
			'wrb_customer'  => __( 'Customer', 'wrb' ),
			'wrb_product'   => __( 'Product', 'wrb' ),
			'wrb_amount'    => __( 'Amount', 'wrb' ),
			'wrb_status'    => __( 'Status', 'wrb' ),
			'wrb_next'      => __( 'Next payment', 'wrb' ),
		);
	}

	public static function render_column( $column, $post_id ) {
		switch ( $column ) {
			case 'wrb_customer':
				$user = get_userdata( (int) self::get( $post_id, 'user_id', 0 ) );
				echo $user ? esc_html( $user->display_name . ' (' . $user->user_email . ')' ) : '&ndash;';
				break;

			case 'wrb_product':
				echo esc_html( get_the_title( (int) self::get( $post_id, 'product_id', 0 ) ) );
				break;

			case 'wrb_amount':
				echo wp_kses_post( wc_price( (float) self::get( $post_id, 'amount', 0 ), array( 'currency' => self::get( $post_id, 'currency' ) ) ) );
				echo ' / ' . esc_html( self::get( $post_id, 'interval', 'month' ) );
				break;

			case 'wrb_status':
				echo esc_html( self::status_label( self::get( $post_id, 'status' ) ) );
				if ( 'yes' === self::get( $post_id, 'cancel_at_period_end' ) ) {
					echo ' ' . esc_html__( '(ends at period end)', 'wrb' );
				}
				break;

			case 'wrb_next':
				$timestamp = (int) self::get( $post_id, 'next_payment', 0 );
				echo $timestamp ? esc_html( wp_date( get_option( 'date_format' ), $timestamp ) ) : '&ndash;';
				break;
		}
	}
}

<?php
/**
 * "Subscriptions" tab in My Account.
 *
 * Customers see their subscriptions and can cancel (at the end of the period they
 * already paid for) or resume a cancellation. The change is sent to Stripe first;
 * the local record is updated only if Stripe accepts it.
 *
 * @package RecurringBilling
 */

defined( 'ABSPATH' ) || exit;

class WRB_Account {

	const ENDPOINT = 'my-subscriptions';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render' ) );
		add_action( 'admin_post_wrb_cancel', array( __CLASS__, 'handle_cancel' ) );
		add_action( 'admin_post_wrb_resume', array( __CLASS__, 'handle_resume' ) );
	}

	public static function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	public static function menu_item( $items ) {
		$new = array();
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'orders' === $key ) {
				$new[ self::ENDPOINT ] = __( 'Subscriptions', 'wrb' );
			}
		}
		if ( ! isset( $new[ self::ENDPOINT ] ) ) {
			$new[ self::ENDPOINT ] = __( 'Subscriptions', 'wrb' );
		}
		return $new;
	}

	/* ---------------------------------------------------------------------
	 * Page
	 * ------------------------------------------------------------------ */

	public static function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message = isset( $_GET['wrb_msg'] ) ? sanitize_key( wp_unslash( $_GET['wrb_msg'] ) ) : '';
		if ( 'cancelled' === $message ) {
			wc_print_notice( __( 'Your subscription will end after the current billing period. You will not be charged again.', 'wrb' ), 'success' );
		} elseif ( 'resumed' === $message ) {
			wc_print_notice( __( 'Your subscription has been resumed.', 'wrb' ), 'success' );
		} elseif ( 'error' === $message ) {
			wc_print_notice( __( 'We could not update your subscription. Please try again or contact us.', 'wrb' ), 'error' );
		}

		$ids = WRB_Subscription::find_by_user( get_current_user_id() );
		if ( ! $ids ) {
			echo '<p>' . esc_html__( 'You have no subscriptions yet.', 'wrb' ) . '</p>';
			return;
		}
		?>
		<table class="woocommerce-orders-table shop_table shop_table_responsive">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Product', 'wrb' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wrb' ); ?></th>
					<th><?php esc_html_e( 'Price', 'wrb' ); ?></th>
					<th><?php esc_html_e( 'Next payment', 'wrb' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $ids as $id ) : ?>
				<?php
				$status    = WRB_Subscription::get( $id, 'status' );
				$ending    = 'yes' === WRB_Subscription::get( $id, 'cancel_at_period_end' );
				$timestamp = (int) WRB_Subscription::get( $id, 'next_payment', 0 );
				$date      = $timestamp ? wp_date( get_option( 'date_format' ), $timestamp ) : '';
				?>
				<tr>
					<td><?php echo esc_html( get_the_title( (int) WRB_Subscription::get( $id, 'product_id', 0 ) ) ); ?></td>
					<td><?php echo esc_html( WRB_Subscription::status_label( $status ) ); ?></td>
					<td>
						<?php echo wp_kses_post( wc_price( (float) WRB_Subscription::get( $id, 'amount', 0 ), array( 'currency' => WRB_Subscription::get( $id, 'currency' ) ) ) ); ?>
						/ <?php echo esc_html( WRB_Subscription::get( $id, 'interval', 'month' ) ); ?>
					</td>
					<td>
						<?php
						if ( 'canceled' === $status || 'incomplete_expired' === $status ) {
							echo '&ndash;';
						} elseif ( $ending ) {
							/* translators: %s: date */
							echo esc_html( sprintf( __( 'Ends on %s', 'wrb' ), $date ) );
						} else {
							echo esc_html( $date ? $date : '–' );
						}
						?>
					</td>
					<td>
						<?php if ( in_array( $status, array( 'active', 'past_due' ), true ) ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="<?php echo $ending ? 'wrb_resume' : 'wrb_cancel'; ?>">
								<input type="hidden" name="subscription" value="<?php echo esc_attr( $id ); ?>">
								<?php wp_nonce_field( 'wrb_action_' . $id, '_wrb_nonce' ); ?>
								<button type="submit" class="button">
									<?php echo $ending ? esc_html__( 'Resume', 'wrb' ) : esc_html__( 'Cancel', 'wrb' ); ?>
								</button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Actions
	 * ------------------------------------------------------------------ */

	public static function handle_cancel() {
		self::change_cancellation( true );
	}

	public static function handle_resume() {
		self::change_cancellation( false );
	}

	/**
	 * @param bool $cancel True = cancel at period end, false = undo that.
	 */
	private static function change_cancellation( $cancel ) {
		$back = wc_get_account_endpoint_url( self::ENDPOINT );

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
			exit;
		}

		$post_id = isset( $_POST['subscription'] ) ? absint( $_POST['subscription'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$nonce   = isset( $_POST['_wrb_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wrb_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		// The nonce ties the request to this subscription; the owner check stops customers touching each other's.
		if ( ! $post_id || ! wp_verify_nonce( $nonce, 'wrb_action_' . $post_id )
			|| (int) WRB_Subscription::get( $post_id, 'user_id', 0 ) !== get_current_user_id() ) {
			wp_safe_redirect( add_query_arg( 'wrb_msg', 'error', $back ) );
			exit;
		}

		$api    = new WRB_Stripe_API( WRB_Gateway::secret_key() );
		$result = $api->post(
			'subscriptions/' . rawurlencode( (string) WRB_Subscription::get( $post_id, 'stripe_id' ) ),
			array( 'cancel_at_period_end' => $cancel ? 'true' : 'false' )
		);

		if ( is_wp_error( $result ) ) {
			wc_get_logger()->error( $result->get_error_message(), array( 'source' => 'wrb', 'subscription' => $post_id ) );
			wp_safe_redirect( add_query_arg( 'wrb_msg', 'error', $back ) );
			exit;
		}

		WRB_Subscription::update_from_stripe( $post_id, $result );

		$parent = wc_get_order( (int) WRB_Subscription::get( $post_id, 'parent_order', 0 ) );
		if ( $parent ) {
			$parent->add_order_note( $cancel ? 'Customer cancelled the subscription (ends at period end).' : 'Customer resumed the subscription.' );
		}

		wp_safe_redirect( add_query_arg( 'wrb_msg', $cancel ? 'cancelled' : 'resumed', $back ) );
		exit;
	}
}

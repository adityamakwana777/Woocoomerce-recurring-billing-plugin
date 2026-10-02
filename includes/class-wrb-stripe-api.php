<?php
/**
 * Minimal Stripe API client (no SDK needed).
 *
 * @package RecurringBilling
 */

defined( 'ABSPATH' ) || exit;

class WRB_Stripe_API {

	const BASE_URL    = 'https://api.stripe.com/v1/';
	const API_VERSION = '2024-06-20';

	/** @var string */
	private $secret_key;

	public function __construct( $secret_key ) {
		$this->secret_key = $secret_key;
	}

	/**
	 * @param string      $path            e.g. "subscriptions".
	 * @param array       $body            Form fields (nested arrays are supported).
	 * @param string|null $idempotency_key Prevents duplicates when a request is retried.
	 * @return array|WP_Error
	 */
	public function post( $path, $body = array(), $idempotency_key = null ) {
		return $this->request( 'POST', $path, $body, $idempotency_key );
	}

	/**
	 * @return array|WP_Error
	 */
	public function get( $path ) {
		return $this->request( 'GET', $path );
	}

	/**
	 * @return array|WP_Error
	 */
	public function delete( $path ) {
		return $this->request( 'DELETE', $path );
	}

	private function request( $method, $path, $body = array(), $idempotency_key = null ) {
		if ( empty( $this->secret_key ) ) {
			return new WP_Error( 'wrb_no_key', 'Stripe secret key is not configured.' );
		}

		$headers = array(
			'Authorization'  => 'Bearer ' . $this->secret_key,
			'Stripe-Version' => self::API_VERSION,
		);
		if ( $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}

		$args = array(
			'method'  => $method,
			'timeout' => 45,
			'headers' => $headers,
		);
		if ( 'POST' === $method ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( self::BASE_URL . ltrim( $path, '/' ), $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 || ! is_array( $data ) || isset( $data['error'] ) ) {
			$error   = ( is_array( $data ) && isset( $data['error'] ) ) ? $data['error'] : array();
			$message = isset( $error['message'] ) ? $error['message'] : 'Unexpected response from Stripe.';
			return new WP_Error( 'wrb_stripe_error', $message, $error );
		}

		return $data;
	}

	private static function is_zero_decimal( $currency ) {
		$zero_decimal = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );
		return in_array( strtoupper( $currency ), $zero_decimal, true );
	}

	/**
	 * Decimal amount -> Stripe's smallest currency unit (three-decimal currencies are not handled).
	 */
	public static function to_minor( $amount, $currency ) {
		return self::is_zero_decimal( $currency ) ? (int) round( $amount ) : (int) round( $amount * 100 );
	}

	/**
	 * Stripe's smallest currency unit -> decimal amount.
	 */
	public static function from_minor( $amount, $currency ) {
		return self::is_zero_decimal( $currency ) ? (float) $amount : (float) $amount / 100;
	}
}

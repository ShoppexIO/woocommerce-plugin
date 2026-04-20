<?php
/**
 * Thin HTTP client for the Shoppex Dev API (/dev/v1/*).
 *
 * @package Shoppex_Pay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Shoppex_Api_Client
 */
class Shoppex_Api_Client {

	/**
	 * API key (shx_ + 32 chars).
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Base URL, e.g. https://api.shoppex.io.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Debug mode flag.
	 *
	 * @var bool
	 */
	private $debug;

	/**
	 * Constructor.
	 *
	 * @param string $api_key  API key.
	 * @param string $base_url Optional base URL override.
	 * @param bool   $debug    Enable debug logging.
	 */
	public function __construct( $api_key, $base_url = SHOPPEX_PAY_API_BASE, $debug = false ) {
		$this->api_key  = $api_key;
		$this->base_url = rtrim( $base_url, '/' );
		$this->debug    = (bool) $debug;
	}

	/**
	 * Create a hosted checkout session.
	 *
	 * @param array  $payload         Session body (product, amount, currency, ...).
	 * @param string $idempotency_key Stable key derived from the WC order.
	 * @return array { ok: bool, status: int, data: array, error: ?string }
	 */
	public function create_checkout_session( array $payload, $idempotency_key ) {
		return $this->request(
			'POST',
			'/dev/v1/checkout/sessions',
			$payload,
			array( 'Idempotency-Key' => $idempotency_key )
		);
	}

	/**
	 * Fetch a payment / order by uniqid.
	 *
	 * @param string $uniqid Shoppex uniqid.
	 * @return array
	 */
	public function get_payment( $uniqid ) {
		return $this->request( 'GET', '/dev/v1/payments/' . rawurlencode( $uniqid ) );
	}

	/**
	 * Refund an order. Only Stripe / PayPal underlying gateways are supported server-side;
	 * other PSPs return VALIDATION_ERROR which callers should surface gracefully.
	 *
	 * @param string     $order_uniqid Shoppex order uniqid.
	 * @param float|null $amount       Refund amount. Null = full refund.
	 * @param string     $reason       Optional reason text.
	 * @return array
	 */
	public function refund_order( $order_uniqid, $amount, $reason = '' ) {
		$body = array();
		if ( null !== $amount ) {
			$body['amount'] = (string) $amount;
		}
		if ( '' !== $reason ) {
			$body['reason'] = $reason;
		}
		return $this->request(
			'POST',
			'/dev/v1/orders/' . rawurlencode( $order_uniqid ) . '/refund',
			$body,
			array( 'Idempotency-Key' => 'woo_refund_' . $order_uniqid . '_' . ( null === $amount ? 'full' : (string) $amount ) )
		);
	}

	/**
	 * Low-level HTTP request.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path starting with /.
	 * @param array|null $body   JSON body (or null for no body).
	 * @param array      $extra  Extra headers.
	 * @return array
	 */
	private function request( $method, $path, $body = null, array $extra = array() ) {
		$url = $this->base_url . $path;

		$headers = array(
			'Authorization' => 'Bearer ' . $this->api_key,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
			'User-Agent'    => 'ShoppexPay-WooCommerce/' . SHOPPEX_PAY_VERSION . ' (PHP ' . PHP_VERSION . ')',
		);
		foreach ( $extra as $k => $v ) {
			$headers[ $k ] = $v;
		}

		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => $headers,
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		if ( $this->debug ) {
			$this->log(
				sprintf(
					'Request %s %s body=%s',
					$method,
					$path,
					null === $body ? '(none)' : wp_json_encode( $body )
				)
			);
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			if ( $this->debug ) {
				$this->log( 'Request transport error: ' . $response->get_error_message() );
			}
			return array(
				'ok'     => false,
				'status' => 0,
				'data'   => array(),
				'error'  => $response->get_error_message(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$parsed = json_decode( $raw, true );

		if ( $this->debug ) {
			$this->log( sprintf( 'Response %d %s', $status, is_string( $raw ) ? $raw : '(binary)' ) );
		}

		if ( ! is_array( $parsed ) ) {
			return array(
				'ok'     => false,
				'status' => $status,
				'data'   => array(),
				'error'  => 'Invalid JSON response',
			);
		}

		$ok = ( $status >= 200 && $status < 300 );

		$envelope = array();
		if ( isset( $parsed['data'] ) && is_array( $parsed['data'] ) ) {
			$envelope = $parsed['data'];
		}

		$error = null;
		if ( ! $ok ) {
			if ( isset( $parsed['error'] ) && is_array( $parsed['error'] ) && isset( $parsed['error']['message'] ) ) {
				$error = (string) $parsed['error']['message'];
			} elseif ( isset( $parsed['message'] ) ) {
				$error = (string) $parsed['message'];
			} else {
				$error = sprintf( 'HTTP %d', $status );
			}
		}

		return array(
			'ok'     => $ok,
			'status' => $status,
			'data'   => $envelope,
			'raw'    => $parsed,
			'error'  => $error,
		);
	}

	/**
	 * Write a debug line via the WC logger.
	 *
	 * @param string $message Line to log.
	 */
	private function log( $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->debug( $message, array( 'source' => 'shoppex-pay' ) );
		}
	}
}

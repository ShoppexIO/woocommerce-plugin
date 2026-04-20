<?php
/**
 * Receives Shoppex webhook events and maps them onto WooCommerce order status.
 *
 * @package Shoppex_Pay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Shoppex_Webhook_Handler
 */
class Shoppex_Webhook_Handler {

	/**
	 * Hook registration — called from the main plugin bootstrap.
	 */
	public static function register() {
		add_action( 'woocommerce_api_shoppex_webhook', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Entry point for ?wc-api=shoppex_webhook.
	 */
	public static function handle() {
		$gateway = self::get_gateway();
		if ( ! $gateway ) {
			status_header( 503 );
			self::respond( array( 'error' => 'gateway_unavailable' ), 503 );
			return;
		}

		$raw_body = file_get_contents( 'php://input' );
		if ( false === $raw_body ) {
			$raw_body = '';
		}

		$signature = self::header( 'X-Shoppex-Signature' );
		$secret    = (string) $gateway->webhook_secret;

		if ( '' === $secret ) {
			self::log( $gateway, 'Rejected webhook: no webhook_secret configured.' );
			self::respond( array( 'error' => 'webhook_not_configured' ), 503 );
			return;
		}

		if ( ! self::verify_signature( $raw_body, $signature, $secret ) ) {
			self::log( $gateway, 'Rejected webhook: invalid signature.' );
			self::respond( array( 'error' => 'invalid_signature' ), 401 );
			return;
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			self::respond( array( 'error' => 'invalid_payload' ), 400 );
			return;
		}

		$event = self::header( 'X-Shoppex-Event' );
		if ( '' === $event && isset( $payload['event'] ) ) {
			$event = (string) $payload['event'];
		}

		$data = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();

		$order = self::resolve_order( $data );
		if ( ! $order ) {
			self::log( $gateway, sprintf( 'Webhook %s: no matching WC order found.', $event ) );
			// We ACK so the webhook doesn't get retried forever — unknown orders
			// are not a transient condition.
			self::respond(
				array(
					'ok'      => true,
					'matched' => false,
				),
				200
			);
			return;
		}

		self::apply_event( $order, $event, $data );

		$order->update_meta_data( WC_Gateway_Shoppex::META_LAST_STATUS, isset( $data['status'] ) ? (string) $data['status'] : '' );
		if ( isset( $data['gateway'] ) ) {
			$order->update_meta_data( WC_Gateway_Shoppex::META_PAYMENT_METHOD, (string) $data['gateway'] );
		}
		$order->save();

		self::log( $gateway, sprintf( 'Handled webhook %s for WC order #%d.', $event, $order->get_id() ) );
		self::respond( array( 'ok' => true ), 200 );
	}

	/**
	 * Map a webhook event + status onto WC order state.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $event Event name, e.g. order:paid.
	 * @param array    $data  Webhook data.data payload.
	 */
	private static function apply_event( $order, $event, array $data ) {
		$status = isset( $data['status'] ) ? strtoupper( (string) $data['status'] ) : '';

		// Short-circuit: once an order is paid, refunded, or cancelled on our
		// side we don't flip-flop it on out-of-order webhook arrivals.
		// `processing` + `completed` both mean "paid" in WooCommerce — the
		// distinction is whether the product ships (processing = physical to
		// ship; completed = virtual/downloadable). Both must be protected from
		// a late `order:cancelled` from Shoppex.
		$paid_or_terminal = array_merge(
			array( 'processing', 'completed', 'refunded', 'cancelled' ),
			(array) wc_get_is_paid_statuses()
		);
		$paid_or_terminal = array_unique( array_filter( $paid_or_terminal ) );

		if ( in_array( $order->get_status(), $paid_or_terminal, true )
			&& 'order:paid' !== $event ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: event name, 2: status code. */
					__( 'Shoppex Pay: ignored webhook %1$s (status %2$s) — order already paid or in a terminal state.', 'shoppex-pay' ),
					$event,
					$status
				)
			);
			return;
		}

		switch ( $event ) {
			case 'order:paid':
				if ( ! $order->is_paid() ) {
					$txn_id = isset( $data['uniqid'] ) ? (string) $data['uniqid'] : '';
					$order->payment_complete( $txn_id );
					$order->add_order_note(
						sprintf(
							/* translators: %s: gateway name. */
							__( 'Shoppex Pay: payment received via %s.', 'shoppex-pay' ),
							isset( $data['gateway'] ) ? (string) $data['gateway'] : 'shoppex'
						)
					);
				}
				break;

			case 'order:partial':
				$order->update_status(
					'on-hold',
					__( 'Shoppex Pay: partial payment received, awaiting remainder.', 'shoppex-pay' )
				);
				break;

			case 'order:cancelled':
				$order->update_status(
					'cancelled',
					__( 'Shoppex Pay: order cancelled / expired.', 'shoppex-pay' )
				);
				break;

			case 'order:disputed':
				$order->update_status(
					'on-hold',
					__( 'Shoppex Pay: order disputed — manual review required.', 'shoppex-pay' )
				);
				break;

			case 'order:created':
			case 'order:updated':
				if ( 'PENDING' === $status || 'WAITING_FOR_CONFIRMATIONS' === $status ) {
					if ( 'pending' === $order->get_status() ) {
						// Already pending — just add a trail.
						$order->add_order_note(
							sprintf(
								/* translators: 1: event name, 2: status code. */
								__( 'Shoppex Pay: %1$s (%2$s).', 'shoppex-pay' ),
								$event,
								$status
							)
						);
					} else {
						$order->update_status(
							'on-hold',
							sprintf(
								/* translators: %s: status code. */
								__( 'Shoppex Pay: awaiting confirmation (%s).', 'shoppex-pay' ),
								$status
							)
						);
					}
				}
				break;

			default:
				$order->add_order_note(
					sprintf(
						/* translators: 1: event name, 2: status code. */
						__( 'Shoppex Pay: received %1$s (%2$s) — no action taken.', 'shoppex-pay' ),
						$event,
						$status
					)
				);
		}
	}

	/**
	 * Find the WC order referenced by the webhook.
	 *
	 * Matching order:
	 *   1) data.custom_fields.wc_order_id (set by us in process_payment)
	 *   2) Fallback: meta lookup on _shoppex_uniqid (HPOS-aware).
	 *
	 * @param array $data Webhook data payload.
	 * @return WC_Order|null
	 */
	private static function resolve_order( array $data ) {
		$custom = self::custom_fields( $data );

		if ( isset( $custom['wc_order_id'] ) ) {
			$order = wc_get_order( (int) $custom['wc_order_id'] );
			if ( $order instanceof WC_Order ) {
				// Defensive key check if available — guards against webhook replay
				// across environments using the same shop.
				$expected = isset( $custom['wc_order_key'] ) ? (string) $custom['wc_order_key'] : '';
				if ( '' === $expected || hash_equals( $expected, (string) $order->get_order_key() ) ) {
					return $order;
				}
			}
		}

		if ( isset( $data['uniqid'] ) && '' !== $data['uniqid'] ) {
			$orders = wc_get_orders(
				array(
					'limit'      => 1,
					'meta_key'   => WC_Gateway_Shoppex::META_UNIQID,
					'meta_value' => (string) $data['uniqid'],
				)
			);
			if ( ! empty( $orders ) ) {
				return $orders[0];
			}
		}

		return null;
	}

	/**
	 * Normalize the custom_fields field. Shoppex may send it as an array of
	 * {name, value} pairs or as an associative array — we tolerate both.
	 *
	 * @param array $data Webhook data payload.
	 * @return array Associative array name => value.
	 */
	private static function custom_fields( array $data ) {
		if ( empty( $data['custom_fields'] ) ) {
			return array();
		}
		$cf = $data['custom_fields'];

		if ( is_string( $cf ) ) {
			$decoded = json_decode( $cf, true );
			$cf      = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $cf ) ) {
			return array();
		}

		$assoc = array();
		foreach ( $cf as $key => $value ) {
			if ( is_array( $value ) && isset( $value['name'] ) ) {
				$assoc[ (string) $value['name'] ] = isset( $value['value'] ) ? (string) $value['value'] : '';
			} elseif ( is_string( $key ) ) {
				$assoc[ $key ] = is_scalar( $value ) ? (string) $value : '';
			}
		}
		return $assoc;
	}

	/**
	 * Constant-time HMAC-SHA512 verification.
	 *
	 * @param string $body      Raw request body.
	 * @param string $signature Header value.
	 * @param string $secret    Shared secret.
	 * @return bool
	 */
	public static function verify_signature( $body, $signature, $secret ) {
		if ( '' === $signature || '' === $secret ) {
			return false;
		}
		$expected = hash_hmac( 'sha512', $body, $secret );
		return hash_equals( $expected, $signature );
	}

	/**
	 * Get a request header in a way that survives different SAPIs.
	 *
	 * @param string $name Header name.
	 * @return string
	 */
	private static function header( $name ) {
		$server_key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		if ( isset( $_SERVER[ $server_key ] ) ) {
			return trim( wp_unslash( (string) $_SERVER[ $server_key ] ) );
		}
		if ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			if ( is_array( $headers ) ) {
				foreach ( $headers as $k => $v ) {
					if ( 0 === strcasecmp( $k, $name ) ) {
						return trim( (string) $v );
					}
				}
			}
		}
		return '';
	}

	/**
	 * Resolve the registered Shoppex gateway instance.
	 *
	 * @return WC_Gateway_Shoppex|null
	 */
	private static function get_gateway() {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		if ( isset( $gateways['shoppex'] ) && $gateways['shoppex'] instanceof WC_Gateway_Shoppex ) {
			return $gateways['shoppex'];
		}
		return null;
	}

	/**
	 * Emit JSON response.
	 *
	 * @param array $payload Body.
	 * @param int   $status  HTTP status.
	 */
	private static function respond( $payload, $status = 200 ) {
		status_header( $status );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $payload );
		exit;
	}

	/**
	 * Debug log via the WC logger.
	 *
	 * @param WC_Gateway_Shoppex $gateway Gateway (for debug flag).
	 * @param string             $message Line to log.
	 */
	private static function log( $gateway, $message ) {
		if ( ! $gateway || ! $gateway->debug ) {
			return;
		}
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->debug( $message, array( 'source' => 'shoppex-pay-webhook' ) );
		}
	}
}

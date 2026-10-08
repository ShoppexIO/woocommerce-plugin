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
	 * Accepted clock difference between Shoppex and this server, in seconds.
	 * Every delivery attempt is signed with a fresh timestamp, so retries pass.
	 */
	const SIGNATURE_TOLERANCE = 300;

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
			self::respond( array( 'error' => 'gateway_unavailable' ), 503 );
			return;
		}

		$raw_body = file_get_contents( 'php://input' );
		if ( false === $raw_body ) {
			$raw_body = '';
		}

		$secret = (string) $gateway->webhook_secret;
		if ( '' === $secret ) {
			self::log( $gateway, 'Rejected webhook: no webhook_secret configured.' );
			self::respond( array( 'error' => 'webhook_not_configured' ), 503 );
			return;
		}

		$verified = self::verify_signature(
			$raw_body,
			self::header( 'X-Shoppex-Signature-V2' ),
			self::header( 'X-Shoppex-Delivery' ),
			self::header( 'X-Shoppex-Timestamp' ),
			$secret,
			time()
		);
		if ( ! $verified ) {
			self::log( $gateway, 'Rejected webhook: invalid or expired signature.' );
			self::respond( array( 'error' => 'invalid_signature' ), 401 );
			return;
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			self::respond( array( 'error' => 'invalid_payload' ), 400 );
			return;
		}

		$result = self::process( $payload );
		self::log( $gateway, sprintf( 'Webhook %s: %s.', isset( $payload['event'] ) ? (string) $payload['event'] : '', $result ) );
		// Unknown orders are acknowledged too: a retry would not find them either.
		self::respond(
			array(
				'ok'     => true,
				'result' => $result,
			),
			200
		);
	}

	/**
	 * Apply a verified webhook payload to its WooCommerce order.
	 *
	 * The event name comes from the signed body. The X-Shoppex-Event header is
	 * not covered by the signature, so trusting it would let anyone turn a
	 * captured `order:created` delivery into `order:paid`.
	 *
	 * @param array $payload Decoded, signature-verified webhook body.
	 * @return string Outcome: `unmatched`, `applied` or `ignored`.
	 */
	public static function process( array $payload ) {
		$event = isset( $payload['event'] ) ? (string) $payload['event'] : '';
		$data  = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();

		$order = self::resolve_order( $data );
		if ( ! $order ) {
			return 'unmatched';
		}

		$applied = self::apply_event( $order, $event, $data );
		$order->save();
		return $applied ? 'applied' : 'ignored';
	}

	/**
	 * Map a webhook event + status onto WC order state.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $event Event name, e.g. order:paid.
	 * @param array    $data  Webhook data payload.
	 * @return bool Whether the order changed.
	 */
	private static function apply_event( $order, $event, array $data ) {
		$uniqid = isset( $data['uniqid'] ) ? (string) $data['uniqid'] : '';
		$status = isset( $data['status'] ) ? strtoupper( (string) $data['status'] ) : '';

		// A payment is money received, whichever Shoppex invoice of this order it
		// came through.
		if ( 'order:paid' === $event ) {
			return self::apply_paid( $order, $uniqid, $status, $data );
		}

		// Every other event describes one invoice. When the buyer started a newer
		// checkout for the same order, the old invoice expiring must not cancel
		// the order they are paying right now.
		if ( '' === $uniqid || $uniqid !== (string) $order->get_meta( WC_Gateway_Shoppex::META_UNIQID ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: event name, 2: Shoppex invoice id. */
					__( 'Shoppex Pay: ignored webhook %1$s for invoice %2$s — the order now uses a different Shoppex invoice.', 'shoppex-pay' ),
					$event,
					$uniqid
				)
			);
			return false;
		}

		$order->update_meta_data( WC_Gateway_Shoppex::META_LAST_STATUS, $status );

		// Only a paid order can be disputed, so a dispute must get past the
		// paid-order guard below. DISPUTED never means paid: hold the order.
		if ( 'order:disputed' === $event ) {
			if ( $order->has_status( array( 'refunded', 'cancelled' ) ) ) {
				$order->add_order_note( __( 'Shoppex Pay: dispute reported for an order that is already refunded or cancelled.', 'shoppex-pay' ) );
				return false;
			}
			$order->update_status( 'on-hold', __( 'Shoppex Pay: payment disputed — manual review required.', 'shoppex-pay' ) );
			return true;
		}

		// Once an order is paid, refunded or cancelled here, a late or
		// out-of-order event must not move it back.
		$paid_or_terminal = array_unique( array_merge( array( 'refunded', 'cancelled' ), (array) wc_get_is_paid_statuses() ) );
		if ( $order->has_status( $paid_or_terminal ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: event name, 2: status code. */
					__( 'Shoppex Pay: ignored webhook %1$s (status %2$s) — order already paid or in a terminal state.', 'shoppex-pay' ),
					$event,
					$status
				)
			);
			return false;
		}

		switch ( $event ) {
			case 'order:partial':
				$order->update_status( 'on-hold', __( 'Shoppex Pay: partial payment received, awaiting remainder.', 'shoppex-pay' ) );
				return true;

			case 'order:cancelled':
				$order->update_status( 'cancelled', __( 'Shoppex Pay: order cancelled / expired.', 'shoppex-pay' ) );
				return true;

			case 'order:created':
			case 'order:updated':
				if ( 'WAITING_FOR_CONFIRMATIONS' === $status && ! $order->has_status( 'on-hold' ) ) {
					$order->update_status( 'on-hold', __( 'Shoppex Pay: payment sent, awaiting blockchain confirmations.', 'shoppex-pay' ) );
					return true;
				}
				return false;

			default:
				$order->add_order_note(
					sprintf(
						/* translators: 1: event name, 2: status code. */
						__( 'Shoppex Pay: received %1$s (%2$s) — no action taken.', 'shoppex-pay' ),
						$event,
						$status
					)
				);
				return false;
		}
	}

	/**
	 * Complete the order for a paid Shoppex invoice — once, and only when the
	 * invoice paid at least the order total in the order currency.
	 *
	 * @param WC_Order $order  WooCommerce order.
	 * @param string   $uniqid Shoppex invoice id.
	 * @param string   $status Invoice status from the payload.
	 * @param array    $data   Webhook data payload.
	 * @return bool Whether the order changed.
	 */
	private static function apply_paid( $order, $uniqid, $status, array $data ) {
		// Shoppex retries deliver the same frozen payload; payment_complete()
		// stored the invoice id as the transaction id the first time.
		if ( '' === $uniqid || $uniqid === (string) $order->get_transaction_id() ) {
			return false;
		}

		if ( 'COMPLETED' !== $status ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: Shoppex invoice id, 2: status code. */
					__( 'Shoppex Pay: ignored order:paid for invoice %1$s with status %2$s.', 'shoppex-pay' ),
					$uniqid,
					$status
				)
			);
			return false;
		}

		if ( $order->is_paid() ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: Shoppex invoice id. */
					__( 'Shoppex Pay: invoice %s was paid, but this order is already paid. Refund the second payment in Shoppex.', 'shoppex-pay' ),
					$uniqid
				)
			);
			return false;
		}

		$currency = isset( $data['currency'] ) ? strtoupper( (string) $data['currency'] ) : '';
		$paid     = isset( $data['total_display'] ) && is_numeric( $data['total_display'] ) ? self::to_cents( $data['total_display'] ) : null;
		// total_display includes buyer-paid gateway fees and tips, so it may
		// exceed the order total; it may never fall short of it.
		if ( strtoupper( (string) $order->get_currency() ) !== $currency || null === $paid || $paid < self::to_cents( $order->get_total() ) ) {
			$order->update_status(
				'on-hold',
				sprintf(
					/* translators: 1: Shoppex invoice id, 2: paid amount, 3: paid currency, 4: order total, 5: order currency. */
					__( 'Shoppex Pay: invoice %1$s reports a payment of %2$s %3$s, but the order total is %4$s %5$s. Check the payment in Shoppex before you fulfil the order.', 'shoppex-pay' ),
					$uniqid,
					null === $paid ? '?' : number_format( $paid / 100, 2, '.', '' ),
					'' === $currency ? '?' : $currency,
					$order->get_total(),
					$order->get_currency()
				)
			);
			return true;
		}

		// Refunds go to the invoice that actually took the money.
		$order->update_meta_data( WC_Gateway_Shoppex::META_UNIQID, $uniqid );
		$order->update_meta_data( WC_Gateway_Shoppex::META_LAST_STATUS, $status );
		$gateway = isset( $data['gateway'] ) ? (string) $data['gateway'] : '';
		if ( '' !== $gateway ) {
			$order->update_meta_data( WC_Gateway_Shoppex::META_PAYMENT_METHOD, $gateway );
		}
		$order->payment_complete( $uniqid );
		$order->add_order_note(
			sprintf(
				/* translators: %s: gateway name. */
				__( 'Shoppex Pay: payment received via %s.', 'shoppex-pay' ),
				'' === $gateway ? 'Shoppex' : $gateway
			)
		);
		return true;
	}

	/**
	 * Amount in cents, rounded half up like Shoppex stores totals.
	 *
	 * @param string|float $amount Decimal amount.
	 * @return int
	 */
	private static function to_cents( $amount ) {
		return (int) round( (float) $amount * 100 );
	}

	/**
	 * Find the WC order referenced by the webhook through the metadata the
	 * plugin sent with the checkout session. The order key must match, so a
	 * second store sharing the Shoppex shop (staging) cannot move this order.
	 *
	 * @param array $data Webhook data payload.
	 * @return WC_Order|null
	 */
	private static function resolve_order( array $data ) {
		$fields = self::custom_fields( $data );
		if ( ! isset( $fields['wc_order_id'], $fields['wc_order_key'] ) ) {
			return null;
		}

		$order = wc_get_order( (int) $fields['wc_order_id'] );
		if ( ! $order instanceof WC_Order || ! hash_equals( (string) $order->get_order_key(), $fields['wc_order_key'] ) ) {
			return null;
		}
		return $order;
	}

	/**
	 * Read the checkout session metadata. Shoppex stores it as
	 * `{"customFields": [{"name": ..., "value": ...}]}` and sends that object.
	 *
	 * @param array $data Webhook data payload.
	 * @return array Associative array name => value.
	 */
	private static function custom_fields( array $data ) {
		$list = isset( $data['custom_fields']['customFields'] ) ? $data['custom_fields']['customFields'] : null;
		if ( ! is_array( $list ) ) {
			return array();
		}

		$fields = array();
		foreach ( $list as $field ) {
			if ( is_array( $field ) && isset( $field['name'], $field['value'] ) && is_scalar( $field['value'] ) ) {
				$fields[ (string) $field['name'] ] = (string) $field['value'];
			}
		}
		return $fields;
	}

	/**
	 * Verify `X-Shoppex-Signature-V2`: HMAC-SHA256 over
	 * `<delivery id>.<timestamp>.<raw body>`, with a fresh timestamp.
	 *
	 * @param string $body             Raw request body.
	 * @param string $signature_header X-Shoppex-Signature-V2 value, `v1,t=<ts>,h=<hex>`.
	 * @param string $delivery_id      X-Shoppex-Delivery value.
	 * @param string $timestamp_header X-Shoppex-Timestamp value.
	 * @param string $secret           Webhook secret.
	 * @param int    $now              Current Unix time.
	 * @return bool
	 */
	public static function verify_signature( $body, $signature_header, $delivery_id, $timestamp_header, $secret, $now ) {
		if ( '' === $secret || '' === $delivery_id || '' === $signature_header ) {
			return false;
		}

		$segments = array_map( 'trim', explode( ',', $signature_header ) );
		if ( ! in_array( 'v1', $segments, true ) ) {
			return false;
		}
		$fields = array();
		foreach ( $segments as $segment ) {
			if ( false !== strpos( $segment, '=' ) ) {
				list( $key, $value ) = explode( '=', $segment, 2 );
				$fields[ $key ]      = $value;
			}
		}

		$timestamp = isset( $fields['t'] ) ? $fields['t'] : '';
		$signature = isset( $fields['h'] ) ? strtolower( $fields['h'] ) : '';
		if ( ! ctype_digit( $timestamp ) || $timestamp !== $timestamp_header ) {
			return false;
		}
		if ( abs( (int) $now - (int) $timestamp ) > self::SIGNATURE_TOLERANCE ) {
			return false;
		}
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $signature ) ) {
			return false;
		}

		return hash_equals( hash_hmac( 'sha256', $delivery_id . '.' . $timestamp . '.' . $body, $secret ), $signature );
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
			return trim( sanitize_text_field( wp_unslash( $_SERVER[ $server_key ] ) ) );
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

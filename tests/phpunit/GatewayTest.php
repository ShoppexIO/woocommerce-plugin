<?php
/**
 * Checkout session creation, overlay display and refunds against the Dev API
 * shapes in apps/backend-elysia/src/routes/dev/v1 (checkout-sessions.routes.ts,
 * orders/refund.routes.ts).
 *
 * @package Shoppex_Pay
 */

use PHPUnit\Framework\TestCase;

final class GatewayTest extends TestCase {

	private const UNIQID      = '3f0e8a52-6c1d-4b7e-9a35-2d4c8f1b6e70';
	private const SESSION_URL = 'https://checkout.shoppex.io/invoice/3f0e8a52-6c1d-4b7e-9a35-2d4c8f1b6e70';

	/** @var WC_Order */
	private $order;

	protected function setUp(): void {
		Test_State::reset();
		WC_Gateway_Shoppex::remember_refund( null );
		$this->order              = new WC_Order( 1234, '49.99', 'EUR' );
		Test_State::$orders[1234] = $this->order;
	}

	private function gateway( array $settings = array() ) {
		Test_State::$options['woocommerce_shoppex_settings'] = $settings + array( 'api_key' => 'shx_test' );
		return new WC_Gateway_Shoppex();
	}

	private function queue_session() {
		Test_State::$responses[] = array(
			'status' => 201,
			'body'   => array(
				'data' => array(
					'id'     => self::UNIQID,
					'url'    => self::SESSION_URL,
					'status' => 'PENDING',
				),
			),
		);
	}

	private function last_request() {
		return end( Test_State::$requests );
	}

	public function test_redirect_mode_sends_the_buyer_to_the_hosted_checkout() {
		$this->queue_session();
		$result = $this->gateway()->process_payment( 1234 );

		$this->assertSame( array( 'result' => 'success', 'redirect' => self::SESSION_URL ), $result );
		$request = $this->last_request();
		$this->assertSame( 'https://api.shoppex.io/dev/v1/checkout/sessions', $request['url'] );
		$body = json_decode( $request['body'], true );
		$this->assertSame( 49.99, $body['amount'] );
		$this->assertSame( 'EUR', $body['currency'] );
		$this->assertSame( array( 'wc_order_id' => '1234', 'wc_order_key' => 'wc_order_key_1234', 'source' => 'woocommerce' ), $body['metadata'] );
		// The session schema has no white_label field; Elysia dropped it silently.
		$this->assertArrayNotHasKey( 'white_label', $body );
		$this->assertSame( self::UNIQID, $this->order->get_meta( WC_Gateway_Shoppex::META_UNIQID ) );
	}

	public function test_overlay_mode_sends_the_buyer_to_the_order_payment_page() {
		$this->queue_session();
		$result = $this->gateway( array( 'checkout_display' => 'overlay' ) )->process_payment( 1234 );

		$this->assertSame( $this->order->get_checkout_payment_url( true ), $result['redirect'] );
	}

	public function test_a_lost_answer_replays_its_session_and_later_attempts_get_a_new_one() {
		$gateway = $this->gateway();

		// The first create timed out before the plugin stored the session.
		Test_State::$responses[] = new WP_Error( 'http_request_failed', 'timeout' );
		$gateway->process_payment( 1234 );
		$first = $this->last_request()['headers']['Idempotency-Key'];
		$this->queue_session();
		$gateway->process_payment( 1234 );
		$this->assertSame( $first, $this->last_request()['headers']['Idempotency-Key'] );

		// The customer comes back later: the stored session may have expired,
		// and a replay would hand it out again for 24 hours.
		$this->queue_session();
		$gateway->process_payment( 1234 );
		$second = $this->last_request()['headers']['Idempotency-Key'];
		$this->assertNotSame( $first, $second );

		// A changed cart on the reused pending order: a reused key with a new
		// body is a 422 at Shoppex.
		$this->order->update_meta_data( WC_Gateway_Shoppex::META_UNIQID, '' );
		$this->order->total = '79.99';
		$this->queue_session();
		$gateway->process_payment( 1234 );
		$this->assertNotSame( $first, $this->last_request()['headers']['Idempotency-Key'] );
	}

	public function test_refund_amounts_are_sent_with_two_decimals() {
		// A store with three price decimals passes 30.300; Shoppex refuses it.
		$this->order->update_meta_data( WC_Gateway_Shoppex::META_UNIQID, self::UNIQID );
		WC_Gateway_Shoppex::remember_refund( new WC_Order_Refund( 501, 1234 ) );
		Test_State::$responses[] = array( 'status' => 200, 'body' => array( 'data' => array( 'message' => 'ok' ) ) );

		$this->gateway()->process_refund( 1234, '30.300', '' );

		$this->assertSame( array( 'amount' => '30.30' ), json_decode( $this->last_request()['body'], true ) );
	}

	public function test_two_refunds_of_the_same_amount_use_two_keys_and_a_retry_reuses_its_key() {
		$gateway = $this->gateway();
		$this->order->update_meta_data( WC_Gateway_Shoppex::META_UNIQID, self::UNIQID );
		$refunded = array( 'status' => 200, 'body' => array( 'data' => array( 'message' => 'Order refunded successfully.' ) ) );

		WC_Gateway_Shoppex::remember_refund( new WC_Order_Refund( 501, 1234 ) );
		Test_State::$responses[] = $refunded;
		$this->assertTrue( $gateway->process_refund( 1234, '10.00', '' ) );
		$first = $this->last_request()['headers']['Idempotency-Key'];
		$this->assertSame( 'https://api.shoppex.io/dev/v1/orders/' . self::UNIQID . '/refund', $this->last_request()['url'] );

		// A second, separate refund of the same amount.
		WC_Gateway_Shoppex::remember_refund( new WC_Order_Refund( 502, 1234 ) );
		Test_State::$responses[] = new WP_Error( 'http_request_failed', 'timeout' );
		$this->assertInstanceOf( WP_Error::class, $gateway->process_refund( 1234, '10.00', '' ) );
		$second = $this->last_request()['headers']['Idempotency-Key'];
		$this->assertNotSame( $first, $second );

		// WooCommerce deleted the failed refund; the merchant retries it.
		WC_Gateway_Shoppex::remember_refund( new WC_Order_Refund( 503, 1234 ) );
		Test_State::$responses[] = $refunded;
		$this->assertTrue( $gateway->process_refund( 1234, '10.00', '' ) );
		$this->assertSame( $second, $this->last_request()['headers']['Idempotency-Key'] );
	}

	public function test_a_refund_recorded_again_after_its_record_was_deleted_is_sent_as_a_new_refund() {
		// Refund 10.00, delete that refund record in WooCommerce, refund 10.00
		// again: a reused key made Shoppex replay the first success, and
		// WooCommerce recorded a refund that never happened.
		$gateway = $this->gateway();
		$this->order->update_meta_data( WC_Gateway_Shoppex::META_UNIQID, self::UNIQID );
		$refunded = array( 'status' => 200, 'body' => array( 'data' => array( 'message' => 'Order refunded successfully.' ) ) );

		WC_Gateway_Shoppex::remember_refund( new WC_Order_Refund( 501, 1234 ) );
		Test_State::$responses[] = $refunded;
		$gateway->process_refund( 1234, '10.00', 'Damaged' );
		$first = $this->last_request()['headers']['Idempotency-Key'];

		WC_Gateway_Shoppex::remember_refund( new WC_Order_Refund( 502, 1234 ) );
		Test_State::$responses[] = $refunded;
		$gateway->process_refund( 1234, '10.00', 'Damaged' );

		$this->assertNotSame( $first, $this->last_request()['headers']['Idempotency-Key'] );
	}

	public function test_a_retry_answered_still_processing_keeps_the_lost_key() {
		// Shoppex answers a reused key whose first request is still running (or
		// whose outcome it lost) with a validation error, not a success.
		$gateway = $this->gateway();
		$this->order->update_meta_data( WC_Gateway_Shoppex::META_UNIQID, self::UNIQID );

		WC_Gateway_Shoppex::remember_refund( new WC_Order_Refund( 501, 1234 ) );
		Test_State::$responses[] = new WP_Error( 'http_request_failed', 'timeout' );
		$gateway->process_refund( 1234, '10.00', '' );
		$lost = $this->last_request()['headers']['Idempotency-Key'];

		WC_Gateway_Shoppex::remember_refund( new WC_Order_Refund( 502, 1234 ) );
		Test_State::$responses[] = array( 'status' => 400, 'body' => array( 'error' => array( 'code' => 'VALIDATION_ERROR', 'message' => 'A request with this Idempotency-Key is still processing. Retry shortly.' ) ) );
		$this->assertInstanceOf( WP_Error::class, $gateway->process_refund( 1234, '10.00', '' ) );

		WC_Gateway_Shoppex::remember_refund( new WC_Order_Refund( 503, 1234 ) );
		Test_State::$responses[] = array( 'status' => 200, 'body' => array( 'data' => array( 'message' => 'ok' ) ) );
		$this->assertTrue( $gateway->process_refund( 1234, '10.00', '' ) );
		$this->assertSame( $lost, $this->last_request()['headers']['Idempotency-Key'] );

		// Settled: the next refund of the same amount is a new one.
		WC_Gateway_Shoppex::remember_refund( new WC_Order_Refund( 504, 1234 ) );
		Test_State::$responses[] = array( 'status' => 200, 'body' => array( 'data' => array( 'message' => 'ok' ) ) );
		$gateway->process_refund( 1234, '10.00', '' );
		$this->assertNotSame( $lost, $this->last_request()['headers']['Idempotency-Key'] );
	}

	public function test_a_refund_is_not_sent_twice_under_the_previous_refund_identity() {
		// A second gateway call in the same request without a new WooCommerce
		// refund would reuse the first refund's key and replay its success.
		$gateway = $this->gateway();
		$this->order->update_meta_data( WC_Gateway_Shoppex::META_UNIQID, self::UNIQID );
		WC_Gateway_Shoppex::remember_refund( new WC_Order_Refund( 501, 1234 ) );
		Test_State::$responses[] = array( 'status' => 200, 'body' => array( 'data' => array( 'message' => 'ok' ) ) );
		$gateway->process_refund( 1234, '10.00', '' );

		$this->assertInstanceOf( WP_Error::class, $gateway->process_refund( 1234, '10.00', '' ) );
		$this->assertCount( 1, Test_State::$requests );
	}

	public function test_a_second_lost_answer_does_not_forget_the_first() {
		$gateway = $this->gateway();
		$this->order->update_meta_data( WC_Gateway_Shoppex::META_UNIQID, self::UNIQID );

		WC_Gateway_Shoppex::remember_refund( new WC_Order_Refund( 501, 1234 ) );
		Test_State::$responses[] = new WP_Error( 'http_request_failed', 'timeout' );
		$gateway->process_refund( 1234, '10.00', '' );
		$lost = $this->last_request()['headers']['Idempotency-Key'];

		WC_Gateway_Shoppex::remember_refund( new WC_Order_Refund( 502, 1234 ) );
		Test_State::$responses[] = new WP_Error( 'http_request_failed', 'timeout' );
		$gateway->process_refund( 1234, '5.00', '' );

		WC_Gateway_Shoppex::remember_refund( new WC_Order_Refund( 503, 1234 ) );
		Test_State::$responses[] = array( 'status' => 200, 'body' => array( 'data' => array( 'message' => 'ok' ) ) );
		$gateway->process_refund( 1234, '10.00', '' );
		$this->assertSame( $lost, $this->last_request()['headers']['Idempotency-Key'] );
	}

	public function test_a_retry_of_a_lost_refund_with_another_reason_keeps_the_lost_key() {
		// Shoppex refuses a reused key with another body; a new key could pay twice.
		$gateway = $this->gateway();
		$this->order->update_meta_data( WC_Gateway_Shoppex::META_UNIQID, self::UNIQID );

		WC_Gateway_Shoppex::remember_refund( new WC_Order_Refund( 501, 1234 ) );
		Test_State::$responses[] = new WP_Error( 'http_request_failed', 'timeout' );
		$gateway->process_refund( 1234, '10.00', 'Damaged' );
		$lost = $this->last_request()['headers']['Idempotency-Key'];

		WC_Gateway_Shoppex::remember_refund( new WC_Order_Refund( 502, 1234 ) );
		Test_State::$responses[] = array( 'status' => 422, 'body' => array( 'error' => array( 'code' => 'VALIDATION_ERROR', 'message' => 'This Idempotency-Key was already used with a different request payload.' ) ) );
		$gateway->process_refund( 1234, '10.00', 'Broken on arrival' );
		$this->assertSame( $lost, $this->last_request()['headers']['Idempotency-Key'] );
	}

	public function test_a_retry_after_a_lost_answer_with_another_amount_is_a_new_refund() {
		$gateway = $this->gateway();
		$this->order->update_meta_data( WC_Gateway_Shoppex::META_UNIQID, self::UNIQID );

		WC_Gateway_Shoppex::remember_refund( new WC_Order_Refund( 501, 1234 ) );
		Test_State::$responses[] = new WP_Error( 'http_request_failed', 'timeout' );
		$gateway->process_refund( 1234, '10.00', '' );
		$lost = $this->last_request()['headers']['Idempotency-Key'];

		// A reused key with another body is a 422 at Shoppex.
		WC_Gateway_Shoppex::remember_refund( new WC_Order_Refund( 502, 1234 ) );
		Test_State::$responses[] = array( 'status' => 200, 'body' => array( 'data' => array( 'message' => 'ok' ) ) );
		$this->assertTrue( $gateway->process_refund( 1234, '12.00', '' ) );
		$this->assertNotSame( $lost, $this->last_request()['headers']['Idempotency-Key'] );
	}

	public function test_a_replayed_lost_refund_is_not_recorded_as_a_new_one() {
		// A 10.00 refund timed out but went through at Shoppex. The merchant
		// recorded it by hand. A later, real 10.00 refund reused the lost key:
		// Shoppex replayed the first success and WooCommerce showed 20.00
		// refunded while the buyer got 10.00.
		$gateway = $this->gateway();
		$this->order->update_meta_data( WC_Gateway_Shoppex::META_UNIQID, self::UNIQID );

		WC_Gateway_Shoppex::remember_refund( new WC_Order_Refund( 501, 1234 ) );
		Test_State::$responses[] = new WP_Error( 'http_request_failed', 'timeout' );
		$gateway->process_refund( 1234, '10.00', '' );
		$lost = $this->last_request()['headers']['Idempotency-Key'];

		WC_Gateway_Shoppex::remember_refund( new WC_Order_Refund( 502, 1234 ) );
		Test_State::$responses[] = array(
			'status'  => 200,
			'headers' => array( 'idempotency-status' => 'cached' ),
			'body'    => array( 'data' => array( 'message' => 'Order refunded successfully.' ) ),
		);
		$replayed = $gateway->process_refund( 1234, '10.00', '' );
		$this->assertSame( $lost, $this->last_request()['headers']['Idempotency-Key'] );
		// WooCommerce deletes the refund record when the gateway returns an error.
		$this->assertInstanceOf( WP_Error::class, $replayed );
		$this->assertSame( 'shoppex_refund_replayed', $replayed->code );
		$this->assertStringContainsString( 'already refunded 10.00 EUR', $replayed->get_error_message() );
		$this->assertStringContainsString( 'no new refund was sent', $replayed->get_error_message() );

		// The merchant tries again: a new refund under a new key.
		WC_Gateway_Shoppex::remember_refund( new WC_Order_Refund( 503, 1234 ) );
		Test_State::$responses[] = array(
			'status'  => 200,
			'headers' => array( 'idempotency-status' => 'created' ),
			'body'    => array( 'data' => array( 'message' => 'Order refunded successfully.' ) ),
		);
		$this->assertTrue( $gateway->process_refund( 1234, '10.00', '' ) );
		$this->assertNotSame( $lost, $this->last_request()['headers']['Idempotency-Key'] );
	}

	public function test_a_refund_of_another_order_is_refused_without_calling_shoppex() {
		// Only the refund WooCommerce just saved for this order names the operation.
		$this->order->update_meta_data( WC_Gateway_Shoppex::META_UNIQID, self::UNIQID );
		WC_Gateway_Shoppex::remember_refund( new WC_Order_Refund( 501, 999 ) );

		$this->assertInstanceOf( WP_Error::class, $this->gateway()->process_refund( 1234, '10.00', '' ) );
		$this->assertSame( array(), Test_State::$requests );
	}

	public function test_the_order_payment_page_opens_the_invoice_and_keeps_the_full_page_link() {
		$this->order->update_meta_data( WC_Gateway_Shoppex::META_UNIQID, self::UNIQID );
		$this->order->update_meta_data( WC_Gateway_Shoppex::META_SESSION_URL, self::SESSION_URL );

		ob_start();
		$this->gateway( array( 'checkout_display' => 'overlay' ) )->receipt_page( 1234 );
		$html = (string) ob_get_clean();

		$this->assertSame( 'https://checkout.shoppex.io/embed/embed.iife.js', Test_State::$scripts[0]['src'] );
		$config = Test_State::$inline_scripts[0]['data'];
		$this->assertStringContainsString( '"invoiceId":"' . self::UNIQID . '"', $config );
		$this->assertSame( self::SESSION_URL, json_decode( substr( $config, strlen( 'window.shoppexPayOverlay = ' ), -1 ), true )['paymentUrl'] );
		$this->assertStringContainsString( 'href="' . self::SESSION_URL . '"', $html );
		// Without invoice mode the buyer is sent on, with no error shown.
		$this->assertStringContainsString( 'data-shoppex-pay-redirecting hidden role="status">Redirecting you to the secure payment page…', $html );
		$this->assertStringNotContainsString( 'role="alert"', $html );
		$this->assertStringNotContainsString( 'could not', $html );
	}

	public function test_the_order_payment_page_opens_nothing_for_a_paid_order() {
		$this->order->update_meta_data( WC_Gateway_Shoppex::META_UNIQID, self::UNIQID );
		$this->order->update_meta_data( WC_Gateway_Shoppex::META_SESSION_URL, self::SESSION_URL );
		$this->order->update_status( 'processing' );

		ob_start();
		$this->gateway( array( 'checkout_display' => 'overlay' ) )->receipt_page( 1234 );
		$this->assertSame( '', ob_get_clean() );
		$this->assertSame( array(), Test_State::$scripts );
	}
}

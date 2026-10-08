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

	public function test_a_changed_cart_on_the_reused_order_gets_a_new_idempotency_key() {
		// Shoppex answers a reused key with a different body with 422, which left
		// the buyer unable to pay for 24 hours.
		$gateway = $this->gateway();
		$this->queue_session();
		$gateway->process_payment( 1234 );
		$first = $this->last_request()['headers']['Idempotency-Key'];

		$this->queue_session();
		$gateway->process_payment( 1234 );
		$this->assertSame( $first, $this->last_request()['headers']['Idempotency-Key'] );

		$this->order->total = '79.99';
		$this->queue_session();
		$gateway->process_payment( 1234 );
		$this->assertNotSame( $first, $this->last_request()['headers']['Idempotency-Key'] );
	}

	public function test_two_refunds_of_the_same_amount_use_two_keys_and_a_retry_reuses_its_key() {
		$gateway = $this->gateway();
		$this->order->update_meta_data( WC_Gateway_Shoppex::META_UNIQID, self::UNIQID );
		$refunded = array( 'status' => 200, 'body' => array( 'data' => array( 'message' => 'Order refunded successfully.' ) ) );

		Test_State::$refunds[1234] = array( 501 );
		Test_State::$responses[]   = $refunded;
		$this->assertTrue( $gateway->process_refund( 1234, '10.00', '' ) );
		$first = $this->last_request()['headers']['Idempotency-Key'];
		$this->assertSame( 'https://api.shoppex.io/dev/v1/orders/' . self::UNIQID . '/refund', $this->last_request()['url'] );

		// A second, separate refund of the same amount.
		Test_State::$refunds[1234] = array( 502, 501 );
		Test_State::$responses[]   = new WP_Error( 'http_request_failed', 'timeout' );
		$this->assertInstanceOf( WP_Error::class, $gateway->process_refund( 1234, '10.00', '' ) );
		$second = $this->last_request()['headers']['Idempotency-Key'];
		$this->assertNotSame( $first, $second );

		// WooCommerce deleted the failed refund; the merchant retries it.
		Test_State::$refunds[1234] = array( 503, 501 );
		Test_State::$responses[]   = $refunded;
		$this->assertTrue( $gateway->process_refund( 1234, '10.00', '' ) );
		$this->assertSame( $second, $this->last_request()['headers']['Idempotency-Key'] );
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
		$this->assertStringContainsString( 'href="' . self::SESSION_URL . '"', $html );
		$this->assertStringContainsString( 'data-shoppex-pay-error hidden', $html );
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

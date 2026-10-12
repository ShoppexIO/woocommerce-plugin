<?php
/**
 * Order transitions from verified webhook payloads. The fixture mirrors the
 * producer: WebhookDeliveryService.composeOrderPayload, with `custom_fields`
 * as the object the Dev API stores for checkout session metadata
 * (`{customFields: [{name, value}]}`, create.routes.ts).
 *
 * @package Shoppex_Pay
 */

use PHPUnit\Framework\TestCase;

final class WebhookProcessTest extends TestCase {

	private const UNIQID = '3f0e8a52-6c1d-4b7e-9a35-2d4c8f1b6e70';

	/** @var WC_Order */
	private $order;

	protected function setUp(): void {
		Test_State::reset();
		$this->order = new WC_Order( 1234, '49.99', 'EUR' );
		$this->order->update_meta_data( WC_Gateway_Shoppex::META_UNIQID, self::UNIQID );
		Test_State::$orders[1234] = $this->order;
		unset( $_SERVER['HTTP_X_SHOPPEX_EVENT'] );
	}

	/**
	 * One delivery attempt. Shoppex gives every new delivery its own id.
	 */
	private function deliver( array $payload, $delivery_id = null ) {
		static $sequence = 0;
		return Shoppex_Webhook_Handler::process( $payload, null === $delivery_id ? 'dlv_' . ++$sequence : $delivery_id );
	}

	private function payload( $event = 'order:paid', array $data = array() ) {
		$payload          = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/order-paid.json' ), true );
		$payload['event'] = $event;
		$payload['data']  = array_merge( $payload['data'], $data );
		return $payload;
	}

	public function test_completes_the_order_from_the_producer_payload() {
		$this->assertSame( 'applied', $this->deliver( $this->payload() ) );
		$this->assertSame( 'processing', $this->order->get_status() );
		$this->assertSame( self::UNIQID, $this->order->get_transaction_id() );
	}

	public function test_a_retried_paid_delivery_completes_the_order_once() {
		$this->deliver( $this->payload() );
		$this->order->update_status( 'completed' );

		$this->assertSame( 'ignored', $this->deliver( $this->payload() ) );
		$this->assertSame( 1, $this->order->payment_completions );
		$this->assertSame( 'completed', $this->order->get_status() );
	}

	public function test_a_disputed_order_is_not_completed_again_by_a_paid_replay() {
		$this->deliver( $this->payload() );
		$this->deliver( $this->payload( 'order:disputed', array( 'status' => 'DISPUTED' ) ) );

		$this->deliver( $this->payload() );

		$this->assertSame( 'on-hold', $this->order->get_status() );
		$this->assertSame( 1, $this->order->payment_completions );
	}

	public function test_refuses_a_payment_below_the_order_total() {
		$this->assertSame( 'applied', $this->deliver( $this->payload( 'order:paid', array( 'total_display' => 9.99 ) ) ) );
		$this->assertSame( 'on-hold', $this->order->get_status() );
		$this->assertSame( 0, $this->order->payment_completions );
		$this->assertStringContainsString( '9.99 EUR', end( $this->order->notes ) );
	}

	public function test_refuses_a_payment_in_another_currency() {
		$this->deliver( $this->payload( 'order:paid', array( 'currency' => 'USD' ) ) );
		$this->assertSame( 'on-hold', $this->order->get_status() );
		$this->assertSame( 0, $this->order->payment_completions );
	}

	public function test_accepts_a_total_raised_by_buyer_paid_gateway_fees() {
		$this->deliver( $this->payload( 'order:paid', array( 'total_display' => 51.49 ) ) );
		$this->assertSame( 'processing', $this->order->get_status() );
	}

	public function test_paid_event_for_a_non_completed_invoice_does_not_complete() {
		$this->deliver( $this->payload( 'order:paid', array( 'status' => 'PENDING' ) ) );
		$this->assertSame( 'pending', $this->order->get_status() );
	}

	public function test_the_event_comes_from_the_signed_body_not_the_unsigned_header() {
		// A captured order:created body replayed with a forged event header.
		$_SERVER['HTTP_X_SHOPPEX_EVENT'] = 'order:paid';
		$this->deliver( $this->payload( 'order:created', array( 'status' => 'COMPLETED' ) ) );
		$this->assertSame( 'pending', $this->order->get_status() );
		$this->assertSame( 0, $this->order->payment_completions );
	}

	public function test_a_payment_through_an_older_invoice_completes_and_retargets_refunds() {
		$older = '9b1d2c3e-4f50-4a6b-8c7d-0e1f2a3b4c5d';
		$this->deliver( $this->payload( 'order:paid', array( 'uniqid' => $older ) ) );
		$this->assertSame( 'processing', $this->order->get_status() );
		$this->assertSame( $older, $this->order->get_meta( WC_Gateway_Shoppex::META_UNIQID ) );
	}

	public function test_an_older_invoice_expiring_does_not_cancel_the_order_paid_through_a_newer_one() {
		$this->deliver( $this->payload( 'order:cancelled', array( 'uniqid' => '9b1d2c3e-4f50-4a6b-8c7d-0e1f2a3b4c5d', 'status' => 'VOIDED' ) ) );
		$this->assertSame( 'pending', $this->order->get_status() );
	}

	public function test_cancels_the_order_when_its_current_invoice_expires() {
		$this->deliver( $this->payload( 'order:cancelled', array( 'status' => 'VOIDED' ) ) );
		$this->assertSame( 'cancelled', $this->order->get_status() );
	}

	public function test_a_late_cancel_does_not_reopen_a_paid_order() {
		$this->deliver( $this->payload() );
		$this->deliver( $this->payload( 'order:cancelled', array( 'status' => 'VOIDED' ) ) );
		$this->assertSame( 'processing', $this->order->get_status() );
	}

	public function test_a_late_cancel_does_not_reopen_a_processing_order_when_a_filter_replaced_the_paid_statuses() {
		$this->deliver( $this->payload() );
		Test_State::$paid_statuses = array( 'shipped' );
		$this->deliver( $this->payload( 'order:cancelled', array( 'status' => 'VOIDED' ) ) );
		$this->assertSame( 'processing', $this->order->get_status() );
	}

	public function test_a_late_payment_on_a_refunded_order_keeps_refunds_on_the_original_invoice() {
		$this->order->update_status( 'refunded' );
		$second = '9b1d2c3e-4f50-4a6b-8c7d-0e1f2a3b4c5d';

		$this->assertSame( 'ignored', $this->deliver( $this->payload( 'order:paid', array( 'uniqid' => $second ) ) ) );

		$this->assertSame( self::UNIQID, $this->order->get_meta( WC_Gateway_Shoppex::META_UNIQID ) );
		$this->assertStringContainsString( 'Refund the second payment', end( $this->order->notes ) );
	}

	public function test_a_dispute_on_a_paid_order_puts_it_on_hold() {
		// Disputes only exist for paid orders; the paid-order guard used to drop them.
		$this->deliver( $this->payload() );
		$this->assertSame( 'applied', $this->deliver( $this->payload( 'order:disputed', array( 'status' => 'DISPUTED' ) ) ) );
		$this->assertSame( 'on-hold', $this->order->get_status() );
	}

	public function test_a_dashboard_retry_of_a_dispute_does_not_hold_an_order_the_merchant_released() {
		// A retry of a failed delivery from the Shoppex dashboard carries a new
		// delivery id; only the frozen payload stays the same.
		$this->deliver( $this->payload() );
		$dispute = $this->payload( 'order:disputed', array( 'status' => 'DISPUTED' ) );
		$this->deliver( $dispute );
		$this->order->update_status( 'completed' );

		$this->assertSame( 'ignored', $this->deliver( $dispute ) );
		$this->assertSame( 'completed', $this->order->get_status() );
	}

	public function test_a_retried_delivery_with_a_rebuilt_payload_does_not_hold_a_released_order() {
		// The dispute was applied but the answer timed out; the merchant released
		// the order. Shoppex retries under the same delivery id, and a payload it
		// rebuilds carries a fresh created_at: the old payload key missed it.
		$this->deliver( $this->payload() );
		$dispute = $this->payload( 'order:disputed', array( 'status' => 'DISPUTED' ) );
		$this->deliver( $dispute, 'dlv_dispute' );
		$this->order->update_status( 'completed' );

		$dispute['created_at'] += 3600;
		$this->assertSame( 'ignored', $this->deliver( $dispute, 'dlv_dispute' ) );
		$this->assertSame( 'completed', $this->order->get_status() );
	}

	public function test_a_later_dispute_holds_a_released_order_again() {
		$this->deliver( $this->payload() );
		$dispute = $this->payload( 'order:disputed', array( 'status' => 'DISPUTED' ) );
		$this->deliver( $dispute );
		$this->order->update_status( 'completed' );

		$dispute['created_at'] += 86400;
		$this->assertSame( 'applied', $this->deliver( $dispute ) );
		$this->assertSame( 'on-hold', $this->order->get_status() );
	}

	public function test_a_dispute_leaves_an_unpaid_order_alone() {
		// A dispute applies to a payment; a pending order has none to hold.
		$this->assertSame( 'ignored', $this->deliver( $this->payload( 'order:disputed', array( 'status' => 'DISPUTED' ) ) ) );
		$this->assertSame( 'pending', $this->order->get_status() );
	}

	public function test_a_refund_after_a_short_payment_hold_goes_to_the_invoice_that_was_paid() {
		// The buyer paid too little through an older checkout session of the
		// order. The merchant released the order and refunds it: the refund
		// went to the newest session's invoice, which took no money.
		$older = '9b1d2c3e-4f50-4a6b-8c7d-0e1f2a3b4c5d';
		$this->deliver( $this->payload( 'order:paid', array( 'uniqid' => $older, 'total_display' => 9.99 ) ) );
		$this->assertSame( 'on-hold', $this->order->get_status() );
		$this->order->update_status( 'processing' );

		$this->assertSame( '/dev/v1/orders/' . $older . '/refund', $this->refund( '9.99' ) );
	}

	public function test_a_dispute_on_the_invoice_that_took_a_short_payment_holds_the_released_order() {
		// The money came through an older checkout session than the order's
		// newest one. Its dispute only left a note, as if it were a stale invoice.
		$older = '9b1d2c3e-4f50-4a6b-8c7d-0e1f2a3b4c5d';
		$this->deliver( $this->payload( 'order:paid', array( 'uniqid' => $older, 'total_display' => 9.99 ) ) );
		$this->order->update_status( 'processing' );

		$this->assertSame( 'applied', $this->deliver( $this->payload( 'order:disputed', array( 'uniqid' => $older, 'status' => 'DISPUTED' ) ) ) );
		$this->assertSame( 'on-hold', $this->order->get_status() );
	}

	public function test_a_full_payment_after_a_short_one_takes_the_refunds() {
		$older = '9b1d2c3e-4f50-4a6b-8c7d-0e1f2a3b4c5d';
		$this->deliver( $this->payload( 'order:paid', array( 'uniqid' => $older, 'total_display' => 9.99 ) ) );
		$this->deliver( $this->payload() );
		$this->assertSame( 'processing', $this->order->get_status() );

		$this->assertSame( '/dev/v1/orders/' . self::UNIQID . '/refund', $this->refund( '49.99' ) );
	}

	/**
	 * Refund through the gateway and return the Shoppex path it called.
	 */
	private function refund( $amount ) {
		Test_State::$options['woocommerce_shoppex_settings'] = array( 'api_key' => 'shx_test' );
		WC_Gateway_Shoppex::remember_refund( new WC_Order_Refund( 501, 1234 ) );
		Test_State::$responses[] = array( 'status' => 200, 'body' => array( 'data' => array( 'message' => 'ok' ) ) );
		$this->assertTrue( ( new WC_Gateway_Shoppex() )->process_refund( 1234, $amount, '' ) );
		return (string) parse_url( end( Test_State::$requests )['url'], PHP_URL_PATH );
	}

	public function test_a_foreign_order_key_is_not_matched() {
		$payload                                        = $this->payload();
		$payload['data']['custom_fields']['customFields'][1]['value'] = 'wc_order_key_from_staging';
		$this->assertSame( 'unmatched', $this->deliver( $payload ) );
		$this->assertSame( 'pending', $this->order->get_status() );
	}

	public function test_an_invoice_without_plugin_metadata_is_not_matched() {
		$this->assertSame( 'unmatched', $this->deliver( $this->payload( 'order:paid', array( 'custom_fields' => null ) ) ) );
	}
}

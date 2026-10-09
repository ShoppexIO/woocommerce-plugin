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

	private function payload( $event = 'order:paid', array $data = array() ) {
		$payload          = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/order-paid.json' ), true );
		$payload['event'] = $event;
		$payload['data']  = array_merge( $payload['data'], $data );
		return $payload;
	}

	public function test_completes_the_order_from_the_producer_payload() {
		$this->assertSame( 'applied', Shoppex_Webhook_Handler::process( $this->payload() ) );
		$this->assertSame( 'processing', $this->order->get_status() );
		$this->assertSame( self::UNIQID, $this->order->get_transaction_id() );
	}

	public function test_a_retried_paid_delivery_completes_the_order_once() {
		Shoppex_Webhook_Handler::process( $this->payload() );
		$this->order->update_status( 'completed' );

		$this->assertSame( 'ignored', Shoppex_Webhook_Handler::process( $this->payload() ) );
		$this->assertSame( 1, $this->order->payment_completions );
		$this->assertSame( 'completed', $this->order->get_status() );
	}

	public function test_a_disputed_order_is_not_completed_again_by_a_paid_replay() {
		Shoppex_Webhook_Handler::process( $this->payload() );
		Shoppex_Webhook_Handler::process( $this->payload( 'order:disputed', array( 'status' => 'DISPUTED' ) ) );

		Shoppex_Webhook_Handler::process( $this->payload() );

		$this->assertSame( 'on-hold', $this->order->get_status() );
		$this->assertSame( 1, $this->order->payment_completions );
	}

	public function test_refuses_a_payment_below_the_order_total() {
		$this->assertSame( 'applied', Shoppex_Webhook_Handler::process( $this->payload( 'order:paid', array( 'total_display' => 9.99 ) ) ) );
		$this->assertSame( 'on-hold', $this->order->get_status() );
		$this->assertSame( 0, $this->order->payment_completions );
		$this->assertStringContainsString( '9.99 EUR', end( $this->order->notes ) );
	}

	public function test_refuses_a_payment_in_another_currency() {
		Shoppex_Webhook_Handler::process( $this->payload( 'order:paid', array( 'currency' => 'USD' ) ) );
		$this->assertSame( 'on-hold', $this->order->get_status() );
		$this->assertSame( 0, $this->order->payment_completions );
	}

	public function test_accepts_a_total_raised_by_buyer_paid_gateway_fees() {
		Shoppex_Webhook_Handler::process( $this->payload( 'order:paid', array( 'total_display' => 51.49 ) ) );
		$this->assertSame( 'processing', $this->order->get_status() );
	}

	public function test_paid_event_for_a_non_completed_invoice_does_not_complete() {
		Shoppex_Webhook_Handler::process( $this->payload( 'order:paid', array( 'status' => 'PENDING' ) ) );
		$this->assertSame( 'pending', $this->order->get_status() );
	}

	public function test_the_event_comes_from_the_signed_body_not_the_unsigned_header() {
		// A captured order:created body replayed with a forged event header.
		$_SERVER['HTTP_X_SHOPPEX_EVENT'] = 'order:paid';
		Shoppex_Webhook_Handler::process( $this->payload( 'order:created', array( 'status' => 'COMPLETED' ) ) );
		$this->assertSame( 'pending', $this->order->get_status() );
		$this->assertSame( 0, $this->order->payment_completions );
	}

	public function test_a_payment_through_an_older_invoice_completes_and_retargets_refunds() {
		$older = '9b1d2c3e-4f50-4a6b-8c7d-0e1f2a3b4c5d';
		Shoppex_Webhook_Handler::process( $this->payload( 'order:paid', array( 'uniqid' => $older ) ) );
		$this->assertSame( 'processing', $this->order->get_status() );
		$this->assertSame( $older, $this->order->get_meta( WC_Gateway_Shoppex::META_UNIQID ) );
	}

	public function test_an_older_invoice_expiring_does_not_cancel_the_order_paid_through_a_newer_one() {
		Shoppex_Webhook_Handler::process( $this->payload( 'order:cancelled', array( 'uniqid' => '9b1d2c3e-4f50-4a6b-8c7d-0e1f2a3b4c5d', 'status' => 'VOIDED' ) ) );
		$this->assertSame( 'pending', $this->order->get_status() );
	}

	public function test_cancels_the_order_when_its_current_invoice_expires() {
		Shoppex_Webhook_Handler::process( $this->payload( 'order:cancelled', array( 'status' => 'VOIDED' ) ) );
		$this->assertSame( 'cancelled', $this->order->get_status() );
	}

	public function test_a_late_cancel_does_not_reopen_a_paid_order() {
		Shoppex_Webhook_Handler::process( $this->payload() );
		Shoppex_Webhook_Handler::process( $this->payload( 'order:cancelled', array( 'status' => 'VOIDED' ) ) );
		$this->assertSame( 'processing', $this->order->get_status() );
	}

	public function test_a_late_cancel_does_not_reopen_a_processing_order_when_a_filter_replaced_the_paid_statuses() {
		Shoppex_Webhook_Handler::process( $this->payload() );
		Test_State::$paid_statuses = array( 'shipped' );
		Shoppex_Webhook_Handler::process( $this->payload( 'order:cancelled', array( 'status' => 'VOIDED' ) ) );
		$this->assertSame( 'processing', $this->order->get_status() );
	}

	public function test_a_late_payment_on_a_refunded_order_keeps_refunds_on_the_original_invoice() {
		$this->order->update_status( 'refunded' );
		$second = '9b1d2c3e-4f50-4a6b-8c7d-0e1f2a3b4c5d';

		$this->assertSame( 'ignored', Shoppex_Webhook_Handler::process( $this->payload( 'order:paid', array( 'uniqid' => $second ) ) ) );

		$this->assertSame( self::UNIQID, $this->order->get_meta( WC_Gateway_Shoppex::META_UNIQID ) );
		$this->assertStringContainsString( 'Refund the second payment', end( $this->order->notes ) );
	}

	public function test_a_dispute_on_a_paid_order_puts_it_on_hold() {
		// Disputes only exist for paid orders; the paid-order guard used to drop them.
		Shoppex_Webhook_Handler::process( $this->payload() );
		$this->assertSame( 'applied', Shoppex_Webhook_Handler::process( $this->payload( 'order:disputed', array( 'status' => 'DISPUTED' ) ) ) );
		$this->assertSame( 'on-hold', $this->order->get_status() );
	}

	public function test_a_retried_dispute_does_not_hold_an_order_the_merchant_released() {
		// Shoppex retries and manual resends carry a new delivery id and a fresh
		// signature timestamp; only the frozen payload stays the same.
		Shoppex_Webhook_Handler::process( $this->payload() );
		$dispute = $this->payload( 'order:disputed', array( 'status' => 'DISPUTED' ) );
		Shoppex_Webhook_Handler::process( $dispute );
		$this->order->update_status( 'completed' );

		$this->assertSame( 'ignored', Shoppex_Webhook_Handler::process( $dispute ) );
		$this->assertSame( 'completed', $this->order->get_status() );
	}

	public function test_a_later_dispute_holds_a_released_order_again() {
		Shoppex_Webhook_Handler::process( $this->payload() );
		$dispute = $this->payload( 'order:disputed', array( 'status' => 'DISPUTED' ) );
		Shoppex_Webhook_Handler::process( $dispute );
		$this->order->update_status( 'completed' );

		$dispute['created_at'] += 86400;
		$this->assertSame( 'applied', Shoppex_Webhook_Handler::process( $dispute ) );
		$this->assertSame( 'on-hold', $this->order->get_status() );
	}

	public function test_a_dispute_leaves_an_unpaid_order_alone() {
		// A dispute applies to a payment; a pending order has none to hold.
		$this->assertSame( 'ignored', Shoppex_Webhook_Handler::process( $this->payload( 'order:disputed', array( 'status' => 'DISPUTED' ) ) ) );
		$this->assertSame( 'pending', $this->order->get_status() );
	}

	public function test_a_foreign_order_key_is_not_matched() {
		$payload                                        = $this->payload();
		$payload['data']['custom_fields']['customFields'][1]['value'] = 'wc_order_key_from_staging';
		$this->assertSame( 'unmatched', Shoppex_Webhook_Handler::process( $payload ) );
		$this->assertSame( 'pending', $this->order->get_status() );
	}

	public function test_an_invoice_without_plugin_metadata_is_not_matched() {
		$this->assertSame( 'unmatched', Shoppex_Webhook_Handler::process( $this->payload( 'order:paid', array( 'custom_fields' => null ) ) ) );
	}
}

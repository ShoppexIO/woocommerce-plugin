<?php
/**
 * Webhook signature verification against the scheme the Shoppex backend sends
 * (WebhookDeliveryService.sendWebhook: X-Shoppex-Signature-V2 =
 * `v1,t=<ts>,h=<hmac-sha256(deliveryId.ts.body)>`).
 *
 * @package Shoppex_Pay
 */

use PHPUnit\Framework\TestCase;

final class WebhookSignatureTest extends TestCase {

	private const SECRET   = 'whsec_test_secret';
	private const DELIVERY = 'dlv_01HZX';
	private const NOW      = 1791540120;

	private function body() {
		return (string) file_get_contents( __DIR__ . '/fixtures/order-paid.json' );
	}

	private function header( $body, $timestamp, $secret = self::SECRET, $delivery = self::DELIVERY ) {
		return 'v1,t=' . $timestamp . ',h=' . hash_hmac( 'sha256', $delivery . '.' . $timestamp . '.' . $body, $secret );
	}

	public function test_accepts_the_v2_signature_shoppex_sends() {
		$body = $this->body();
		$this->assertTrue(
			Shoppex_Webhook_Handler::verify_signature( $body, $this->header( $body, self::NOW ), self::DELIVERY, (string) self::NOW, self::SECRET, self::NOW )
		);
	}

	public function test_rejects_a_replay_older_than_the_tolerance() {
		// The legacy SHA-512 body signature had no timestamp: a captured delivery
		// verified forever.
		$body = $this->body();
		$old  = self::NOW - Shoppex_Webhook_Handler::SIGNATURE_TOLERANCE - 1;
		$this->assertFalse(
			Shoppex_Webhook_Handler::verify_signature( $body, $this->header( $body, $old ), self::DELIVERY, (string) $old, self::SECRET, self::NOW )
		);
	}

	public function test_rejects_a_timestamp_header_that_differs_from_the_signed_one() {
		$body = $this->body();
		$this->assertFalse(
			Shoppex_Webhook_Handler::verify_signature( $body, $this->header( $body, self::NOW ), self::DELIVERY, (string) ( self::NOW + 1 ), self::SECRET, self::NOW )
		);
	}

	public function test_rejects_a_tampered_body_another_delivery_id_or_another_secret() {
		$body   = $this->body();
		$header = $this->header( $body, self::NOW );
		$this->assertFalse( Shoppex_Webhook_Handler::verify_signature( $body . ' ', $header, self::DELIVERY, (string) self::NOW, self::SECRET, self::NOW ) );
		$this->assertFalse( Shoppex_Webhook_Handler::verify_signature( $body, $header, 'dlv_other', (string) self::NOW, self::SECRET, self::NOW ) );
		$this->assertFalse( Shoppex_Webhook_Handler::verify_signature( $body, $header, self::DELIVERY, (string) self::NOW, self::SECRET . 'x', self::NOW ) );
	}

	public function test_rejects_the_legacy_sha512_signature_and_missing_parts() {
		$body = $this->body();
		$this->assertFalse( Shoppex_Webhook_Handler::verify_signature( $body, hash_hmac( 'sha512', $body, self::SECRET ), self::DELIVERY, (string) self::NOW, self::SECRET, self::NOW ) );
		$this->assertFalse( Shoppex_Webhook_Handler::verify_signature( $body, '', self::DELIVERY, (string) self::NOW, self::SECRET, self::NOW ) );
		$this->assertFalse( Shoppex_Webhook_Handler::verify_signature( $body, $this->header( $body, self::NOW, self::SECRET, '' ), '', (string) self::NOW, self::SECRET, self::NOW ) );
		$this->assertFalse( Shoppex_Webhook_Handler::verify_signature( $body, $this->header( $body, self::NOW, '' ), self::DELIVERY, (string) self::NOW, '', self::NOW ) );
	}
}

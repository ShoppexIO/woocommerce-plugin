<?php
/**
 * The overlay script on the order payment page, run under Node against the
 * embed SDK as it exists before and after invoice mode
 * (apps/checkout/src/embed/index.ts: `Shoppex.supports.invoiceId`).
 *
 * @package Shoppex_Pay
 */

use PHPUnit\Framework\TestCase;

final class OverlayScriptTest extends TestCase {

	private const PAYMENT_URL = 'https://checkout.shoppex.io/invoice/3f0e8a52-6c1d-4b7e-9a35-2d4c8f1b6e70';

	private function page( $embed ) {
		$command = sprintf(
			'node %s %s %s 2>&1',
			escapeshellarg( __DIR__ . '/overlay-harness.cjs' ),
			escapeshellarg( dirname( __DIR__, 2 ) . '/shoppex-pay/assets/js/overlay.js' ),
			escapeshellarg( $embed )
		);
		exec( $command, $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		return json_decode( implode( "\n", $output ), true );
	}

	public function test_an_embed_without_invoice_mode_sends_the_buyer_to_the_payment_page() {
		// That SDK ignores invoiceId and opens nothing. The page showed every
		// buyer an error and left them to find the link.
		$page = $this->page( 'without-invoice-mode' );

		$this->assertSame( array( self::PAYMENT_URL ), $page['replaced'] );
		$this->assertTrue( $page['redirecting_shown'] );
		$this->assertFalse( $page['pay_button_shown'] );
		$this->assertSame( array(), $page['opened'] );
	}

	public function test_a_missing_embed_sends_the_buyer_to_the_payment_page() {
		$page = $this->page( 'missing' );

		$this->assertSame( array( self::PAYMENT_URL ), $page['replaced'] );
		$this->assertTrue( $page['redirecting_shown'] );
		$this->assertFalse( $page['pay_button_shown'] );
	}

	public function test_an_embed_with_invoice_mode_opens_the_invoice() {
		$page = $this->page( 'with-invoice-mode' );

		$this->assertSame( array(), $page['replaced'] );
		$this->assertFalse( $page['redirecting_shown'] );
		$this->assertTrue( $page['pay_button_shown'] );
		$this->assertSame( array( array( 'invoiceId' => '3f0e8a52-6c1d-4b7e-9a35-2d4c8f1b6e70' ) ), $page['opened'] );
	}
}

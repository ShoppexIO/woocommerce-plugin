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

	public function test_an_embed_without_invoice_mode_shows_the_payment_page_link_instead_of_a_dead_button() {
		// That SDK ignores invoiceId and opens nothing; the Pay button did nothing either.
		$page = $this->page( 'without-invoice-mode' );

		$this->assertTrue( $page['error_shown'] );
		$this->assertFalse( $page['pay_button_shown'] );
		$this->assertSame( array(), $page['opened'] );
	}

	public function test_a_missing_embed_shows_the_payment_page_link() {
		$page = $this->page( 'missing' );

		$this->assertTrue( $page['error_shown'] );
		$this->assertFalse( $page['pay_button_shown'] );
	}

	public function test_an_embed_with_invoice_mode_opens_the_invoice() {
		$page = $this->page( 'with-invoice-mode' );

		$this->assertFalse( $page['error_shown'] );
		$this->assertTrue( $page['pay_button_shown'] );
		$this->assertSame( array( array( 'invoiceId' => '3f0e8a52-6c1d-4b7e-9a35-2d4c8f1b6e70' ) ), $page['opened'] );
	}
}

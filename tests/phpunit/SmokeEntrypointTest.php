<?php
/**
 * `npm run test:smoke` is the entry point existing validation calls; the
 * standalone smoke file it ran became the PHPUnit webhook suites.
 *
 * @package Shoppex_Pay
 */

use PHPUnit\Framework\TestCase;

final class SmokeEntrypointTest extends TestCase {

	public function test_the_smoke_entrypoint_runs_the_webhook_suites() {
		$root    = dirname( __DIR__, 2 );
		$package = json_decode( (string) file_get_contents( $root . '/package.json' ), true );
		// Removing the script broke `npm run test:smoke` with a missing-script error.
		$this->assertArrayHasKey( 'test:smoke', $package['scripts'] );

		exec( 'cd ' . escapeshellarg( $root ) . ' && ' . $package['scripts']['test:smoke'] . ' 2>&1', $output, $status );
		$output = implode( "\n", $output );

		$this->assertSame( 0, $status, $output );
		$this->assertMatchesRegularExpression( '/OK \(\d+ tests/', $output );
		$this->assertStringNotContainsString( 'No tests executed', $output );
	}
}

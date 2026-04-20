<?php
/**
 * Smoke test: HMAC-SHA512 webhook signature verification.
 *
 * Runs standalone (no WP needed), uses the exact algorithm the plugin
 * ships with.
 *
 * Usage:  php tests/smoke/webhook-signature-smoke.php
 */

declare( strict_types=1 );

$failures = 0;

function assert_true( string $name, bool $cond, string $detail = '' ): void {
	global $failures;
	if ( $cond ) {
		echo "  [PASS] {$name}\n";
	} else {
		echo "  [FAIL] {$name}" . ( '' !== $detail ? ": {$detail}" : '' ) . "\n";
		++$failures;
	}
}

/**
 * Mirror of Shoppex_Webhook_Handler::verify_signature().
 */
function verify_signature( string $body, string $signature, string $secret ): bool {
	if ( '' === $signature || '' === $secret ) {
		return false;
	}
	return hash_equals( hash_hmac( 'sha512', $body, $secret ), $signature );
}

echo "=== Webhook signature smoke test ===\n\n";

$secret = 'whsec_smoketest_' . bin2hex( random_bytes( 16 ) );
$body   = json_encode( array(
	'event' => 'order:paid',
	'data'  => array(
		'uniqid'        => 'ord_abc123',
		'status'        => 'COMPLETED',
		'gateway'       => 'STRIPE',
		'total'         => 19.99,
		'currency'      => 'USD',
		'customer_email' => 'buyer@example.com',
		'custom_fields' => array(
			array( 'name' => 'wc_order_id', 'value' => '1234' ),
			array( 'name' => 'wc_order_key', 'value' => 'wc_xyz' ),
			array( 'name' => 'source', 'value' => 'woocommerce' ),
		),
	),
) );

$valid_sig = hash_hmac( 'sha512', $body, $secret );

// Happy path.
assert_true( 'Valid signature accepted', verify_signature( $body, $valid_sig, $secret ) );

// Tampered body.
assert_true( 'Tampered body rejected', ! verify_signature( $body . 'x', $valid_sig, $secret ) );

// Wrong secret.
assert_true( 'Wrong secret rejected', ! verify_signature( $body, $valid_sig, $secret . 'x' ) );

// Empty signature.
assert_true( 'Empty signature rejected', ! verify_signature( $body, '', $secret ) );

// Empty secret (misconfigured plugin).
assert_true( 'Empty secret rejected', ! verify_signature( $body, $valid_sig, '' ) );

// Length mismatch (defensive — hash_equals handles this, but make sure).
assert_true( 'Short signature rejected', ! verify_signature( $body, 'abc', $secret ) );

// Timing: confirm hash_equals-style check doesn't depend on ASCII shortcuts.
$almost = substr( $valid_sig, 0, -1 ) . ( 'a' === substr( $valid_sig, -1 ) ? 'b' : 'a' );
assert_true( 'One-char-off signature rejected', ! verify_signature( $body, $almost, $secret ) );

echo "\n";

// --- custom_fields normalization ------------------------------------------
echo "Test: custom_fields normalization (both shapes)\n";

/**
 * Mirror of Shoppex_Webhook_Handler::custom_fields().
 */
function custom_fields_normalize( array $data ): array {
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

$array_shape = array(
	'custom_fields' => array(
		array( 'name' => 'wc_order_id', 'value' => '1234' ),
		array( 'name' => 'source', 'value' => 'woocommerce' ),
	),
);
$cf1 = custom_fields_normalize( $array_shape );
assert_true( 'Array-of-pairs shape: wc_order_id=1234', ( $cf1['wc_order_id'] ?? '' ) === '1234' );
assert_true( 'Array-of-pairs shape: source=woocommerce', ( $cf1['source'] ?? '' ) === 'woocommerce' );

$assoc_shape = array( 'custom_fields' => array( 'wc_order_id' => '5678', 'source' => 'woocommerce' ) );
$cf2         = custom_fields_normalize( $assoc_shape );
assert_true( 'Assoc shape: wc_order_id=5678', ( $cf2['wc_order_id'] ?? '' ) === '5678' );

$json_string_shape = array(
	'custom_fields' => json_encode( array( array( 'name' => 'wc_order_id', 'value' => '9999' ) ) ),
);
$cf3 = custom_fields_normalize( $json_string_shape );
assert_true( 'JSON-string shape: wc_order_id=9999', ( $cf3['wc_order_id'] ?? '' ) === '9999' );

$empty_shape = array( 'custom_fields' => null );
$cf4         = custom_fields_normalize( $empty_shape );
assert_true( 'Null custom_fields → empty array', array() === $cf4 );

echo "\n";

if ( $failures > 0 ) {
	echo "FAILED: {$failures} assertion(s) failed.\n";
	exit( 1 );
}
echo "ALL TESTS PASSED\n";
exit( 0 );

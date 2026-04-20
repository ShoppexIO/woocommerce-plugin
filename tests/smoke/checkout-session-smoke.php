<?php
/**
 * Smoke test: POST /dev/v1/checkout/sessions
 *
 * Usage:
 *   SHOPPEX_API_KEY=shx_... php tests/smoke/checkout-session-smoke.php
 *
 * Exits non-zero on failure.
 *
 * This script reuses NO WordPress functions — it mimics what the plugin's
 * Shoppex_Api_Client does, so it can run standalone with plain PHP.
 */

declare( strict_types=1 );

$api_key  = getenv( 'SHOPPEX_API_KEY' ) ?: '';
$base_url = getenv( 'SHOPPEX_API_BASE' ) ?: 'https://api.shoppex.io';

if ( '' === $api_key ) {
	fwrite( STDERR, "Missing SHOPPEX_API_KEY env var.\n" );
	exit( 2 );
}

$failures = 0;

/**
 * Assert helper.
 */
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
 * Perform a JSON request and return [status, body-array|null].
 *
 * @return array{0:int,1:?array,2:string}
 */
function req( string $method, string $url, array $headers, ?array $body ): array {
	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_TIMEOUT        => 20,
			CURLOPT_POSTFIELDS     => null !== $body ? json_encode( $body ) : null,
		)
	);
	$raw    = curl_exec( $ch );
	$status = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	$err    = curl_error( $ch );
	unset( $ch );
	if ( false === $raw ) {
		return array( 0, null, $err );
	}
	$parsed = json_decode( (string) $raw, true );
	return array( $status, is_array( $parsed ) ? $parsed : null, (string) $raw );
}

echo "=== Shoppex checkout session smoke test ===\n";
echo "Base URL: {$base_url}\n";
echo "API key:  " . substr( $api_key, 0, 8 ) . "...\n\n";

// --- Test 1: Happy path ---------------------------------------------------
echo "Test 1: POST /dev/v1/checkout/sessions (happy path)\n";

$idempotency_key = 'woo_order_smoketest_' . bin2hex( random_bytes( 6 ) );
$payload         = array(
	'product'        => array( 'name' => 'Smoke test order', 'quantity' => 1 ),
	'amount'         => 19.99,
	'currency'       => 'USD',
	'customer_email' => 'smoketest@example.com',
	'success_url'    => 'https://example.com/order-received',
	'cancel_url'     => 'https://example.com/checkout',
	'metadata'       => array(
		'wc_order_id'  => '99999',
		'wc_order_key' => 'wc_order_smoketest',
		'source'       => 'woocommerce',
	),
	'white_label'    => false,
);

list( $status, $body, $raw ) = req(
	'POST',
	"{$base_url}/dev/v1/checkout/sessions",
	array(
		'Authorization: Bearer ' . $api_key,
		'Content-Type: application/json',
		'Accept: application/json',
		'Idempotency-Key: ' . $idempotency_key,
	),
	$payload
);

assert_true( 'HTTP 2xx', $status >= 200 && $status < 300, "got status {$status}: " . substr( $raw, 0, 300 ) );
assert_true( 'Response has `data` envelope', is_array( $body ) && isset( $body['data'] ) && is_array( $body['data'] ), 'body: ' . substr( $raw, 0, 200 ) );

$data = ( is_array( $body ) && isset( $body['data'] ) ) ? $body['data'] : array();

assert_true( 'data.id present (session uniqid)', ! empty( $data['id'] ), 'data: ' . json_encode( $data ) );
assert_true( 'data.url present (hosted checkout)', ! empty( $data['url'] ), 'data: ' . json_encode( $data ) );
assert_true( 'data.status present', ! empty( $data['status'] ), 'data: ' . json_encode( $data ) );
assert_true( 'data.amount matches request', isset( $data['amount'] ) && (float) $data['amount'] === 19.99, 'amount: ' . ( $data['amount'] ?? 'missing' ) );
assert_true( 'data.currency matches request', ( $data['currency'] ?? '' ) === 'USD', 'currency: ' . ( $data['currency'] ?? 'missing' ) );
assert_true( 'data.customer_email matches request', ( $data['customer_email'] ?? '' ) === 'smoketest@example.com', 'email: ' . ( $data['customer_email'] ?? 'missing' ) );

$first_id = $data['id'] ?? null;

echo "\n";

// --- Test 2: Idempotency --------------------------------------------------
echo "Test 2: Idempotency — same key returns same session\n";

list( $status2, $body2, $raw2 ) = req(
	'POST',
	"{$base_url}/dev/v1/checkout/sessions",
	array(
		'Authorization: Bearer ' . $api_key,
		'Content-Type: application/json',
		'Accept: application/json',
		'Idempotency-Key: ' . $idempotency_key,
	),
	$payload
);

assert_true( 'HTTP 2xx on replay', $status2 >= 200 && $status2 < 300, "got status {$status2}" );
$data2      = ( is_array( $body2 ) && isset( $body2['data'] ) ) ? $body2['data'] : array();
$second_id  = $data2['id'] ?? null;
assert_true( 'Replay returns same session id', null !== $first_id && $first_id === $second_id, "first={$first_id} second={$second_id}" );

echo "\n";

// --- Test 3: Auth rejection -----------------------------------------------
echo "Test 3: Wrong API key is rejected\n";

list( $status3, $body3, $raw3 ) = req(
	'POST',
	"{$base_url}/dev/v1/checkout/sessions",
	array(
		'Authorization: Bearer shx_0000000000000000000000000000000',
		'Content-Type: application/json',
		'Accept: application/json',
	),
	$payload
);

assert_true( 'Invalid key returns 4xx', $status3 >= 400 && $status3 < 500, "got status {$status3}" );

echo "\n";

// --- Test 4: Validation ---------------------------------------------------
echo "Test 4: Missing required fields rejected\n";

list( $status4, $body4, $raw4 ) = req(
	'POST',
	"{$base_url}/dev/v1/checkout/sessions",
	array(
		'Authorization: Bearer ' . $api_key,
		'Content-Type: application/json',
		'Accept: application/json',
	),
	array() // Empty body on purpose.
);

assert_true( 'Empty body returns 4xx', $status4 >= 400 && $status4 < 500, "got status {$status4}" );

echo "\n";

if ( $failures > 0 ) {
	echo "FAILED: {$failures} assertion(s) failed.\n";
	exit( 1 );
}
echo "ALL TESTS PASSED\n";
exit( 0 );

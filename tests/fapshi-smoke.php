<?php
/**
 * Standalone Fapshi connectivity check — verifies your credentials and the
 * initiate-pay flow WITHOUT WordPress. Self-contained (no plugin classes, no
 * WP shims), so it isolates "are my creds + network good?" from plugin bugs.
 *
 * Run (sandbox):
 *   docker run --rm -e FAPSHI_USER=xxx -e FAPSHI_KEY=yyy \
 *     -v "$PWD:/app" php:8.2-cli php /app/tests/fapshi-smoke.php
 *
 * On Windows PowerShell:
 *   docker run --rm -e FAPSHI_USER=$env:FAPSHI_USER -e FAPSHI_KEY=$env:FAPSHI_KEY `
 *     -v "${PWD}:/app" php:8.2-cli php /app/tests/fapshi-smoke.php
 *
 * Env vars:
 *   FAPSHI_USER   (required)  your service's apiuser
 *   FAPSHI_KEY    (required)  your service's apikey
 *   FAPSHI_ENV    (optional)  sandbox (default) | live
 *   FAPSHI_AMOUNT (optional)  amount in XAF, default 100
 */

$user = getenv( 'FAPSHI_USER' );
$key  = getenv( 'FAPSHI_KEY' );
$env  = getenv( 'FAPSHI_ENV' ) ?: 'sandbox';
$amt  = (int) ( getenv( 'FAPSHI_AMOUNT' ) ?: 100 );

if ( ! $user || ! $key ) {
	fwrite( STDERR, "ERROR: set FAPSHI_USER and FAPSHI_KEY env vars.\n" );
	exit( 2 );
}

$base = ( 'live' === $env ) ? 'https://live.fapshi.com' : 'https://sandbox.fapshi.com';
$ext  = 'smoke-' . date( 'YmdHis' );

echo "Environment : $env ($base)\n";
echo "Amount      : $amt XAF\n";
echo "externalId  : $ext\n\n";

/* 1) initiate-pay -------------------------------------------------------- */
echo "→ POST /initiate-pay\n";
list( $code, $body ) = fapshi_request(
	'POST',
	"$base/initiate-pay",
	$user,
	$key,
	array(
		'amount'     => $amt,
		'externalId' => $ext,
		'message'    => 'FormPay CM smoke test',
	)
);
echo "  HTTP $code\n";
echo '  ' . json_encode( $body ) . "\n";

if ( $code < 200 || $code >= 300 || empty( $body['transId'] ) ) {
	fwrite( STDERR, "\nFAILED: credentials or request rejected. Check apiuser/apikey and environment.\n" );
	exit( 1 );
}

$trans_id = $body['transId'];
echo "\n  ✓ Got payment link: " . ( $body['link'] ?? '(none)' ) . "\n";
echo "  ✓ transId: $trans_id\n";

/* 2) payment-status ------------------------------------------------------ */
echo "\n→ GET /payment-status/$trans_id\n";
list( $code, $body ) = fapshi_request( 'GET', "$base/payment-status/" . rawurlencode( $trans_id ), $user, $key );
echo "  HTTP $code\n";
echo '  ' . json_encode( $body ) . "\n";

if ( $code >= 200 && $code < 300 && isset( $body['status'] ) ) {
	echo "\n  ✓ Status lookup works. Current status: {$body['status']}\n";
	echo "\nALL GOOD — credentials valid, initiate-pay + payment-status both work.\n";
	echo "Open the payment link above in a browser to complete a sandbox payment.\n";
	exit( 0 );
}

fwrite( STDERR, "\ninitiate-pay worked but status lookup failed — check the response above.\n" );
exit( 1 );

/* ----------------------------------------------------------------------- */

/**
 * Minimal HTTPS request via streams (no curl extension needed).
 *
 * @return array{0:int,1:array} [http_code, decoded_body]
 */
function fapshi_request( $method, $url, $user, $key, array $body = null ) {
	$headers = array(
		'apiuser: ' . $user,
		'apikey: ' . $key,
		'Accept: application/json',
	);
	$opts = array(
		'http' => array(
			'method'        => $method,
			'header'        => $headers,
			'ignore_errors' => true, // capture 4xx bodies instead of warnings
			'timeout'       => 30,
		),
	);
	if ( null !== $body ) {
		$opts['http']['header'][] = 'Content-Type: application/json';
		$opts['http']['content']  = json_encode( $body );
	}

	$raw  = @file_get_contents( $url, false, stream_context_create( $opts ) );
	$code = 0;
	if ( isset( $http_response_header[0] ) && preg_match( '#\s(\d{3})\s#', $http_response_header[0], $m ) ) {
		$code = (int) $m[1];
	}
	$decoded = json_decode( (string) $raw, true );
	return array( $code, is_array( $decoded ) ? $decoded : array( 'raw' => $raw ) );
}

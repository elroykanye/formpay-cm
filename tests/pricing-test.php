<?php
/**
 * Standalone test for the pricing engine — no PHPUnit/WordPress required.
 * Run: docker run --rm -v "$PWD:/app" php:8.2-cli php /app/tests/pricing-test.php
 *
 * Exercises the real PriceResolver / PriceRule / RuleParser classes. We only
 * shim the handful of WP functions they touch.
 *
 * @package FormPayCM
 */

define( 'ABSPATH', __DIR__ );

// --- Minimal WP shims --------------------------------------------------------
if ( ! function_exists( '__' ) ) {
	function __( $t, $d = null ) { return $t; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { return $value; } // identity
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}

require __DIR__ . '/../includes/Pricing/PriceRule.php';
require __DIR__ . '/../includes/Pricing/PriceResolver.php';
require __DIR__ . '/../includes/Pricing/RuleParser.php';

use FormPayCM\Pricing\PriceRule;
use FormPayCM\Pricing\PriceResolver;
use FormPayCM\Pricing\RuleParser;

$resolver = new PriceResolver();
$pass = 0; $fail = 0;

function check( $label, $actual, $expected ) {
	global $pass, $fail;
	$ok = ( $actual === $expected );
	if ( is_object( $actual ) && $actual instanceof WP_Error ) {
		$actual = 'WP_Error:' . $actual->code;
	}
	if ( $ok ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; echo "  FAIL  $label  (got " . var_export( $actual, true ) . ", expected " . var_export( $expected, true ) . ")\n"; }
}

echo "Fixed mode\n";
$r = PriceRule::from_array( array( 'mode' => 'fixed', 'amount' => 5000 ) );
check( 'returns fixed amount', $resolver->resolve( $r, array() ), 5000 );

echo "Field map (faculty)\n";
$r = PriceRule::from_array( array(
	'mode'  => 'field_map',
	'field' => 'faculty',
	'map'   => array( 'medicine' => 75000, 'law' => 50000, 'arts' => 40000 ),
) );
check( 'medicine → 75000', $resolver->resolve( $r, array( 'faculty' => 'medicine' ) ), 75000 );
check( 'law → 50000', $resolver->resolve( $r, array( 'faculty' => 'law' ) ), 50000 );

echo "Field map security: client cannot inject a price\n";
// Browser tries to also post a cheap 'amount' — resolver must ignore it.
check( 'ignores posted amount, uses map', $resolver->resolve( $r, array( 'faculty' => 'medicine', 'amount' => 1 ) ), 75000 );

echo "Field map: unknown option with no default → error\n";
$res = $resolver->resolve( $r, array( 'faculty' => 'unknown' ) );
check( 'unknown rejected', is_wp_error( $res ), true );

echo "Field map: unknown option with default → default\n";
$r2 = PriceRule::from_array( array(
	'mode' => 'field_map', 'field' => 'faculty',
	'map' => array( 'law' => 50000 ), 'default' => 10000,
) );
check( 'falls back to default', $resolver->resolve( $r2, array( 'faculty' => 'x' ) ), 10000 );

echo "Conditional (faculty + level)\n";
$r = PriceRule::from_array( array(
	'mode'  => 'conditional',
	'rules' => array(
		array( 'when' => array( 'faculty' => 'medicine', 'level' => '100' ), 'amount' => 80000 ),
		array( 'when' => array( 'faculty' => 'medicine' ), 'amount' => 75000 ),
	),
	'default' => 30000,
) );
check( 'med+100 → 80000', $resolver->resolve( $r, array( 'faculty' => 'medicine', 'level' => '100' ) ), 80000 );
check( 'med+200 → 75000 (2nd rule)', $resolver->resolve( $r, array( 'faculty' => 'medicine', 'level' => '200' ) ), 75000 );
check( 'no match → default', $resolver->resolve( $r, array( 'faculty' => 'law' ) ), 30000 );

echo "Field value (donation), opt-in\n";
$r = PriceRule::from_array( array( 'mode' => 'field_value', 'field' => 'donation' ) );
check( 'reads typed amount', $resolver->resolve( $r, array( 'donation' => '2500' ) ), 2500 );
check( 'strips non-digits', $resolver->resolve( $r, array( 'donation' => '2 500 FCFA' ) ), 2500 );

echo "Minimum amount guard (Fapshi 100 XAF)\n";
$r = PriceRule::from_array( array( 'mode' => 'fixed', 'amount' => 50 ) );
check( 'below minimum rejected', is_wp_error( $resolver->resolve( $r, array() ) ), true );

echo "RuleParser round-trips\n";
$map = RuleParser::parse_map( "medicine:75000\nlaw:50000\narts: 40000" );
check( 'parses map', $map, array( 'medicine' => 75000, 'law' => 50000, 'arts' => 40000 ) );
$cond = RuleParser::parse_conditions( "faculty=medicine & level=100 : 80000" );
check( 'parses conditions', $cond, array( array( 'when' => array( 'faculty' => 'medicine', 'level' => '100' ), 'amount' => 80000 ) ) );

echo "\n----------------------------------------\n";
echo "PASS: $pass   FAIL: $fail\n";
exit( $fail === 0 ? 0 : 1 );

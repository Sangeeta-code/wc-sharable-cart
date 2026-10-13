<?php
/**
 * Test runner:  php tests/run.php
 *
 * @package WC_Share_Cart
 */

require __DIR__ . '/bootstrap.php';

foreach ( array( 'test-link-security.php', 'test-expiration.php', 'test-cart-serialization.php', 'test-cart-import.php' ) as $file ) {
	require __DIR__ . '/' . $file;
}

$passed = 0;
$failed = 0;

foreach ( $GLOBALS['wcsc_tests'] as $test ) {
	list( $name, $fn ) = $test;
	wcsc_reset_state();
	try {
		$fn();
		++$passed;
		echo "  ok    {$name}\n";
	} catch ( Throwable $e ) {
		++$failed;
		echo "  FAIL  {$name}\n        " . $e->getMessage() . "\n";
	}
}

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );

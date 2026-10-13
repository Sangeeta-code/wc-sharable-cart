<?php
/**
 * Expiration and status tests.
 *
 * @package WC_Share_Cart
 */

function wcsc_row( $status, $expires_ts ) {
	return (object) array(
		'status'     => $status,
		'expires_at' => gmdate( 'Y-m-d H:i:s', $expires_ts ),
	);
}

wcsc_test(
	'a link before its expiry is active',
	function () {
		wcsc_assert_same( 'active', WCSC_Share_Links::effective_status( wcsc_row( 'active', time() + 3600 ) ) );
	}
);

wcsc_test(
	'a link at or after its expiry is expired even if the cron job has not run yet',
	function () {
		wcsc_assert_same( 'expired', WCSC_Share_Links::effective_status( wcsc_row( 'active', time() - 1 ) ) );
		wcsc_assert_same( 'expired', WCSC_Share_Links::effective_status( wcsc_row( 'active', time() ) ) );
	}
);

wcsc_test(
	'a link already marked expired stays expired',
	function () {
		wcsc_assert_same( 'expired', WCSC_Share_Links::effective_status( wcsc_row( 'expired', time() + 9999 ) ) );
	}
);

wcsc_test(
	'revoked wins over everything else',
	function () {
		wcsc_assert_same( 'revoked', WCSC_Share_Links::effective_status( wcsc_row( 'revoked', time() + 3600 ) ) );
		wcsc_assert_same( 'revoked', WCSC_Share_Links::effective_status( wcsc_row( 'revoked', time() - 3600 ) ) );
	}
);

wcsc_test(
	'status can be evaluated at a given moment',
	function () {
		$row = wcsc_row( 'active', 1000000 );
		wcsc_assert_same( 'active', WCSC_Share_Links::effective_status( $row, 999999 ) );
		wcsc_assert_same( 'expired', WCSC_Share_Links::effective_status( $row, 1000000 ) );
	}
);

wcsc_test(
	'stored datetimes are UTC and round-trip exactly',
	function () {
		$ts = 1790000000;
		wcsc_assert_same( $ts, WCSC_Share_Links::to_timestamp( WCSC_Share_Links::mysql_utc( $ts ) ) );
		wcsc_assert_same( 0, WCSC_Share_Links::to_timestamp( null ) );
		// Must not depend on the PHP default timezone.
		$previous = date_default_timezone_get();
		date_default_timezone_set( 'Asia/Kolkata' );
		wcsc_assert_same( $ts, WCSC_Share_Links::to_timestamp( WCSC_Share_Links::mysql_utc( $ts ) ) );
		date_default_timezone_set( $previous );
	}
);

wcsc_test(
	'requested expiry is clamped to the configured maximum validity',
	function () {
		update_option( 'wcsc_settings', array( 'default_expiry_days' => 7, 'max_validity_days' => 30 ) );
		WCSC_Settings::flush_cache();
		wcsc_assert_same( 7, WCSC_Share_Links::clamp_days( null ), 'Default is used when nothing is requested' );
		wcsc_assert_same( 30, WCSC_Share_Links::clamp_days( 365 ) );
		wcsc_assert_same( 1, WCSC_Share_Links::clamp_days( 0 ) );
		wcsc_assert_same( 14, WCSC_Share_Links::clamp_days( 14 ) );
	}
);

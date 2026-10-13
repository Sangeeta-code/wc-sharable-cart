<?php
/**
 * Token, hashing, ownership and rate limiting tests.
 *
 * @package WC_Share_Cart
 */

wcsc_test(
	'token is 43 URL-safe characters and passes the format check',
	function () {
		$token = WCSC_Security::generate_token();
		wcsc_assert_same( 43, strlen( $token ) );
		wcsc_assert( 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', $token ), 'Token must be URL safe' );
		wcsc_assert( WCSC_Security::is_valid_token_format( $token ) );
	}
);

wcsc_test(
	'tokens are unique across 2000 generations',
	function () {
		$seen = array();
		for ( $i = 0; $i < 2000; $i++ ) {
			$seen[ WCSC_Security::generate_token() ] = true;
		}
		wcsc_assert_same( 2000, count( $seen ) );
	}
);

wcsc_test(
	'malformed tokens are rejected before any database lookup',
	function () {
		foreach ( array( '', 'short', str_repeat( 'a', 42 ), str_repeat( 'a', 44 ), str_repeat( '!', 43 ), "abc'; DROP TABLE x;--", array( 'a' ), null, 123 ) as $bad ) {
			wcsc_assert( ! WCSC_Security::is_valid_token_format( $bad ), 'Should reject ' . var_export( $bad, true ) );
		}
		wcsc_assert_same( null, WCSC_Share_Links::get_by_token( 'nope' ) );
	}
);

wcsc_test(
	'only a 64-hex hash is derived from the token and it is not the token',
	function () {
		$token = WCSC_Security::generate_token();
		$hash  = WCSC_Security::hash_token( $token );
		wcsc_assert_same( 64, strlen( $hash ) );
		wcsc_assert( 1 === preg_match( '/^[a-f0-9]{64}$/', $hash ) );
		wcsc_assert( false === strpos( $hash, $token ) );
		wcsc_assert_same( $hash, WCSC_Security::hash_token( $token ), 'Hash must be deterministic' );
		wcsc_assert( $hash !== WCSC_Security::hash_token( WCSC_Security::generate_token() ) );
	}
);

wcsc_test(
	'guest ownership marker never contains the raw session id',
	function () {
		$raw    = 't_1234567890abcdef';
		$marker = WCSC_Security::hash_owner( $raw );
		wcsc_assert( false === strpos( $marker, $raw ) );
		wcsc_assert_same( 64, strlen( $marker ) );
	}
);

wcsc_test(
	'ownership: user id, guest marker and strangers',
	function () {
		$marker = WCSC_Security::hash_owner( 'guest-A' );
		$user   = (object) array( 'user_id' => 5, 'owner_hash' => null );
		$guest  = (object) array( 'user_id' => null, 'owner_hash' => $marker );

		wcsc_assert( WCSC_Share_Links::is_owner( $user, 5 ) );
		wcsc_assert( ! WCSC_Share_Links::is_owner( $user, 6 ), 'Another user must not own it' );
		wcsc_assert( ! WCSC_Share_Links::is_owner( $user, 0, $marker ), 'A guest must not own a user record' );
		wcsc_assert( WCSC_Share_Links::is_owner( $guest, 0, $marker ) );
		wcsc_assert( ! WCSC_Share_Links::is_owner( $guest, 0, WCSC_Security::hash_owner( 'guest-B' ) ) );
		wcsc_assert( ! WCSC_Share_Links::is_owner( $guest, 0, null ) );
	}
);

wcsc_test(
	'rate limiter allows up to the maximum, then blocks',
	function () {
		for ( $i = 1; $i <= 3; $i++ ) {
			wcsc_assert( WCSC_Security::allow( 'share', 'ip-1', 3, 3600 ), "Hit {$i} should be allowed" );
		}
		wcsc_assert( ! WCSC_Security::allow( 'share', 'ip-1', 3, 3600 ), 'Fourth hit must be blocked' );
	}
);

wcsc_test(
	'rate limiter keeps identities and buckets separate',
	function () {
		wcsc_assert( WCSC_Security::allow( 'share', 'a', 1, 3600 ) );
		wcsc_assert( ! WCSC_Security::allow( 'share', 'a', 1, 3600 ) );
		wcsc_assert( WCSC_Security::allow( 'share', 'b', 1, 3600 ), 'Other identity is unaffected' );
		wcsc_assert( WCSC_Security::allow( 'other', 'a', 1, 3600 ), 'Other bucket is unaffected' );
	}
);

wcsc_test(
	'rate limiter window resets after it has elapsed',
	function () {
		wcsc_assert( WCSC_Security::allow( 'share', 'z', 1, 3600 ) );
		wcsc_assert( ! WCSC_Security::allow( 'share', 'z', 1, 3600 ) );
		$key                                    = 'wcsc_rl_' . md5( 'share|z' );
		$GLOBALS['wcsc_transients'][ $key ]['reset'] = time() - 1;
		wcsc_assert( WCSC_Security::allow( 'share', 'z', 1, 3600 ), 'A new window starts after reset' );
	}
);

wcsc_test(
	'a limit of 0 disables the limiter',
	function () {
		for ( $i = 0; $i < 20; $i++ ) {
			wcsc_assert( WCSC_Security::allow( 'share', 'x', 0, 3600 ) );
		}
	}
);

wcsc_test(
	'settings sanitization clamps numbers, validates colours/selects and keeps expiry <= maximum',
	function () {
		$out = WCSC_Settings::sanitize(
			array(
				'default_expiry_days' => '999',
				'max_validity_days'   => '10',
				'button_bg'           => 'javascript:alert(1)',
				'button_color'        => '#ABCDEF',
				'button_radius'       => '-5',
				'classic_placement'   => 'evil',
				'button_class'        => 'my-class <script>x</script> ok',
				'button_label'        => '',
				'msg_invalid'         => '',
				'opt_copy'            => '1',
			)
		);
		wcsc_assert_same( 10, $out['max_validity_days'] );
		wcsc_assert_same( 10, $out['default_expiry_days'], 'Default may not exceed maximum' );
		wcsc_assert_same( '#2563eb', $out['button_bg'], 'Invalid colour falls back to default' );
		wcsc_assert_same( '#ABCDEF', $out['button_color'] );
		wcsc_assert_same( 0, $out['button_radius'] );
		wcsc_assert_same( 'after_cart_table', $out['classic_placement'] );
		wcsc_assert( false === strpos( $out['button_class'], '<' ), 'Class names are sanitized' );
		wcsc_assert_same( 'Share Cart', $out['button_label'], 'Empty label falls back to default' );
		wcsc_assert_same( 'This shared cart link is not valid.', $out['msg_invalid'] );
		wcsc_assert_same( 1, $out['opt_copy'] );
		wcsc_assert_same( 0, $out['opt_qr'], 'Unchecked checkbox becomes 0' );
	}
);

wcsc_test(
	'placement can be set to "none" (shortcode only) and load_everywhere is an opt-in checkbox',
	function () {
		$defaults = WCSC_Settings::defaults();
		wcsc_assert_same( 0, $defaults['load_everywhere'], 'Scripts are not loaded site-wide by default' );

		$out = WCSC_Settings::sanitize(
			array(
				'classic_placement' => 'none',
				'block_placement'   => 'none',
				'load_everywhere'   => '1',
			)
		);
		wcsc_assert_same( 'none', $out['classic_placement'] );
		wcsc_assert_same( 'none', $out['block_placement'] );
		wcsc_assert_same( 1, $out['load_everywhere'] );

		$off = WCSC_Settings::sanitize( array( 'block_placement' => 'bogus' ) );
		wcsc_assert_same( 'below_checkout', $off['block_placement'], 'Unknown placement falls back to default' );
		wcsc_assert_same( 0, $off['load_everywhere'] );
	}
);

wcsc_test(
	'shortcode button markup is escaped and only carries safe classes',
	function () {
		require_once WCSC_PATH . 'public/class-wcsc-public.php';

		$html = WCSC_Public::button_html(
			array(
				'label'       => '<b>Send</b> "cart"',
				'class'       => 'my-btn "onclick=alert(1)',
				'extra_class' => 'wcsc-share-btn--shortcode',
				'icon'        => false,
				'full'        => true,
			)
		);
		wcsc_assert( false === strpos( $html, '<b>' ), 'Label is escaped' );
		wcsc_assert( false === strpos( $html, 'onclick=alert' ), 'Class attribute cannot be broken out of' );
		wcsc_assert( false !== strpos( $html, 'wcsc-share-btn wcsc-share-btn--shortcode' ), 'Base classes present' );
		wcsc_assert( false !== strpos( $html, 'wcsc-share-wrap--full' ), 'Full width wrapper' );
		wcsc_assert( false === strpos( $html, '<svg' ), 'Icon can be hidden' );

		$blank = WCSC_Public::button_html( array( 'label' => '   ' ) );
		wcsc_assert( false !== strpos( $blank, '>Share Cart<' ), 'Empty label falls back to the configured label' );
		wcsc_assert( false !== strpos( $blank, '<svg' ), 'Icon shown by default' );
	}
);

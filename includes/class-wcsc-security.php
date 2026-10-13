<?php
/**
 * Security helpers: tokens, hashing, nonces, rate limiting, logging.
 *
 * @package WC_Share_Cart
 */

defined( 'ABSPATH' ) || exit;

/**
 * Security utilities.
 */
class WCSC_Security {

	const TOKEN_REGEX  = '/^[A-Za-z0-9_-]{43}$/';
	const NONCE_ACTION = 'wcsc_rest';

	/**
	 * Generate a 256-bit, URL-safe, cryptographically secure token (43 characters).
	 *
	 * @return string
	 * @throws Exception When no secure randomness source is available.
	 */
	public static function generate_token() {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Whether a string has the shape of a share token.
	 *
	 * @param mixed $token Candidate.
	 * @return bool
	 */
	public static function is_valid_token_format( $token ) {
		return is_string( $token ) && 1 === preg_match( self::TOKEN_REGEX, $token );
	}

	/**
	 * Hash for storage. Tokens carry 256 bits of entropy so a fast hash is sufficient and keeps lookups indexed.
	 *
	 * @param string $token Raw token.
	 * @return string 64 hex chars.
	 */
	public static function hash_token( $token ) {
		return hash( 'sha256', (string) $token );
	}

	/**
	 * Opaque ownership marker for guests, derived from the WooCommerce customer id.
	 * The raw session id is never stored or exposed.
	 *
	 * @param string $customer_id WooCommerce customer/session id.
	 * @return string
	 */
	public static function hash_owner( $customer_id ) {
		return hash_hmac( 'sha256', (string) $customer_id, wp_salt( 'auth' ) );
	}

	/**
	 * Visitor IP (REMOTE_ADDR only; proxies can be handled through the filter).
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = apply_filters( 'wcsc_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Fixed-window rate limiter. Returns true when the action is allowed (and records it).
	 * Counters live in transients, so they use the object cache when one is present.
	 * The read/increment is not atomic; it is a best-effort abuse brake, not an accounting system.
	 *
	 * @param string $bucket         Logical bucket, e.g. "share_ip".
	 * @param string $identity       Identity inside the bucket (IP, session hash...).
	 * @param int    $max            Allowed hits per window. 0 disables the limit.
	 * @param int    $period_seconds Window length in seconds.
	 * @return bool
	 */
	public static function allow( $bucket, $identity, $max, $period_seconds ) {
		$max = (int) $max;
		if ( $max <= 0 ) {
			return true;
		}

		$period_seconds = max( 1, (int) $period_seconds );
		$key            = 'wcsc_rl_' . md5( $bucket . '|' . $identity );
		$now            = time();
		$data           = get_transient( $key );

		if ( ! is_array( $data ) || ! isset( $data['count'], $data['reset'] ) || $data['reset'] <= $now ) {
			$data = array(
				'count' => 0,
				'reset' => $now + $period_seconds,
			);
		}

		if ( $data['count'] >= $max ) {
			return false;
		}

		++$data['count'];
		set_transient( $key, $data, max( 1, $data['reset'] - $now ) );
		return true;
	}

	/**
	 * Create the nonce used for the plugin's REST calls.
	 *
	 * @return string
	 */
	public static function create_nonce() {
		return wp_create_nonce( self::NONCE_ACTION );
	}

	/**
	 * Verify the CSRF header on a REST request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function verify_rest_nonce( $request ) {
		$nonce = $request->get_header( 'X-WCSC-Nonce' );
		return is_string( $nonce ) && '' !== $nonce && false !== wp_verify_nonce( $nonce, self::NONCE_ACTION );
	}

	/**
	 * Log to WooCommerce logs without leaking tokens or personal data.
	 *
	 * @param string $message Message.
	 * @param string $level   PSR level.
	 * @param array  $context Extra context (must not contain tokens).
	 */
	public static function log( $message, $level = 'warning', array $context = array() ) {
		if ( ! WCSC_Settings::get_bool( 'enable_logging' ) || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$context['source'] = 'wc-share-cart';
		wc_get_logger()->log( $level, $message, $context );
	}
}

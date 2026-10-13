<?php
/**
 * Standalone test bootstrap.
 *
 * These tests exercise the plugin's pure logic (tokens, hashing, expiry, rate limiting, serialization,
 * validation and import planning) against light WordPress / WooCommerce stubs, so they run with plain
 * PHP:   php tests/run.php
 *
 * They do NOT replace testing in a real WooCommerce installation. See tests/MANUAL-TESTS.md.
 *
 * @package WC_Share_Cart
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WCSC_PATH', dirname( __DIR__ ) . '/' );
define( 'WCSC_VERSION', '1.0.0-test' );
define( 'WCSC_SLUG', 'shared-cart' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

/* ---------------------------------------------------------------- global stores */

$GLOBALS['wcsc_options']    = array();
$GLOBALS['wcsc_transients'] = array();
$GLOBALS['wcsc_products']   = array();
$GLOBALS['wcsc_filters']    = array();

/* ---------------------------------------------------------------- WP function stubs */

function __( $s ) { return $s; }
function esc_html__( $s ) { return $s; }
function _n( $single, $plural, $n ) { return 1 === (int) $n ? $single : $plural; }
function add_action() {}
function add_filter( $tag, $cb ) { $GLOBALS['wcsc_filters'][ $tag ][] = $cb; }
function apply_filters( $tag, $value ) {
	$args = func_get_args();
	array_shift( $args );
	foreach ( isset( $GLOBALS['wcsc_filters'][ $tag ] ) ? $GLOBALS['wcsc_filters'][ $tag ] : array() as $cb ) {
		$args[0] = call_user_func_array( $cb, $args );
	}
	return $args[0];
}
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['wcsc_options'] ) ? $GLOBALS['wcsc_options'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['wcsc_options'][ $k ] = $v; return true; }
function add_option( $k, $v ) { $GLOBALS['wcsc_options'][ $k ] = $v; return true; }
function get_transient( $k ) {
	if ( ! isset( $GLOBALS['wcsc_transients'][ $k ] ) ) {
		return false;
	}
	return $GLOBALS['wcsc_transients'][ $k ];
}
function set_transient( $k, $v ) { $GLOBALS['wcsc_transients'][ $k ] = $v; return true; }
function wp_salt() { return 'test-salt'; }
function wp_unslash( $v ) { return $v; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_title( $s ) { return preg_replace( '/[^a-z0-9_\-%]/', '', str_replace( ' ', '-', strtolower( (string) $s ) ) ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function sanitize_html_class( $s ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $s ); }
function sanitize_hex_color( $c ) { return preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', (string) $c ) ? $c : ''; }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
function absint( $n ) { return abs( (int) $n ); }
function wp_json_encode( $d ) { return json_encode( $d ); }
function wp_cache_get() { return false; }
function wp_cache_set() {}
function wp_cache_delete() {}
function add_settings_error() {}
function wc_stock_amount( $n ) { return (int) $n; }
function wc_get_product( $id ) { return isset( $GLOBALS['wcsc_products'][ $id ] ) ? $GLOBALS['wcsc_products'][ $id ] : false; }
function wc_format_stock_quantity_for_display( $q ) { return (string) $q; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }

/* ---------------------------------------------------------------- class stubs (faithful to the real API where it matters) */

class WP_Error {
	public $errors = array();
	public $error_data = array();
	public function __construct( $code = '', $message = '', $data = '' ) {
		if ( empty( $code ) ) {
			return;
		}
		$this->errors[ $code ][] = $message;
		if ( ! empty( $data ) ) {
			$this->error_data[ $code ] = $data;
		}
	}
	public function get_error_code() {
		$codes = array_keys( $this->errors );
		return $codes ? $codes[0] : '';
	}
	public function get_error_message( $code = '' ) {
		$code = $code ? $code : $this->get_error_code();
		return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
	}
	/** Real semantics: the argument is an error CODE, not a key inside the data. */
	public function get_error_data( $code = '' ) {
		$code = $code ? $code : $this->get_error_code();
		return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
	}
}

class WC_Product {
	public $p;
	public function __construct( array $props = array() ) {
		$this->p = $props + array(
			'id'            => 0,
			'name'          => 'Product',
			'sku'           => '',
			'type'          => 'simple',
			'status'        => 'publish',
			'purchasable'   => true,
			'in_stock'      => true,
			'stock'         => null,   // null = not managed.
			'backorders'    => false,
			'parent_id'     => 0,
			'variation_options' => array(),
			'attrs'         => array(),
		);
	}
	public function get_id() { return $this->p['id']; }
	public function get_name() { return $this->p['name']; }
	public function get_sku() { return $this->p['sku']; }
	public function get_type() { return $this->p['type']; }
	public function get_status() { return $this->p['status']; }
	public function is_type( $t ) { return $this->p['type'] === $t; }
	public function is_purchasable() { return $this->p['purchasable']; }
	public function is_in_stock() { return $this->p['in_stock']; }
	public function get_stock_quantity() { return $this->p['stock']; }
	public function get_stock_managed_by_id() { return $this->p['id']; }
	public function get_parent_id() { return $this->p['parent_id']; }
	public function has_enough_stock( $qty ) {
		return null === $this->p['stock'] || $this->p['backorders'] || $this->p['stock'] >= $qty;
	}
	public function get_variation_attributes() {
		return $this->p['type'] === 'variable' ? $this->p['variation_options'] : $this->p['attrs'];
	}
}
class WC_Product_Variation extends WC_Product {}

/* ---------------------------------------------------------------- plugin code under test */

require_once WCSC_PATH . 'admin/class-wcsc-settings.php';
require_once WCSC_PATH . 'includes/class-wcsc-security.php';
require_once WCSC_PATH . 'includes/class-wcsc-cart-serializer.php';
require_once WCSC_PATH . 'includes/class-wcsc-cart-manager.php';

// Minimal stand-ins for classes that need a database; only the parts used by the logic under test.
class WCSC_Database {
	public static function table() { return 'wp_wcsc_shared_carts'; }
}
class WCSC_Analytics {
	public static function increment() {}
}
require_once WCSC_PATH . 'includes/class-wcsc-share-links.php';

/* ---------------------------------------------------------------- tiny test framework */

$GLOBALS['wcsc_tests'] = array();

function wcsc_test( $name, callable $fn ) {
	$GLOBALS['wcsc_tests'][] = array( $name, $fn );
}

function wcsc_assert( $cond, $message = 'Assertion failed' ) {
	if ( ! $cond ) {
		throw new RuntimeException( $message );
	}
}

function wcsc_assert_same( $expected, $actual, $message = '' ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( trim( $message . ' Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ) );
	}
}

function wcsc_reset_state() {
	$GLOBALS['wcsc_options']    = array();
	$GLOBALS['wcsc_transients'] = array();
	$GLOBALS['wcsc_products']   = array();
	$GLOBALS['wcsc_filters']    = array();
	WCSC_Settings::flush_cache();
	WCSC_Cart_Serializer::reset_adapters();
}

/** Register a catalogue product for wc_get_product(). */
function wcsc_add_product( $id, array $props = array(), $class = 'WC_Product' ) {
	$GLOBALS['wcsc_products'][ $id ] = new $class( array( 'id' => $id ) + $props );
	return $GLOBALS['wcsc_products'][ $id ];
}

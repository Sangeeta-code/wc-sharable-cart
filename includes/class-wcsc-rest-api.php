<?php
/**
 * REST API: create and revoke share links.
 *
 * Previewing a shared cart is a plain read-only GET page; importing is a POST form
 * handled by WCSC_Public. Both state-changing REST routes require POST, a CSRF nonce
 * header and rate limiting.
 *
 * @package WC_Share_Cart
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST routes.
 */
class WCSC_REST_API {

	const NAMESPACE_ = 'wcsc/v1';

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_,
			'/share',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_share' ),
				// Guests must be able to share. Authorisation is enforced inside via nonce + settings + rate limits.
				'permission_callback' => '__return_true',
				'args'                => array(
					'confirm_partial' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		// Fresh CSRF nonces. Needed on pages other than the cart (drawers): a guest's nonce is tied to the
		// shopping session, which may only have been created after the page loaded (first add to cart).
		register_rest_route(
			self::NAMESPACE_,
			'/nonces',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_nonces' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/revoke',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'revoke_share' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * GET /nonces.
	 *
	 * Requires a custom request header. Browsers only let other origins send custom headers after a
	 * CORS preflight, which WordPress does not approve for this header, so only this site's own scripts
	 * can call it, and only their own page can read the response.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_nonces( $request ) {
		if ( ! WCSC_Settings::get_bool( 'enabled' ) || ! WCSC_Public::sharing_available() ) {
			return new WP_Error( 'wcsc_disabled', __( 'Cart sharing is currently disabled.', 'wc-share-cart' ), array( 'status' => 403 ) );
		}
		if ( '1' !== (string) $request->get_header( 'X-WCSC-Refresh' ) ) {
			return new WP_Error( 'wcsc_bad_request', __( 'Invalid request.', 'wc-share-cart' ), array( 'status' => 400 ) );
		}
		if ( ! WCSC_Cart_Manager::ensure_cart() ) {
			return new WP_Error( 'wcsc_no_cart', __( 'Your cart is not available right now. Please reload the page.', 'wc-share-cart' ), array( 'status' => 500 ) );
		}
		if ( ! WCSC_Security::allow( 'nonces', WCSC_Cart_Manager::session_identity(), 30, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'wcsc_rate_limited', __( 'Too many requests. Please wait a moment and try again.', 'wc-share-cart' ), array( 'status' => 429 ) );
		}

		return $this->nocache(
			rest_ensure_response(
				array(
					'restNonce' => wp_create_nonce( 'wp_rest' ),
					'nonce'     => WCSC_Security::create_nonce(),
				)
			)
		);
	}

	/**
	 * Common guard: feature switches, cart session, CSRF nonce.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	private function guard( $request ) {
		if ( ! WCSC_Settings::get_bool( 'enabled' ) ) {
			return new WP_Error( 'wcsc_disabled', __( 'Cart sharing is currently disabled.', 'wc-share-cart' ), array( 'status' => 403 ) );
		}

		// The session must exist before the nonce check: WooCommerce ties guest nonces to the session.
		if ( ! WCSC_Cart_Manager::ensure_cart() ) {
			return new WP_Error( 'wcsc_no_cart', __( 'Your cart is not available right now. Please reload the page.', 'wc-share-cart' ), array( 'status' => 500 ) );
		}

		if ( ! WCSC_Security::verify_rest_nonce( $request ) ) {
			return new WP_Error( 'wcsc_bad_nonce', __( 'Your session expired. Please reload the page and try again.', 'wc-share-cart' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Add no-cache headers to every response.
	 *
	 * @param WP_REST_Response|WP_Error $response Response.
	 * @return WP_REST_Response|WP_Error
	 */
	private function nocache( $response ) {
		if ( $response instanceof WP_REST_Response ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		}
		return $response;
	}

	/**
	 * POST /share.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_share( $request ) {
		$guard = $this->guard( $request );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$logged_in = is_user_logged_in();
		if ( ( $logged_in && ! WCSC_Settings::get_bool( 'allow_logged_in' ) ) || ( ! $logged_in && ! WCSC_Settings::get_bool( 'allow_guests' ) ) ) {
			return new WP_Error( 'wcsc_forbidden', __( 'Sharing is not available for your account type.', 'wc-share-cart' ), array( 'status' => 403 ) );
		}

		$cart = WC()->cart;
		if ( ! $cart || $cart->is_empty() ) {
			return new WP_Error( 'wcsc_empty_cart', __( 'Your cart is empty. Add some products before sharing.', 'wc-share-cart' ), array( 'status' => 400 ) );
		}

		$serialized  = WCSC_Cart_Serializer::serialize_cart( $cart );
		$items       = $serialized['items'];
		$unsupported = $serialized['unsupported'];

		// Technical key names are only shown to store managers (they help write an adapter).
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			foreach ( $unsupported as $i => $u ) {
				unset( $unsupported[ $i ]['keys'], $unsupported[ $i ]['detail'] );
			}
		}

		if ( empty( $items ) ) {
			return new WP_Error(
				'wcsc_unsupported_all',
				__( 'None of the items in your cart can be shared.', 'wc-share-cart' ),
				array(
					'status'   => 422,
					'excluded' => $unsupported,
				)
			);
		}

		if ( count( $items ) > WCSC_Settings::get_int( 'max_products' ) ) {
			return new WP_Error(
				'wcsc_too_many',
				sprintf(
					/* translators: %d: maximum number of products */
					__( 'A shared cart can contain at most %d products.', 'wc-share-cart' ),
					WCSC_Settings::get_int( 'max_products' )
				),
				array( 'status' => 422 )
			);
		}

		// Tell the customer about unsupported items *before* anything is shared.
		if ( ! empty( $unsupported ) && ! $request->get_param( 'confirm_partial' ) ) {
			return new WP_Error(
				'wcsc_unsupported',
				__( 'Some items in your cart cannot be shared and would be left out.', 'wc-share-cart' ),
				array(
					'status'   => 409,
					'excluded' => $unsupported,
				)
			);
		}

		// Rate limits (per customer/session and per IP).
		$ok_session = WCSC_Security::allow(
			'share_session',
			WCSC_Cart_Manager::session_identity(),
			WCSC_Settings::get_int( 'rate_session_max' ),
			WCSC_Settings::get_int( 'rate_session_period' ) * MINUTE_IN_SECONDS
		);
		$ok_ip      = WCSC_Security::allow(
			'share_ip',
			WCSC_Security::client_ip(),
			WCSC_Settings::get_int( 'rate_ip_max' ),
			WCSC_Settings::get_int( 'rate_ip_period' ) * MINUTE_IN_SECONDS
		);
		if ( ! $ok_session || ! $ok_ip ) {
			$response = new WP_Error( 'wcsc_rate_limited', __( 'You have created too many share links recently. Please try again later.', 'wc-share-cart' ), array( 'status' => 429 ) );
			return $response;
		}

		$created = WCSC_Share_Links::create(
			$items,
			get_current_user_id(),
			WCSC_Cart_Manager::guest_owner_hash()
		);
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$days = max( 1, (int) round( ( WCSC_Share_Links::to_timestamp( $created['expires_at'] ) - time() ) / DAY_IN_SECONDS ) );

		return $this->nocache(
			rest_ensure_response(
				array(
					'success'       => true,
					'url'           => $created['url'],
					'token'         => $created['token'],
					'expires_at'    => gmdate( 'c', WCSC_Share_Links::to_timestamp( $created['expires_at'] ) ),
					'expires_label' => sprintf(
						/* translators: 1: number of days, 2: date */
						_n( 'This link expires in %1$d day (%2$s).', 'This link expires in %1$d days (%2$s).', $days, 'wc-share-cart' ),
						$days,
						WCSC_Share_Links::format_date( $created['expires_at'] )
					),
					'excluded'      => $unsupported,
				)
			)
		);
	}

	/**
	 * POST /revoke. Allowed only for the verified owner (user id or guest session marker).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function revoke_share( $request ) {
		$guard = $this->guard( $request );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		if ( ! WCSC_Security::allow( 'revoke_ip', WCSC_Security::client_ip(), 60, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'wcsc_rate_limited', __( 'Too many requests. Please try again later.', 'wc-share-cart' ), array( 'status' => 429 ) );
		}

		$row = WCSC_Share_Links::get_by_token( (string) $request->get_param( 'token' ) );
		if ( ! $row || ! WCSC_Share_Links::is_owner( $row, get_current_user_id(), WCSC_Cart_Manager::guest_owner_hash() ) ) {
			// Same answer for "unknown" and "not yours".
			return new WP_Error( 'wcsc_not_found', __( 'This link could not be revoked.', 'wc-share-cart' ), array( 'status' => 404 ) );
		}

		WCSC_Share_Links::revoke( (int) $row->id );

		return $this->nocache( rest_ensure_response( array( 'success' => true ) ) );
	}
}

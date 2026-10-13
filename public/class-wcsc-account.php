<?php
/**
 * My Account → Shared Carts.
 *
 * @package WC_Share_Cart
 */

defined( 'ABSPATH' ) || exit;

/**
 * Account endpoint.
 */
class WCSC_Account {

	const PER_PAGE = 10;

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_filter( 'woocommerce_get_query_vars', array( $this, 'add_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_item' ) );
		add_filter( 'woocommerce_endpoint_' . WCSC_ACCOUNT_ENDPOINT . '_title', array( $this, 'endpoint_title' ) );
		add_action( 'woocommerce_account_' . WCSC_ACCOUNT_ENDPOINT . '_endpoint', array( $this, 'render' ) );
		add_action( 'template_redirect', array( $this, 'handle_actions' ), 6 );
	}

	/**
	 * Whether the feature is on.
	 *
	 * @return bool
	 */
	private function enabled() {
		return WCSC_Settings::get_bool( 'enabled' ) && WCSC_Settings::get_bool( 'account_enabled' ) && WCSC_Settings::get_bool( 'allow_logged_in' );
	}

	/**
	 * Register the endpoint with WooCommerce.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function add_query_var( $vars ) {
		if ( $this->enabled() ) {
			$vars[ WCSC_ACCOUNT_ENDPOINT ] = WCSC_ACCOUNT_ENDPOINT;
		}
		return $vars;
	}

	/**
	 * Menu entry (before Logout).
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public function menu_item( $items ) {
		if ( ! $this->enabled() ) {
			return $items;
		}
		$new = array();
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$new[ WCSC_ACCOUNT_ENDPOINT ] = __( 'Shared Carts', 'wc-share-cart' );
			}
			$new[ $key ] = $label;
		}
		if ( ! isset( $new[ WCSC_ACCOUNT_ENDPOINT ] ) ) {
			$new[ WCSC_ACCOUNT_ENDPOINT ] = __( 'Shared Carts', 'wc-share-cart' );
		}
		return $new;
	}

	/**
	 * Endpoint title.
	 *
	 * @return string
	 */
	public function endpoint_title() {
		return __( 'Shared Carts', 'wc-share-cart' );
	}

	/**
	 * Revoke / delete / clear history (POST + nonce + ownership).
	 */
	public function handle_actions() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) || empty( $_POST['wcsc_account_action'] ) ) {
			return;
		}
		if ( ! $this->enabled() || ! is_user_logged_in() ) {
			return;
		}

		$redirect = wc_get_endpoint_url( WCSC_ACCOUNT_ENDPOINT, '', wc_get_page_permalink( 'myaccount' ) );

		if ( ! isset( $_POST['wcsc_account_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcsc_account_nonce'] ) ), 'wcsc_account' ) ) {
			wc_add_notice( __( 'Your session expired. Please try again.', 'wc-share-cart' ), 'error' );
			wp_safe_redirect( $redirect );
			exit;
		}

		$user_id = get_current_user_id();
		$action  = sanitize_key( wp_unslash( $_POST['wcsc_account_action'] ) );

		if ( 'clear_history' === $action ) {
			// Only inactive records (expired / revoked) are removed in bulk.
			$result = WCSC_Share_Links::query( array( 'user_id' => $user_id, 'per_page' => 200 ) );
			$ids    = array();
			foreach ( $result['rows'] as $row ) {
				if ( 'active' !== WCSC_Share_Links::effective_status( $row ) ) {
					$ids[] = (int) $row->id;
				}
			}
			WCSC_Share_Links::delete( $ids );
			wc_add_notice( __( 'Your inactive shared carts were removed.', 'wc-share-cart' ), 'success' );
		} else {
			$id  = isset( $_POST['wcsc_cart_id'] ) ? absint( $_POST['wcsc_cart_id'] ) : 0;
			$row = $id ? WCSC_Share_Links::get( $id ) : null;

			// Ownership check: a customer can only manage their own records.
			if ( ! $row || ! WCSC_Share_Links::is_owner( $row, $user_id ) ) {
				wc_add_notice( __( 'That shared cart could not be found.', 'wc-share-cart' ), 'error' );
			} elseif ( 'revoke' === $action ) {
				WCSC_Share_Links::revoke( $id );
				wc_add_notice( __( 'The link has been revoked.', 'wc-share-cart' ), 'success' );
			} elseif ( 'delete' === $action ) {
				WCSC_Share_Links::delete( array( $id ) );
				wc_add_notice( __( 'The shared cart was removed from your history.', 'wc-share-cart' ), 'success' );
			}
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Render the endpoint content.
	 */
	public function render() {
		if ( ! $this->enabled() || ! is_user_logged_in() ) {
			return;
		}

		$page   = max( 1, absint( get_query_var( 'paged', 1 ) ) );
		$result = WCSC_Share_Links::query(
			array(
				'user_id'  => get_current_user_id(),
				'per_page' => self::PER_PAGE,
				'page'     => $page,
			)
		);

		WCSC_Public::get_template(
			'my-account-shared-carts.php',
			array(
				'rows'  => $result['rows'],
				'total' => $result['total'],
				'pages' => (int) ceil( $result['total'] / self::PER_PAGE ),
				'page'  => $page,
			)
		);
	}
}

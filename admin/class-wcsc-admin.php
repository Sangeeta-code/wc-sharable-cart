<?php
/**
 * Admin screens: dashboard, shared carts, settings.
 *
 * @package WC_Share_Cart
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin controller.
 */
class WCSC_Admin {

	const CAP  = 'manage_woocommerce';
	const PAGE = 'wc-share-cart';

	/**
	 * Hook suffix of our page.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( 'WCSC_Settings', 'register' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'option_page_capability_' . WCSC_Settings::GROUP, array( $this, 'settings_capability' ) );
	}

	/**
	 * Settings API capability.
	 *
	 * @return string
	 */
	public function settings_capability() {
		return self::CAP;
	}

	/**
	 * Add the submenu under WooCommerce.
	 */
	public function menu() {
		$this->hook = add_submenu_page(
			'woocommerce',
			__( 'WC Share Cart', 'wc-share-cart' ),
			__( 'WC Share Cart', 'wc-share-cart' ),
			self::CAP,
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Admin assets, only on our page.
	 *
	 * @param string $hook Current hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( $hook !== $this->hook ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'wcsc-admin', WCSC_URL . 'assets/css/admin.css', array(), WCSC_VERSION );
		wp_enqueue_script( 'wcsc-admin', WCSC_URL . 'assets/js/admin.js', array( 'jquery', 'wp-color-picker' ), WCSC_VERSION, true );
		wp_localize_script(
			'wcsc-admin',
			'wcscAdmin',
			array(
				'confirmDelete' => __( 'Delete the selected shared cart records permanently? This cannot be undone.', 'wc-share-cart' ),
				'confirmRevoke' => __( 'Revoke the selected links? Anyone using them will see a "revoked" message.', 'wc-share-cart' ),
			)
		);
	}

	/**
	 * Current tab.
	 *
	 * @return string
	 */
	private function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification
		return in_array( $tab, array( 'dashboard', 'carts', 'settings' ), true ) ? $tab : 'dashboard';
	}

	/**
	 * Row actions (revoke / delete) and bulk actions. Runs before output so we can redirect.
	 */
	public function handle_actions() {
		if ( ! isset( $_GET['page'] ) || self::PAGE !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$redirect = admin_url( 'admin.php?page=' . self::PAGE . '&tab=carts' );

		// Single row actions (GET with nonce).
		if ( isset( $_GET['wcsc_action'], $_GET['cart_id'] ) ) {
			if ( ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'wc-share-cart' ), '', array( 'response' => 403 ) );
			}
			$action  = sanitize_key( wp_unslash( $_GET['wcsc_action'] ) );
			$cart_id = absint( $_GET['cart_id'] );
			check_admin_referer( 'wcsc_' . $action . '_' . $cart_id );

			if ( 'revoke' === $action ) {
				WCSC_Share_Links::revoke( $cart_id );
				$redirect = add_query_arg( 'wcsc_notice', 'revoked', $redirect );
			} elseif ( 'delete' === $action ) {
				WCSC_Share_Links::delete( array( $cart_id ) );
				$redirect = add_query_arg( 'wcsc_notice', 'deleted', $redirect );
			} else {
				return;
			}
			wp_safe_redirect( $redirect );
			exit;
		}

		// Bulk actions (POST through the list table form).
		$bulk = '';
		if ( isset( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] ) {
			$bulk = sanitize_key( wp_unslash( $_REQUEST['action'] ) );
		} elseif ( isset( $_REQUEST['action2'] ) && '-1' !== $_REQUEST['action2'] ) {
			$bulk = sanitize_key( wp_unslash( $_REQUEST['action2'] ) );
		}

		if ( in_array( $bulk, array( 'bulk_delete', 'bulk_revoke' ), true ) && isset( $_REQUEST['cart'] ) ) {
			if ( ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'wc-share-cart' ), '', array( 'response' => 403 ) );
			}
			check_admin_referer( 'bulk-wcsc_carts' );
			$ids = array_map( 'absint', (array) wp_unslash( $_REQUEST['cart'] ) );

			if ( 'bulk_delete' === $bulk ) {
				$count    = WCSC_Share_Links::delete( $ids );
				$redirect = add_query_arg(
					array(
						'wcsc_notice' => 'deleted',
						'wcsc_count'  => $count,
					),
					$redirect
				);
			} else {
				$count = 0;
				foreach ( $ids as $id ) {
					$count += WCSC_Share_Links::revoke( $id ) ? 1 : 0;
				}
				$redirect = add_query_arg(
					array(
						'wcsc_notice' => 'revoked',
						'wcsc_count'  => $count,
					),
					$redirect
				);
			}
			wp_safe_redirect( $redirect );
			exit;
		}
	}

	/**
	 * Render the page with tabs.
	 */
	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-share-cart' ), '', array( 'response' => 403 ) );
		}

		$tab  = $this->current_tab();
		$tabs = array(
			'dashboard' => __( 'Dashboard', 'wc-share-cart' ),
			'carts'     => __( 'Shared Carts', 'wc-share-cart' ),
			'settings'  => __( 'Settings', 'wc-share-cart' ),
		);

		echo '<div class="wrap wcsc-admin"><h1>' . esc_html__( 'WC Share Cart', 'wc-share-cart' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab %2$s">%3$s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=' . $slug ) ),
				$tab === $slug ? 'nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		$this->render_notices();

		switch ( $tab ) {
			case 'carts':
				$this->render_carts();
				break;
			case 'settings':
				$this->render_settings();
				break;
			default:
				$this->render_dashboard();
		}
		echo '</div>';
	}

	/**
	 * Action result notices.
	 */
	private function render_notices() {
		if ( empty( $_GET['wcsc_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$notice = sanitize_key( wp_unslash( $_GET['wcsc_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$count  = isset( $_GET['wcsc_count'] ) ? absint( $_GET['wcsc_count'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification

		if ( 'deleted' === $notice ) {
			$msg = null === $count ? __( 'Record deleted.', 'wc-share-cart' ) : sprintf( /* translators: %d: count */ _n( '%d record deleted.', '%d records deleted.', $count, 'wc-share-cart' ), $count );
		} elseif ( 'revoked' === $notice ) {
			$msg = null === $count ? __( 'Link revoked.', 'wc-share-cart' ) : sprintf( /* translators: %d: count */ _n( '%d link revoked.', '%d links revoked.', $count, 'wc-share-cart' ), $count );
		} else {
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}

	/**
	 * Dashboard tab.
	 */
	private function render_dashboard() {
		$s     = WCSC_Analytics::summary();
		$cards = array(
			array( __( 'Shared carts created', 'wc-share-cart' ), number_format_i18n( $s['created'] ), '' ),
			array( __( 'Unique links opened', 'wc-share-cart' ), number_format_i18n( $s['opened'] ), '' ),
			array( __( 'Successful imports', 'wc-share-cart' ), number_format_i18n( $s['imports'] ), sprintf( /* translators: 1: merge count, 2: replace count */ __( '%1$s merge / %2$s replace', 'wc-share-cart' ), number_format_i18n( $s['merge'] ), number_format_i18n( $s['replace'] ) ) ),
			array( __( 'Revoked links', 'wc-share-cart' ), number_format_i18n( $s['revoked'] ), '' ),
			array( __( 'Expired links', 'wc-share-cart' ), number_format_i18n( $s['expired'] ), '' ),
			array( __( 'Attributed purchases', 'wc-share-cart' ), number_format_i18n( $s['purchases'] ), __( 'Paid orders only', 'wc-share-cart' ) ),
			array( __( 'Estimated revenue', 'wc-share-cart' ), wc_price( $s['revenue'] ), __( 'Line totals of shared products in paid orders', 'wc-share-cart' ) ),
		);

		echo '<div class="wcsc-cards">';
		foreach ( $cards as $card ) {
			printf(
				'<div class="wcsc-card"><span class="wcsc-card__label">%1$s</span><strong class="wcsc-card__value">%2$s</strong><span class="wcsc-card__note">%3$s</span></div>',
				esc_html( $card[0] ),
				wp_kses_post( $card[1] ),
				esc_html( $card[2] )
			);
		}
		echo '</div>';

		echo '<h2>' . esc_html__( 'Most commonly shared products', 'wc-share-cart' ) . '</h2>';
		$top = WCSC_Analytics::top_products( 5 );
		if ( empty( $top ) ) {
			echo '<p>' . esc_html__( 'No data yet.', 'wc-share-cart' ) . '</p>';
		} else {
			echo '<table class="widefat striped wcsc-top"><thead><tr><th>' . esc_html__( 'Product', 'wc-share-cart' ) . '</th><th>' . esc_html__( 'Times shared', 'wc-share-cart' ) . '</th></tr></thead><tbody>';
			foreach ( $top as $row ) {
				printf( '<tr><td>%1$s</td><td>%2$s</td></tr>', esc_html( $row['name'] ), esc_html( number_format_i18n( $row['count'] ) ) );
			}
			echo '</tbody></table>';
		}
		echo '<p class="description">' . esc_html__( 'Statistics are counters kept independently of the records, so deleting a record does not reduce them. Revenue is an estimate: it only counts orders that contain products from an imported shared cart and have been paid (processing or completed). Importing a cart is never counted as a purchase.', 'wc-share-cart' ) . '</p>';
	}

	/**
	 * Settings tab.
	 */
	private function render_settings() {
		settings_errors( WCSC_Settings::OPTION );
		echo '<form method="post" action="options.php">';
		settings_fields( WCSC_Settings::GROUP );
		do_settings_sections( WCSC_Settings::PAGE );
		submit_button();
		echo '</form>';
	}

	/**
	 * Shared carts tab: list or details.
	 */
	private function render_carts() {
		if ( isset( $_GET['wcsc_view'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$this->render_cart_details( absint( $_GET['wcsc_view'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}
		require_once WCSC_PATH . 'admin/class-wcsc-shared-carts-list.php';

		$table = new WCSC_Shared_Carts_List();
		$table->prepare_items();

		echo '<p class="description">' . esc_html__( 'Share links are stored as one-way hashes, so a link cannot be recovered for existing records. The URL is only shown to the customer at the moment it is created.', 'wc-share-cart' ) . '</p>';
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" /><input type="hidden" name="tab" value="carts" />';
		$table->search_box( __( 'Search carts', 'wc-share-cart' ), 'wcsc-search' );
		echo '</form>';
		echo '<form method="post" id="wcsc-carts-form" action="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=carts' ) ) . '">';
		// Needed because the Filter button submits this form with GET, which drops the action URL's query string.
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" /><input type="hidden" name="tab" value="carts" />';
		$table->display();
		echo '</form>';
	}

	/**
	 * Details of one record.
	 *
	 * @param int $id Record id.
	 */
	private function render_cart_details( $id ) {
		$row  = WCSC_Share_Links::get( $id );
		$back = admin_url( 'admin.php?page=' . self::PAGE . '&tab=carts' );

		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to shared carts', 'wc-share-cart' ) . '</a></p>';

		if ( ! $row ) {
			echo '<p>' . esc_html__( 'This record no longer exists.', 'wc-share-cart' ) . '</p>';
			return;
		}

		$status = WCSC_Share_Links::effective_status( $row );
		echo '<h2>' . esc_html( sprintf( /* translators: %d: id */ __( 'Shared cart #%d', 'wc-share-cart' ), $row->id ) ) . '</h2>';
		echo '<table class="widefat striped wcsc-details"><tbody>';
		$meta = array(
			__( 'Status', 'wc-share-cart' )         => ucfirst( $status ),
			__( 'Created (site time)', 'wc-share-cart' ) => WCSC_Share_Links::format_date( $row->created_at ),
			__( 'Expires (site time)', 'wc-share-cart' ) => WCSC_Share_Links::format_date( $row->expires_at ),
			__( 'Owner', 'wc-share-cart' )          => $row->user_id ? sprintf( /* translators: %d: user id */ __( 'Registered user #%d', 'wc-share-cart' ), $row->user_id ) : __( 'Guest', 'wc-share-cart' ),
			__( 'Link opens', 'wc-share-cart' )     => (int) $row->open_count,
			__( 'Last opened', 'wc-share-cart' )    => WCSC_Share_Links::format_date( $row->last_opened_at ),
			__( 'Successful imports', 'wc-share-cart' ) => (int) $row->import_count,
			__( 'Last imported', 'wc-share-cart' )  => WCSC_Share_Links::format_date( $row->last_imported_at ),
		);
		foreach ( $meta as $label => $value ) {
			printf( '<tr><th scope="row">%1$s</th><td>%2$s</td></tr>', esc_html( $label ), esc_html( (string) $value ) );
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Items (snapshot at sharing time)', 'wc-share-cart' ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Product', 'wc-share-cart' ) . '</th><th>' . esc_html__( 'ID / Variation', 'wc-share-cart' ) . '</th><th>' . esc_html__( 'Type', 'wc-share-cart' ) . '</th><th>' . esc_html__( 'Selections', 'wc-share-cart' ) . '</th><th>' . esc_html__( 'Qty', 'wc-share-cart' ) . '</th></tr></thead><tbody>';
		foreach ( WCSC_Share_Links::get_items( $row ) as $item ) {
			$selections = array();
			foreach ( (array) ( isset( $item['variation'] ) ? $item['variation'] : array() ) as $k => $v ) {
				$selections[] = str_replace( 'attribute_', '', $k ) . ': ' . $v;
			}
			if ( ! empty( $item['options'] ) ) {
				$selections[] = wp_json_encode( $item['options'] );
			}
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
				esc_html( isset( $item['name'] ) ? $item['name'] : '' ),
				esc_html( ( isset( $item['product_id'] ) ? $item['product_id'] : '' ) . ( ! empty( $item['variation_id'] ) ? ' / ' . $item['variation_id'] : '' ) ),
				esc_html( isset( $item['type'] ) ? $item['type'] : '' ),
				esc_html( implode( ', ', $selections ) ),
				esc_html( isset( $item['quantity'] ) ? $item['quantity'] : '' )
			);
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Prices are intentionally not stored: they are recalculated from the live catalogue whenever a shared cart is previewed or imported.', 'wc-share-cart' ) . '</p>';
	}
}

<?php
/**
 * Frontend: Share Cart button, modal, shared cart preview page and import handling.
 *
 * @package WC_Share_Cart
 */

defined( 'ABSPATH' ) || exit;

/**
 * Public controller.
 */
class WCSC_Public {

	/**
	 * State of the shared cart page for the current request.
	 *
	 * @var array|null
	 */
	private static $view = null;

	/**
	 * Whether the share scripts/modal were requested during this request.
	 *
	 * @var bool
	 */
	private static $share_assets = false;

	/**
	 * Whether the modal markup has been printed.
	 *
	 * @var bool
	 */
	private static $modal_printed = false;

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		// Priority 15: after normal footer output, before the footer scripts are printed (20).
		add_action( 'wp_footer', array( $this, 'render_modal' ), 15 );
		add_action( 'template_redirect', array( $this, 'maybe_handle_shared_cart' ), 5 );
		add_shortcode( 'wcsc_shared_cart_page', array( $this, 'shortcode' ) );
		add_shortcode( 'wcsc_share_button', array( $this, 'share_button_shortcode' ) );
		add_action( 'wp', array( $this, 'hook_classic_button' ) );
	}

	/**
	 * Whether the Share Cart feature is available to the current visitor.
	 *
	 * @return bool
	 */
	public static function sharing_available() {
		if ( ! WCSC_Settings::get_bool( 'enabled' ) || ! WCSC_Settings::get_bool( 'show_button' ) ) {
			return false;
		}
		return is_user_logged_in() ? WCSC_Settings::get_bool( 'allow_logged_in' ) : WCSC_Settings::get_bool( 'allow_guests' );
	}

	/**
	 * Locate a template (themes may override in wc-share-cart/) and include it.
	 *
	 * @param string $name Template file name.
	 * @param array  $args Variables.
	 */
	public static function get_template( $name, array $args = array() ) {
		$file = locate_template( array( 'wc-share-cart/' . $name ) );
		if ( ! $file ) {
			$file = WCSC_PATH . 'templates/' . $name;
		}
		$file = apply_filters( 'wcsc_template_file', $file, $name, $args );
		if ( is_readable( $file ) ) {
			extract( $args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
			include $file;
		}
	}

	/**
	 * Hook the button for the classic cart (the Cart Block is handled in JS, see WCSC_Cart_Block).
	 */
	public function hook_classic_button() {
		if ( ! is_cart() || ! self::sharing_available() ) {
			return;
		}
		if ( 'none' === (string) WCSC_Settings::get( 'classic_placement' ) ) {
			return; // The store owner places the button with the shortcode.
		}

		$map = array(
			'before_cart_table'      => array( 'woocommerce_before_cart_table', 10 ),
			'after_cart_table'       => array( 'woocommerce_after_cart_table', 10 ),
			'cart_actions'           => array( 'woocommerce_cart_actions', 50 ),
			'before_checkout_button' => array( 'woocommerce_proceed_to_checkout', 15 ),
			'after_checkout_button'  => array( 'woocommerce_proceed_to_checkout', 30 ),
		);
		$key = (string) WCSC_Settings::get( 'classic_placement' );
		if ( ! isset( $map[ $key ] ) ) {
			$key = 'after_cart_table';
		}
		add_action( $map[ $key ][0], array( $this, 'render_classic_button' ), $map[ $key ][1] );
	}

	/**
	 * Inline style carrying the button design tokens.
	 *
	 * @return string
	 */
	public static function button_style() {
		return sprintf(
			'--wcsc-btn-bg:%1$s;--wcsc-btn-color:%2$s;--wcsc-btn-radius:%3$dpx;',
			sanitize_hex_color( (string) WCSC_Settings::get( 'button_bg' ) ) ?: '#2563eb',
			sanitize_hex_color( (string) WCSC_Settings::get( 'button_color' ) ) ?: '#ffffff',
			(int) WCSC_Settings::get( 'button_radius' )
		);
	}

	/**
	 * Classic cart button.
	 */
	public function render_classic_button() {
		static $rendered = false;
		if ( $rendered ) {
			return;
		}
		$rendered = true;

		echo self::button_html( array( 'extra_class' => 'wcsc-share-btn--classic' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in button_html().
	}

	/**
	 * Markup of one Share Cart button. Every value is escaped here.
	 *
	 * @param array $args { label, class, extra_class, icon (bool), full (bool) }.
	 * @return string
	 */
	public static function button_html( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'label'       => (string) WCSC_Settings::get( 'button_label' ),
				'class'       => '',
				'extra_class' => '',
				'icon'        => true,
				'full'        => false,
			)
		);

		$label = trim( (string) $args['label'] );
		if ( '' === $label ) {
			$label = (string) WCSC_Settings::get( 'button_label' );
		}

		$class_list = array( 'wcsc-share-btn' );
		foreach ( preg_split( '/\s+/', trim( $args['extra_class'] . ' ' . (string) WCSC_Settings::get( 'button_class' ) . ' ' . $args['class'] ) ) as $c ) {
			$c = sanitize_html_class( $c );
			if ( '' !== $c ) {
				$class_list[] = $c;
			}
		}

		$icon = $args['icon'] ? '<svg class="wcsc-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81a3 3 0 1 0-3-3c0 .24.04.47.09.7L8.04 9.81A2.99 2.99 0 0 0 6 9a3 3 0 1 0 0 6c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65a2.92 2.92 0 1 0 2.92-2.92z"/></svg>' : '';

		return sprintf(
			'<div class="wcsc-share-wrap%1$s"><button type="button" class="%2$s" style="%3$s" aria-haspopup="dialog" aria-controls="wcsc-modal">%4$s<span>%5$s</span></button></div>',
			$args['full'] ? ' wcsc-share-wrap--full' : '',
			esc_attr( implode( ' ', array_unique( $class_list ) ) ),
			esc_attr( self::button_style() ),
			$icon,
			esc_html( $label )
		);
	}

	/**
	 * [wcsc_share_button] shortcode: the Share Cart button wherever the store owner wants it.
	 *
	 * @param array|string $atts Attributes: label, class, icon, width.
	 * @return string
	 */
	public function share_button_shortcode( $atts ) {
		if ( ! self::sharing_available() ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'label' => '',
				'class' => '',
				'icon'  => 'yes',
				'width' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'wcsc_share_button'
		);

		// Make sure the scripts and the modal are available on this page (idempotent).
		self::load_share_assets();

		return self::button_html(
			array(
				'label'       => sanitize_text_field( $atts['label'] ),
				'class'       => (string) $atts['class'],
				'extra_class' => 'wcsc-share-btn--shortcode',
				'icon'        => ! in_array( strtolower( (string) $atts['icon'] ), array( 'no', 'false', '0', 'off' ), true ),
				'full'        => 'full' === strtolower( (string) $atts['width'] ),
			)
		);
	}

	/**
	 * Frontend assets: the cart page and the shared cart page always; every page when the store owner
	 * enabled "load on every page" (cart drawers). The shortcode loads them on demand elsewhere.
	 */
	public function enqueue() {
		$on_cart   = function_exists( 'is_cart' ) && is_cart();
		$on_shared = null !== self::$view;

		if ( $on_cart || $on_shared ) {
			wp_enqueue_style( 'wcsc-public', WCSC_URL . 'assets/css/public.css', array(), WCSC_VERSION );
		}

		if ( ! self::sharing_available() ) {
			return;
		}
		if ( $on_cart || ( WCSC_Settings::get_bool( 'load_everywhere' ) && null === self::$view ) ) {
			self::load_share_assets();
		}
	}

	/**
	 * Enqueue the share script, styles and data, and request the modal. Safe to call more than once and
	 * late (for example from a shortcode): footer scripts and late styles are still printed.
	 */
	public static function load_share_assets() {
		if ( self::$share_assets ) {
			return;
		}
		self::$share_assets = true;

		wp_enqueue_style( 'wcsc-public', WCSC_URL . 'assets/css/public.css', array(), WCSC_VERSION );

		$deps = array();
		if ( WCSC_Settings::get_bool( 'opt_qr' ) ) {
			wp_enqueue_script( 'wcsc-qr', WCSC_URL . 'assets/js/qr-code.js', array(), WCSC_VERSION, true );
			$deps[] = 'wcsc-qr';
		}
		wp_enqueue_script( 'wcsc-public', WCSC_URL . 'assets/js/public.js', $deps, WCSC_VERSION, true );

		wp_localize_script(
			'wcsc-public',
			'wcscData',
			array(
				'shareUrl'   => esc_url_raw( rest_url( 'wcsc/v1/share' ) ),
				'revokeUrl'  => esc_url_raw( rest_url( 'wcsc/v1/revoke' ) ),
				'noncesUrl'  => esc_url_raw( rest_url( 'wcsc/v1/nonces' ) ),
				'restNonce'  => wp_create_nonce( 'wp_rest' ),
				'nonce'      => WCSC_Security::create_nonce(),
				'options'    => array(
					'copy'       => WCSC_Settings::get_bool( 'opt_copy' ),
					'whatsapp'   => WCSC_Settings::get_bool( 'opt_whatsapp' ),
					'email'      => WCSC_Settings::get_bool( 'opt_email' ),
					'qr'         => WCSC_Settings::get_bool( 'opt_qr' ),
					'qrDownload' => WCSC_Settings::get_bool( 'qr_download' ),
				),
				'button'     => array(
					'label'     => (string) WCSC_Settings::get( 'button_label' ),
					'class'     => (string) WCSC_Settings::get( 'button_class' ),
					'style'     => self::button_style(),
					'placement' => (string) WCSC_Settings::get( 'block_placement' ),
				),
				'i18n'       => array(
					'creating'        => __( 'Creating your link…', 'wc-share-cart' ),
					'copied'          => __( 'Link Copied!', 'wc-share-cart' ),
					'copyLink'        => __( 'Copy Link', 'wc-share-cart' ),
					'copyFailed'      => __( 'Could not copy automatically. Please select the link and copy it.', 'wc-share-cart' ),
					'genericError'    => __( 'Something went wrong. Please check your connection and try again.', 'wc-share-cart' ),
					'unsupportedHead' => __( 'Some items cannot be shared and would be left out:', 'wc-share-cart' ),
					'shareSupported'  => __( 'Share the other items', 'wc-share-cart' ),
					'excludedNote'    => __( 'Left out of this link:', 'wc-share-cart' ),
					'revokeConfirm'   => __( 'Revoke this link? Anyone who has it will no longer be able to open it.', 'wc-share-cart' ),
					'revoked'         => __( 'This link has been revoked.', 'wc-share-cart' ),
					'qrAlt'           => __( 'QR code for your shared cart link', 'wc-share-cart' ),
					'waText'          => __( 'Check out this shopping cart I shared with you:', 'wc-share-cart' ),
					'mailSubject'     => __( 'Check Out My Shopping Cart', 'wc-share-cart' ),
					'mailBody'        => __( 'I wanted to share these products with you. View the cart here:', 'wc-share-cart' ),
				),
			)
		);
	}

	/**
	 * Print the (hidden) modal markup in the footer, on every page that loaded the share scripts.
	 */
	public function render_modal() {
		if ( self::$modal_printed || ! self::$share_assets || ! self::sharing_available() ) {
			return;
		}
		self::$modal_printed = true;
		self::get_template(
			'share-modal.php',
			array(
				'heading' => (string) WCSC_Settings::get( 'msg_modal_heading' ),
				'desc'    => (string) WCSC_Settings::get( 'msg_modal_desc' ),
				'opts'    => array(
					'copy'       => WCSC_Settings::get_bool( 'opt_copy' ),
					'whatsapp'   => WCSC_Settings::get_bool( 'opt_whatsapp' ),
					'email'      => WCSC_Settings::get_bool( 'opt_email' ),
					'qr'         => WCSC_Settings::get_bool( 'opt_qr' ),
					'qrDownload' => WCSC_Settings::get_bool( 'qr_download' ),
				),
			)
		);
	}

	/*
	 * ------------------------------------------------------------------
	 * Shared cart page (recipient).
	 * ------------------------------------------------------------------
	 */

	/**
	 * Detect a shared cart request, handle the import POST, or prepare the preview.
	 */
	public function maybe_handle_shared_cart() {
		$raw = get_query_var( 'wcsc_token', '' );
		if ( '' === $raw || ! is_string( $raw ) ) {
			return;
		}

		// A bearer link must never be cached publicly, indexed, or leaked through the Referer header.
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Referrer-Policy: no-referrer', true );
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		$token  = sanitize_text_field( wp_unslash( $raw ) );
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		$state = 'ok';
		$row   = null;

		if ( ! WCSC_Settings::get_bool( 'enabled' ) || ! WCSC_Settings::get_bool( 'enable_preview' ) ) {
			$state = 'disabled';
		} else {
			$row = WCSC_Share_Links::get_by_token( $token );
			if ( ! $row ) {
				$state = 'invalid';
			} else {
				$state = WCSC_Share_Links::effective_status( $row ); // active|expired|revoked.
			}
		}

		// Import: POST only, nonce protected, rate limited.
		if ( 'POST' === $method && isset( $_POST['wcsc_import'] ) && $row ) {
			$this->handle_import( $row, $state, $token );
			return;
		}

		$view = array(
			'state'   => $state,
			'row'     => $row,
			'token'   => $token,
			'preview' => null,
		);

		if ( $row && 'active' === $state ) {
			$view['preview'] = WCSC_Cart_Manager::build_preview( WCSC_Share_Links::get_items( $row ) );
			// Count real views only (not HEAD requests or browser prefetches).
			$purpose = isset( $_SERVER['HTTP_PURPOSE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_PURPOSE'] ) ) ) : '';
			if ( 'GET' === $method && 'prefetch' !== $purpose ) {
				WCSC_Share_Links::record_open( $row );
			}
		}

		self::$view = $view;

		$status = array(
			'invalid'  => 404,
			'expired'  => 410,
			'revoked'  => 410,
			'disabled' => 200,
			'active'   => 200,
		);
		$code   = isset( $status[ $state ] ) ? $status[ $state ] : 200;

		global $wp_query;
		$wp_query->is_404       = false;
		$wp_query->is_home      = false;
		$wp_query->is_front_page = false;
		$wp_query->is_archive   = false;
		status_header( $code );

		add_filter( 'template_include', array( $this, 'template_include' ), 99 );
		add_filter( 'document_title_parts', array( $this, 'document_title' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_filter( 'pre_get_document_title', array( $this, 'pre_title' ) );
	}

	/**
	 * Process the import form.
	 *
	 * @param object $row   Record.
	 * @param string $state Effective status.
	 * @param string $token Raw token.
	 */
	private function handle_import( $row, $state, $token ) {
		$back = WCSC_Share_Links::get_url( $token );

		// Make sure WooCommerce notices can be stored for guests.
		WCSC_Cart_Manager::ensure_cart();

		if ( ! isset( $_POST['wcsc_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcsc_nonce'] ) ), 'wcsc_import' ) ) {
			wc_add_notice( __( 'Your session expired. Please try again.', 'wc-share-cart' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}

		if ( ! WCSC_Security::allow( 'import_ip', WCSC_Security::client_ip(), (int) apply_filters( 'wcsc_import_rate_limit', 60 ), HOUR_IN_SECONDS ) ) {
			wc_add_notice( __( 'Too many requests. Please try again later.', 'wc-share-cart' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}

		if ( 'active' !== $state ) {
			wp_safe_redirect( $back );
			exit;
		}

		$mode = isset( $_POST['wcsc_mode'] ) ? sanitize_key( wp_unslash( $_POST['wcsc_mode'] ) ) : '';
		if ( ( 'merge' === $mode && ! WCSC_Settings::get_bool( 'enable_merge' ) ) || ( 'replace' === $mode && ! WCSC_Settings::get_bool( 'enable_replace' ) ) ) {
			$mode = '';
		}

		$result = WCSC_Cart_Manager::import( WCSC_Share_Links::get_items( $row ), $mode );

		if ( is_wp_error( $result ) ) {
			$skipped = $result->get_error_data( 'wcsc_nothing_to_import' );
			wc_add_notice( $result->get_error_message(), 'error' );
			if ( is_array( $skipped ) && ! empty( $skipped['skipped'] ) ) {
				foreach ( $skipped['skipped'] as $s ) {
					wc_add_notice( sprintf( /* translators: 1: product name, 2: reason */ __( '%1$s: %2$s', 'wc-share-cart' ), esc_html( $s['name'] ), esc_html( $s['reason'] ) ), 'notice' );
				}
			}
			wp_safe_redirect( $back );
			exit;
		}

		WCSC_Share_Links::record_import( $row, $mode );
		if ( WC()->session ) {
			WC()->session->set( WCSC_Analytics::SESSION_KEY, (int) $row->id );
		}

		wc_add_notice( esc_html( (string) WCSC_Settings::get( 'msg_imported' ) ), 'success' );
		foreach ( $result['skipped'] as $s ) {
			wc_add_notice( sprintf( /* translators: 1: product name, 2: reason */ __( '%1$s could not be added: %2$s', 'wc-share-cart' ), esc_html( $s['name'] ), esc_html( $s['reason'] ) ), 'notice' );
		}
		foreach ( $result['dropped_coupons'] as $code ) {
			wc_add_notice( sprintf( /* translators: %s: coupon code */ __( 'The coupon "%s" is no longer valid for the new cart and was removed.', 'wc-share-cart' ), esc_html( $code ) ), 'notice' );
		}

		// Always a same-site destination (no open redirect).
		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}

	/**
	 * Use a theme-aware template for the shared cart page.
	 *
	 * @return string
	 */
	public function template_include() {
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			global $_wp_current_template_content, $_wp_current_template_id;
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$_wp_current_template_id      = 'wc-share-cart//shared-cart';
			$_wp_current_template_content = '<!-- wp:template-part {"slug":"header","tagName":"header"} /--><!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} --><main class="wp-block-group"><!-- wp:shortcode -->[wcsc_shared_cart_page]<!-- /wp:shortcode --></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';
			return ABSPATH . WPINC . '/template-canvas.php';
		}
		return WCSC_PATH . 'templates/shared-cart-page.php';
	}

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public function pre_title() {
		return $this->title_text() . ' – ' . get_bloginfo( 'name' );
	}

	/**
	 * Title parts.
	 *
	 * @param array $parts Parts.
	 * @return array
	 */
	public function document_title( $parts ) {
		$parts['title'] = $this->title_text();
		return $parts;
	}

	/**
	 * Title text.
	 *
	 * @return string
	 */
	private function title_text() {
		return wp_strip_all_tags( (string) WCSC_Settings::get( 'msg_recipient_heading' ) );
	}

	/**
	 * Body class.
	 *
	 * @param array $classes Classes.
	 * @return array
	 */
	public function body_class( $classes ) {
		$classes[] = 'wcsc-shared-cart-page';
		return $classes;
	}

	/**
	 * Shortcode: renders the shared cart view prepared for this request.
	 *
	 * @return string
	 */
	public function shortcode() {
		if ( null === self::$view ) {
			return '';
		}
		ob_start();
		self::get_template( 'shared-cart-preview.php', array( 'view' => self::$view ) );
		return (string) ob_get_clean();
	}

	/**
	 * Current view (for the plain template).
	 *
	 * @return array|null
	 */
	public static function get_view() {
		return self::$view;
	}
}

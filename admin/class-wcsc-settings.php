<?php
/**
 * Settings schema, access, sanitization and Settings API rendering.
 *
 * @package WC_Share_Cart
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings.
 */
class WCSC_Settings {

	const OPTION = 'wcsc_settings';
	const GROUP  = 'wcsc_settings_group';
	const PAGE   = 'wc-share-cart-settings';

	/**
	 * Cached merged values.
	 *
	 * @var array|null
	 */
	private static $values = null;

	/**
	 * Cached schema.
	 *
	 * @var array|null
	 */
	private static $schema = null;

	/**
	 * Section labels.
	 *
	 * @return array
	 */
	public static function sections() {
		return array(
			'general'  => __( 'General Settings', 'wc-share-cart' ),
			'share'    => __( 'Share Options', 'wc-share-cart' ),
			'links'    => __( 'Link Settings', 'wc-share-cart' ),
			'button'   => __( 'Button Settings', 'wc-share-cart' ),
			'shortcode' => __( 'Place the Button Anywhere (Shortcode)', 'wc-share-cart' ),
			'messages' => __( 'Custom Messages', 'wc-share-cart' ),
			'data'     => __( 'Account, Logging and Data', 'wc-share-cart' ),
		);
	}

	/**
	 * Field definitions. Also the single source of truth for defaults and sanitization.
	 *
	 * @return array
	 */
	public static function schema() {
		if ( null !== self::$schema ) {
			return self::$schema;
		}

		$classic = array(
			'before_cart_table'      => __( 'Above the cart table', 'wc-share-cart' ),
			'after_cart_table'       => __( 'Below the cart table', 'wc-share-cart' ),
			'cart_actions'           => __( 'In the cart actions row (next to Update cart)', 'wc-share-cart' ),
			'before_checkout_button' => __( 'Above the Proceed to checkout button', 'wc-share-cart' ),
			'after_checkout_button'  => __( 'Below the Proceed to checkout button', 'wc-share-cart' ),
			'none'                   => __( 'Do not add automatically (I will use the shortcode)', 'wc-share-cart' ),
		);

		$block = array(
			'above_checkout' => __( 'Above the Proceed to checkout button', 'wc-share-cart' ),
			'below_checkout' => __( 'Below the Proceed to checkout button', 'wc-share-cart' ),
			'cart_top'       => __( 'At the top of the cart', 'wc-share-cart' ),
			'none'           => __( 'Do not add automatically (I will use the shortcode)', 'wc-share-cart' ),
		);

		self::$schema = array(
			// General.
			'enabled'            => array( 'general', 'checkbox', 1, __( 'Enable plugin', 'wc-share-cart' ), __( 'Master switch. When off, links stop working and nothing is shown to customers.', 'wc-share-cart' ) ),
			'show_button'        => array( 'general', 'checkbox', 1, __( 'Show Share Cart button', 'wc-share-cart' ), '' ),
			'allow_guests'       => array( 'general', 'checkbox', 1, __( 'Allow guests to share', 'wc-share-cart' ), '' ),
			'allow_logged_in'    => array( 'general', 'checkbox', 1, __( 'Allow logged-in customers to share', 'wc-share-cart' ), '' ),
			'enable_preview'     => array( 'general', 'checkbox', 1, __( 'Enable shared cart preview page', 'wc-share-cart' ), '' ),
			'enable_merge'       => array( 'general', 'checkbox', 1, __( 'Enable "Merge With My Cart"', 'wc-share-cart' ), '' ),
			'enable_replace'     => array( 'general', 'checkbox', 1, __( 'Enable "Replace My Cart"', 'wc-share-cart' ), '' ),

			// Share options.
			'opt_copy'           => array( 'share', 'checkbox', 1, __( 'Enable Copy Link', 'wc-share-cart' ), '' ),
			'opt_whatsapp'       => array( 'share', 'checkbox', 1, __( 'Enable WhatsApp', 'wc-share-cart' ), '' ),
			'opt_email'          => array( 'share', 'checkbox', 1, __( 'Enable Email', 'wc-share-cart' ), __( 'Opens the customer\'s own email app. The site never sends the email.', 'wc-share-cart' ) ),
			'opt_qr'             => array( 'share', 'checkbox', 1, __( 'Enable QR Code', 'wc-share-cart' ), '' ),
			'qr_download'        => array( 'share', 'checkbox', 1, __( 'Allow QR Code download (PNG)', 'wc-share-cart' ), '' ),

			// Links.
			'default_expiry_days' => array( 'links', 'number', 7, __( 'Default link expiration (days)', 'wc-share-cart' ), '', 1, 365 ),
			'max_validity_days'   => array( 'links', 'number', 30, __( 'Maximum link validity (days)', 'wc-share-cart' ), __( 'The default expiration can never exceed this value.', 'wc-share-cart' ), 1, 365 ),
			'auto_cleanup'        => array( 'links', 'checkbox', 1, __( 'Automatic expired-link cleanup', 'wc-share-cart' ), __( 'Daily WP-Cron job. Active links are never deleted before they expire.', 'wc-share-cart' ) ),
			'cleanup_retention_days' => array( 'links', 'number', 30, __( 'Keep expired / revoked records for (days)', 'wc-share-cart' ), __( 'Counted from the expiration date. Used by the automatic cleanup.', 'wc-share-cart' ), 0, 365 ),
			'max_products'        => array( 'links', 'number', 50, __( 'Maximum products per shared cart', 'wc-share-cart' ), '', 1, 200 ),
			'rate_session_max'    => array( 'links', 'number', 10, __( 'Max links per customer/session', 'wc-share-cart' ), __( '0 disables this limit.', 'wc-share-cart' ), 0, 1000 ),
			'rate_session_period' => array( 'links', 'number', 60, __( 'Customer/session limit period (minutes)', 'wc-share-cart' ), '', 1, 1440 ),
			'rate_ip_max'         => array( 'links', 'number', 30, __( 'Max sharing requests per IP', 'wc-share-cart' ), __( '0 disables this limit.', 'wc-share-cart' ), 0, 10000 ),
			'rate_ip_period'      => array( 'links', 'number', 60, __( 'IP limit period (minutes)', 'wc-share-cart' ), '', 1, 1440 ),

			// Button.
			'button_label'        => array( 'button', 'text', __( 'Share Cart', 'wc-share-cart' ), __( 'Button label', 'wc-share-cart' ), '' ),
			'classic_placement'   => array( 'button', 'select', 'after_cart_table', __( 'Placement: classic cart', 'wc-share-cart' ), '', $classic ),
			'block_placement'     => array( 'button', 'select', 'below_checkout', __( 'Placement: Cart Block', 'wc-share-cart' ), __( 'Inserted with JavaScript because PHP hooks do not run inside blocks. If the anchor cannot be found, the button is shown below the block.', 'wc-share-cart' ), $block ),
			'button_bg'           => array( 'button', 'color', '#2563eb', __( 'Button background color', 'wc-share-cart' ), '' ),
			'button_color'        => array( 'button', 'color', '#ffffff', __( 'Button text color', 'wc-share-cart' ), '' ),
			'button_radius'       => array( 'button', 'number', 6, __( 'Button border radius (px)', 'wc-share-cart' ), '', 0, 50 ),
			'button_class'        => array( 'button', 'text', '', __( 'Custom CSS class', 'wc-share-cart' ), __( 'Optional. Space separated class names added to the button.', 'wc-share-cart' ) ),

			// Shortcode.
			'load_everywhere'     => array( 'shortcode', 'checkbox', 0, __( 'Load the Share Cart scripts on every page', 'wc-share-cart' ), __( 'Needed for cart drawers / mini-carts that are refreshed by AJAX or printed late in the page. Leave off if you only use the shortcode in normal page content: the scripts then load only on pages that contain the button.', 'wc-share-cart' ) ),

			// Messages.
			'msg_modal_heading'   => array( 'messages', 'text', __( 'Share Your Cart', 'wc-share-cart' ), __( 'Modal heading', 'wc-share-cart' ), '' ),
			'msg_modal_desc'      => array( 'messages', 'text', __( 'Share your selected products with anyone.', 'wc-share-cart' ), __( 'Modal description', 'wc-share-cart' ), '' ),
			'msg_recipient_heading' => array( 'messages', 'text', __( 'Someone Shared a Cart With You!', 'wc-share-cart' ), __( 'Recipient page heading', 'wc-share-cart' ), '' ),
			'msg_recipient_desc'  => array( 'messages', 'text', __( 'Review the selected products and add them to your shopping cart.', 'wc-share-cart' ), __( 'Recipient page description', 'wc-share-cart' ), '' ),
			'msg_invalid'         => array( 'messages', 'text', __( 'This shared cart link is not valid.', 'wc-share-cart' ), __( 'Invalid link message', 'wc-share-cart' ), '' ),
			'msg_expired'         => array( 'messages', 'text', __( 'This shared cart link has expired.', 'wc-share-cart' ), __( 'Expired link message', 'wc-share-cart' ), '' ),
			'msg_revoked'         => array( 'messages', 'text', __( 'This shared cart link has been revoked by its owner.', 'wc-share-cart' ), __( 'Revoked link message', 'wc-share-cart' ), '' ),
			'msg_imported'        => array( 'messages', 'text', __( 'The shared items have been added to your cart.', 'wc-share-cart' ), __( 'Successfully imported message', 'wc-share-cart' ), '' ),

			// Data.
			'account_enabled'     => array( 'data', 'checkbox', 1, __( 'Enable My Account → Shared Carts', 'wc-share-cart' ), __( 'Lets logged-in customers review and revoke their links.', 'wc-share-cart' ) ),
			'enable_logging'      => array( 'data', 'checkbox', 1, __( 'Log errors to WooCommerce → Status → Logs', 'wc-share-cart' ), __( 'Source: wc-share-cart. Tokens and personal data are never logged.', 'wc-share-cart' ) ),
			'delete_on_uninstall' => array( 'data', 'checkbox', 0, __( 'Delete ALL plugin data when the plugin is deleted', 'wc-share-cart' ), __( 'Removes the shared carts table, settings and statistics on uninstall. Deactivating never deletes data.', 'wc-share-cart' ) ),
		);

		return self::$schema;
	}

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		$out = array();
		foreach ( self::schema() as $key => $def ) {
			$out[ $key ] = $def[2];
		}
		return $out;
	}

	/**
	 * Merged, cached values.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$values ) {
			$saved        = get_option( self::OPTION, array() );
			self::$values = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$values;
	}

	/**
	 * Get a value.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	/**
	 * Get a boolean value.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function get_bool( $key ) {
		return (bool) self::get( $key );
	}

	/**
	 * Get an integer value.
	 *
	 * @param string $key Setting key.
	 * @return int
	 */
	public static function get_int( $key ) {
		return (int) self::get( $key );
	}

	/**
	 * Reset the cache (after update).
	 */
	public static function flush_cache() {
		self::$values = null;
	}

	/**
	 * Store defaults on first activation without overriding existing values.
	 */
	public static function ensure_defaults() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
		}
	}

	/**
	 * Register hooks for the Settings API.
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);

		foreach ( self::sections() as $id => $title ) {
			add_settings_section(
				'wcsc_' . $id,
				$title,
				'shortcode' === $id ? array( __CLASS__, 'render_shortcode_help' ) : '__return_false',
				self::PAGE
			);
		}

		foreach ( self::schema() as $key => $def ) {
			add_settings_field(
				'wcsc_' . $key,
				$def[3],
				array( __CLASS__, 'render_field' ),
				self::PAGE,
				'wcsc_' . $def[0],
				array(
					'key'       => $key,
					'label_for' => 'wcsc_' . $key,
				)
			);
		}
	}

	/**
	 * Sanitize the whole option array. Unchecked checkboxes are absent from the form and become 0.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$old   = self::all();
		$out   = array();

		foreach ( self::schema() as $key => $def ) {
			$type    = $def[1];
			$default = $def[2];
			$raw     = isset( $input[ $key ] ) ? wp_unslash( $input[ $key ] ) : null;
			$raw     = is_array( $raw ) ? null : $raw;

			switch ( $type ) {
				case 'checkbox':
					$out[ $key ] = ( null !== $raw && '' !== $raw && '0' !== $raw ) ? 1 : 0;
					break;

				case 'number':
					$min         = isset( $def[5] ) ? (int) $def[5] : 0;
					$max         = isset( $def[6] ) ? (int) $def[6] : PHP_INT_MAX;
					$val         = null === $raw ? (int) $default : (int) $raw;
					$out[ $key ] = max( $min, min( $max, $val ) );
					break;

				case 'color':
					$color       = null === $raw ? '' : sanitize_hex_color( (string) $raw );
					$out[ $key ] = $color ? $color : $default;
					break;

				case 'select':
					$choices     = isset( $def[5] ) ? $def[5] : array();
					$out[ $key ] = ( null !== $raw && isset( $choices[ $raw ] ) ) ? (string) $raw : $default;
					break;

				default: // text.
					if ( 'button_class' === $key ) {
						$classes     = preg_split( '/\s+/', trim( (string) $raw ) );
						$classes     = array_filter( array_map( 'sanitize_html_class', (array) $classes ) );
						$out[ $key ] = implode( ' ', $classes );
					} else {
						$text        = null === $raw ? '' : sanitize_text_field( (string) $raw );
						$out[ $key ] = ( '' === $text && 0 === strpos( $key, 'msg_' ) ) ? $default : $text;
						if ( 'button_label' === $key && '' === $out[ $key ] ) {
							$out[ $key ] = $default;
						}
					}
			}
		}

		if ( $out['default_expiry_days'] > $out['max_validity_days'] ) {
			$out['default_expiry_days'] = $out['max_validity_days'];
			add_settings_error( self::OPTION, 'wcsc_expiry', __( 'The default expiration was reduced to match the maximum link validity.', 'wc-share-cart' ), 'info' );
		}

		if ( ! $out['enable_merge'] && ! $out['enable_replace'] && $out['enable_preview'] ) {
			add_settings_error( self::OPTION, 'wcsc_modes', __( 'Both Merge and Replace are disabled: recipients can preview shared carts but cannot import them.', 'wc-share-cart' ), 'warning' );
		}

		// The My Account endpoint is only (re)registered after a flush: request exactly one when it changes.
		if ( (int) $old['account_enabled'] !== (int) $out['account_enabled'] ) {
			update_option( 'wcsc_needs_flush', 1, false );
		}

		return $out;
	}

	/**
	 * Instructions for the shortcode (printed at the top of its settings section).
	 */
	public static function render_shortcode_help() {
		$hook_example = "add_action( 'woocommerce_after_mini_cart', function () {\n\techo do_shortcode( '[wcsc_share_button]' );\n} );";
		$php_example  = "<?php echo do_shortcode( '[wcsc_share_button]' ); ?>";
		?>
		<div class="wcsc-help">
			<p><?php esc_html_e( 'Use this shortcode to put the Share Cart button wherever you want, instead of (or in addition to) the default position. Pick "Do not add automatically" in the placement settings above if you only want the shortcode.', 'wc-share-cart' ); ?></p>

			<p><input type="text" readonly class="large-text code wcsc-select-on-focus" value="[wcsc_share_button]" aria-label="<?php esc_attr_e( 'Shortcode', 'wc-share-cart' ); ?>" /></p>

			<h4><?php esc_html_e( 'Optional attributes', 'wc-share-cart' ); ?></h4>
			<table class="widefat striped wcsc-help-table">
				<thead><tr><th><?php esc_html_e( 'Attribute', 'wc-share-cart' ); ?></th><th><?php esc_html_e( 'What it does', 'wc-share-cart' ); ?></th><th><?php esc_html_e( 'Example', 'wc-share-cart' ); ?></th></tr></thead>
				<tbody>
					<tr><td><code>label</code></td><td><?php esc_html_e( 'Button text for this one button (defaults to the label above).', 'wc-share-cart' ); ?></td><td><code>[wcsc_share_button label="Send my cart"]</code></td></tr>
					<tr><td><code>class</code></td><td><?php esc_html_e( 'Extra CSS classes for this button.', 'wc-share-cart' ); ?></td><td><code>[wcsc_share_button class="my-btn"]</code></td></tr>
					<tr><td><code>icon</code></td><td><?php esc_html_e( 'Set to "no" to hide the share icon.', 'wc-share-cart' ); ?></td><td><code>[wcsc_share_button icon="no"]</code></td></tr>
					<tr><td><code>width</code></td><td><?php esc_html_e( 'Set to "full" to make the button as wide as its container (useful in drawers).', 'wc-share-cart' ); ?></td><td><code>[wcsc_share_button width="full"]</code></td></tr>
				</tbody>
			</table>

			<h4><?php esc_html_e( 'Where you can use it', 'wc-share-cart' ); ?></h4>
			<ul class="ul-disc">
				<li><?php esc_html_e( 'Pages, posts and widgets: paste it into a Shortcode block (or the classic editor).', 'wc-share-cart' ); ?></li>
				<li><?php esc_html_e( 'Page builders (Elementor, Divi, Bricks...): use their Shortcode widget.', 'wc-share-cart' ); ?></li>
				<li>
					<?php esc_html_e( 'Theme templates:', 'wc-share-cart' ); ?>
					<code><?php echo esc_html( $php_example ); ?></code>
				</li>
				<li>
					<?php esc_html_e( 'Cart drawer / mini-cart built on WooCommerce\'s standard mini-cart template: add this to your child theme\'s functions.php or a code snippets plugin:', 'wc-share-cart' ); ?>
					<pre class="wcsc-code"><?php echo esc_html( $hook_example ); ?></pre>
					<?php esc_html_e( 'Drawers from other plugins or themes usually offer their own hook or a custom-HTML/shortcode field. Use the shortcode there.', 'wc-share-cart' ); ?>
				</li>
			</ul>

			<h4><?php esc_html_e( 'Good to know', 'wc-share-cart' ); ?></h4>
			<ul class="ul-disc">
				<li><?php esc_html_e( 'The button honours the settings above: it is not shown when the plugin is off, when sharing is disabled for the visitor (guests / logged-in), or when the button is hidden.', 'wc-share-cart' ); ?></li>
				<li><?php esc_html_e( 'You can use the shortcode several times on one page. They all open the same Share Cart window.', 'wc-share-cart' ); ?></li>
				<li><?php esc_html_e( 'If the cart is empty, the window tells the customer to add products first.', 'wc-share-cart' ); ?></li>
				<li><?php esc_html_e( 'Drawers that reload their contents with AJAX, or that print their HTML late in the page, need the option "Load the Share Cart scripts on every page" below.', 'wc-share-cart' ); ?></li>
				<li><?php esc_html_e( 'Block-based mini-carts (the WooCommerce Mini-Cart block) do not run PHP hooks. If your WooCommerce version lets you edit the Mini-Cart template part in the Site Editor, add a Shortcode block there and turn on the option below.', 'wc-share-cart' ); ?></li>
				<li><?php esc_html_e( 'Page caching: the button itself is static, so it is safe to cache. The cart contents are always read live when the customer clicks it.', 'wc-share-cart' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render one field.
	 *
	 * @param array $args Field args.
	 */
	public static function render_field( $args ) {
		$key    = $args['key'];
		$schema = self::schema();
		if ( ! isset( $schema[ $key ] ) ) {
			return;
		}
		$def   = $schema[ $key ];
		$type  = $def[1];
		$value = self::get( $key );
		$name  = self::OPTION . '[' . $key . ']';
		$id    = 'wcsc_' . $key;

		switch ( $type ) {
			case 'checkbox':
				printf(
					'<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> %4$s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( 1, (int) $value, false ),
					esc_html( $def[3] )
				);
				break;

			case 'number':
				printf(
					'<input type="number" class="small-text" id="%1$s" name="%2$s" value="%3$s" min="%4$d" max="%5$d" step="1" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value ),
					isset( $def[5] ) ? (int) $def[5] : 0,
					isset( $def[6] ) ? (int) $def[6] : 99999
				);
				break;

			case 'color':
				printf(
					'<input type="text" class="wcsc-color-field" id="%1$s" name="%2$s" value="%3$s" data-default-color="%4$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value ),
					esc_attr( $def[2] )
				);
				break;

			case 'select':
				printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( $def[5] as $opt_value => $opt_label ) {
					printf(
						'<option value="%1$s" %2$s>%3$s</option>',
						esc_attr( $opt_value ),
						selected( $value, $opt_value, false ),
						esc_html( $opt_label )
					);
				}
				echo '</select>';
				break;

			default:
				printf(
					'<input type="text" class="regular-text" id="%1$s" name="%2$s" value="%3$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value )
				);
		}

		if ( ! empty( $def[4] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $def[4] ) );
		}
	}
}

add_action( 'update_option_' . WCSC_Settings::OPTION, array( 'WCSC_Settings', 'flush_cache' ) );
add_action( 'add_option_' . WCSC_Settings::OPTION, array( 'WCSC_Settings', 'flush_cache' ) );

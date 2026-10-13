<?php
/**
 * Admin list table of shared carts.
 *
 * @package WC_Share_Cart
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared carts list table.
 */
class WCSC_Shared_Carts_List extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'wcsc_cart',
				'plural'   => 'wcsc_carts',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'            => '<input type="checkbox" />',
			'id'            => __( 'Cart ID', 'wc-share-cart' ),
			'created_at'    => __( 'Created', 'wc-share-cart' ),
			'expires_at'    => __( 'Expires', 'wc-share-cart' ),
			'product_count' => __( 'Products', 'wc-share-cart' ),
			'total_qty'     => __( 'Total qty', 'wc-share-cart' ),
			'status'        => __( 'Status', 'wc-share-cart' ),
			'open_count'    => __( 'Opens', 'wc-share-cart' ),
			'import_count'  => __( 'Imports', 'wc-share-cart' ),
			'actions'       => __( 'Actions', 'wc-share-cart' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'id'            => array( 'id', true ),
			'created_at'    => array( 'created_at', true ),
			'expires_at'    => array( 'expires_at', false ),
			'product_count' => array( 'product_count', false ),
			'total_qty'     => array( 'total_qty', false ),
			'open_count'    => array( 'open_count', false ),
			'import_count'  => array( 'import_count', false ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'bulk_revoke' => __( 'Revoke', 'wc-share-cart' ),
			'bulk_delete' => __( 'Delete', 'wc-share-cart' ),
		);
	}

	/**
	 * Checkbox column.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return '<input type="checkbox" name="cart[]" value="' . esc_attr( $item->id ) . '" />';
	}

	/**
	 * Default column renderer.
	 *
	 * @param object $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'id':
				return '#' . (int) $item->id;
			case 'created_at':
			case 'expires_at':
				return esc_html( WCSC_Share_Links::format_date( $item->$column_name ) );
			case 'product_count':
			case 'total_qty':
			case 'open_count':
			case 'import_count':
				return esc_html( number_format_i18n( (int) $item->$column_name ) );
			case 'status':
				$status = WCSC_Share_Links::effective_status( $item );
				return '<span class="wcsc-status wcsc-status--' . esc_attr( $status ) . '">' . esc_html( ucfirst( $status ) ) . '</span>';
			case 'actions':
				return $this->row_actions_html( $item );
		}
		return '';
	}

	/**
	 * Action links. "Copy link" is intentionally absent: only a hash of the token is stored.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	private function row_actions_html( $item ) {
		$base   = admin_url( 'admin.php?page=' . WCSC_Admin::PAGE . '&tab=carts' );
		$status = WCSC_Share_Links::effective_status( $item );
		$id     = (int) $item->id;

		$links = array(
			'<a href="' . esc_url( add_query_arg( 'wcsc_view', $id, $base ) ) . '">' . esc_html__( 'View details', 'wc-share-cart' ) . '</a>',
		);

		if ( 'revoked' !== $status ) {
			$url     = wp_nonce_url( add_query_arg( array( 'wcsc_action' => 'revoke', 'cart_id' => $id ), $base ), 'wcsc_revoke_' . $id );
			$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Revoke', 'wc-share-cart' ) . '</a>';
		}

		$url     = wp_nonce_url( add_query_arg( array( 'wcsc_action' => 'delete', 'cart_id' => $id ), $base ), 'wcsc_delete_' . $id );
		$links[] = '<a href="' . esc_url( $url ) . '" class="submitdelete wcsc-confirm-delete">' . esc_html__( 'Delete', 'wc-share-cart' ) . '</a>';

		return implode( ' | ', $links );
	}

	/**
	 * Filters above the table.
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$status    = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '';
		$date_from = isset( $_REQUEST['date_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_from'] ) ) : '';
		$date_to   = isset( $_REQUEST['date_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_to'] ) ) : '';
		// phpcs:enable

		echo '<div class="alignleft actions wcsc-filters">';
		echo '<label class="screen-reader-text" for="wcsc-status">' . esc_html__( 'Filter by status', 'wc-share-cart' ) . '</label>';
		echo '<select name="status" id="wcsc-status"><option value="">' . esc_html__( 'All statuses', 'wc-share-cart' ) . '</option>';
		foreach ( array(
			'active'  => __( 'Active', 'wc-share-cart' ),
			'expired' => __( 'Expired', 'wc-share-cart' ),
			'revoked' => __( 'Revoked', 'wc-share-cart' ),
		) as $value => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $status, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
		echo '<label for="wcsc-date-from">' . esc_html__( 'From', 'wc-share-cart' ) . '</label> <input type="date" id="wcsc-date-from" name="date_from" value="' . esc_attr( $date_from ) . '" />';
		echo '<label for="wcsc-date-to">' . esc_html__( 'To', 'wc-share-cart' ) . '</label> <input type="date" id="wcsc-date-to" name="date_to" value="' . esc_attr( $date_to ) . '" />';
		submit_button( __( 'Filter', 'wc-share-cart' ), '', 'filter_action', false, array( 'formmethod' => 'get', 'formaction' => admin_url( 'admin.php?page=' . WCSC_Admin::PAGE . '&tab=carts' ) ) );
		echo '</div>';
	}

	/**
	 * Load rows.
	 */
	public function prepare_items() {
		// phpcs:disable WordPress.Security.NonceVerification
		$per_page = 20;
		$result   = WCSC_Share_Links::query(
			array(
				'status'    => isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '',
				'search'    => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
				'date_from' => isset( $_REQUEST['date_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_from'] ) ) : '',
				'date_to'   => isset( $_REQUEST['date_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_to'] ) ) : '',
				'orderby'   => isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'id',
				'order'     => isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'desc',
				'per_page'  => $per_page,
				'page'      => $this->get_pagenum(),
			)
		);
		// phpcs:enable

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'id' );
		$this->items           = $result['rows'];

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);
	}

	/**
	 * Empty state.
	 */
	public function no_items() {
		esc_html_e( 'No shared carts found.', 'wc-share-cart' );
	}
}

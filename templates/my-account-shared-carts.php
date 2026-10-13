<?php
/**
 * My Account → Shared Carts.
 *
 * Override in your theme: wc-share-cart/my-account-shared-carts.php
 *
 * @var object[] $rows  Records.
 * @var int      $total Total records.
 * @var int      $pages Number of pages.
 * @var int      $page  Current page.
 *
 * @package WC_Share_Cart
 */

defined( 'ABSPATH' ) || exit;

$base = wc_get_endpoint_url( WCSC_ACCOUNT_ENDPOINT, '', wc_get_page_permalink( 'myaccount' ) );
?>
<div class="wcsc-account">
	<p><?php esc_html_e( 'Carts you have shared. For your privacy, links are stored as one-way hashes, so an existing link cannot be shown again here. You can revoke a link at any time.', 'wc-share-cart' ); ?></p>

	<?php if ( empty( $rows ) ) : ?>
		<p class="woocommerce-info"><?php esc_html_e( 'You have not shared any carts yet.', 'wc-share-cart' ); ?></p>
	<?php else : ?>
		<table class="woocommerce-orders-table shop_table shop_table_responsive wcsc-account__table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Created', 'wc-share-cart' ); ?></th>
					<th><?php esc_html_e( 'Expires', 'wc-share-cart' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wc-share-cart' ); ?></th>
					<th><?php esc_html_e( 'Contents', 'wc-share-cart' ); ?></th>
					<th><?php esc_html_e( 'Opens / Imports', 'wc-share-cart' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'wc-share-cart' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php $status = WCSC_Share_Links::effective_status( $row ); ?>
					<tr>
						<td data-title="<?php esc_attr_e( 'Created', 'wc-share-cart' ); ?>"><?php echo esc_html( WCSC_Share_Links::format_date( $row->created_at ) ); ?></td>
						<td data-title="<?php esc_attr_e( 'Expires', 'wc-share-cart' ); ?>"><?php echo esc_html( WCSC_Share_Links::format_date( $row->expires_at ) ); ?></td>
						<td data-title="<?php esc_attr_e( 'Status', 'wc-share-cart' ); ?>"><span class="wcsc-status wcsc-status--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span></td>
						<td data-title="<?php esc_attr_e( 'Contents', 'wc-share-cart' ); ?>">
							<details>
								<summary>
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: number of products, 2: total quantity */
											_n( '%1$d product (%2$d items)', '%1$d products (%2$d items)', (int) $row->product_count, 'wc-share-cart' ),
											(int) $row->product_count,
											(int) $row->total_qty
										)
									);
									?>
								</summary>
								<ul class="wcsc-account__items">
									<?php foreach ( WCSC_Share_Links::get_items( $row ) as $item ) : ?>
										<li><?php echo esc_html( ( isset( $item['name'] ) ? $item['name'] : '#' . (int) $item['product_id'] ) . ' × ' . (int) $item['quantity'] ); ?></li>
									<?php endforeach; ?>
								</ul>
							</details>
						</td>
						<td data-title="<?php esc_attr_e( 'Opens / Imports', 'wc-share-cart' ); ?>"><?php echo esc_html( (int) $row->open_count . ' / ' . (int) $row->import_count ); ?></td>
						<td data-title="<?php esc_attr_e( 'Actions', 'wc-share-cart' ); ?>">
							<form method="post" class="wcsc-account__actions" action="<?php echo esc_url( $base ); ?>">
								<?php wp_nonce_field( 'wcsc_account', 'wcsc_account_nonce' ); ?>
								<input type="hidden" name="wcsc_cart_id" value="<?php echo esc_attr( (int) $row->id ); ?>" />
								<?php if ( 'revoked' !== $status && 'active' === $status ) : ?>
									<button type="submit" class="button" name="wcsc_account_action" value="revoke"><?php esc_html_e( 'Revoke', 'wc-share-cart' ); ?></button>
								<?php endif; ?>
								<button type="submit" class="button" name="wcsc_account_action" value="delete"><?php esc_html_e( 'Remove', 'wc-share-cart' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $pages > 1 ) : ?>
			<nav class="woocommerce-pagination wcsc-account__pagination" aria-label="<?php esc_attr_e( 'Pagination', 'wc-share-cart' ); ?>">
				<?php if ( $page > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $page - 1, $base ) ); ?>"><?php esc_html_e( 'Newer', 'wc-share-cart' ); ?></a>
				<?php endif; ?>
				<?php if ( $page < $pages ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $page + 1, $base ) ); ?>"><?php esc_html_e( 'Older', 'wc-share-cart' ); ?></a>
				<?php endif; ?>
			</nav>
		<?php endif; ?>

		<form method="post" class="wcsc-account__clear" action="<?php echo esc_url( $base ); ?>">
			<?php wp_nonce_field( 'wcsc_account', 'wcsc_account_nonce' ); ?>
			<button type="submit" class="button" name="wcsc_account_action" value="clear_history"><?php esc_html_e( 'Remove expired and revoked history', 'wc-share-cart' ); ?></button>
		</form>
	<?php endif; ?>
</div>

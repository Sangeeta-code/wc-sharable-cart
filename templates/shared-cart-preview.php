<?php
/**
 * Recipient view of a shared cart.
 *
 * Override in your theme: wc-share-cart/shared-cart-preview.php
 *
 * @var array $view { state, row, token, preview }
 *
 * @package WC_Share_Cart
 */

defined( 'ABSPATH' ) || exit;

$state    = $view['state'];
$preview  = $view['preview'];
$shop_url = wc_get_page_permalink( 'shop' );
$shop_url = $shop_url ? $shop_url : home_url( '/' );

$messages = array(
	'invalid'  => WCSC_Settings::get( 'msg_invalid' ),
	'expired'  => WCSC_Settings::get( 'msg_expired' ),
	'revoked'  => WCSC_Settings::get( 'msg_revoked' ),
	'disabled' => __( 'Shared cart previews are currently unavailable.', 'wc-share-cart' ),
);
?>
<div class="wcsc-preview">
	<?php if ( function_exists( 'wc_print_notices' ) ) : ?>
		<div class="wcsc-notices"><?php wc_print_notices(); ?></div>
	<?php endif; ?>

	<?php if ( 'active' !== $state || ! $preview ) : ?>
		<div class="wcsc-preview__message wcsc-preview__message--<?php echo esc_attr( $state ); ?>" role="alert">
			<h1 class="wcsc-preview__title"><?php echo esc_html( isset( $messages[ $state ] ) ? $messages[ $state ] : $messages['invalid'] ); ?></h1>
			<a class="wcsc-btn wcsc-btn--secondary" href="<?php echo esc_url( $shop_url ); ?>"><?php esc_html_e( 'Continue Shopping', 'wc-share-cart' ); ?></a>
		</div>
	<?php else : ?>
		<?php
		$enable_merge   = WCSC_Settings::get_bool( 'enable_merge' );
		$enable_replace = WCSC_Settings::get_bool( 'enable_replace' );
		$can_import     = ( $enable_merge || $enable_replace ) && $preview['importable'] > 0;
		$default_mode   = $enable_merge ? 'merge' : 'replace';
		$expires_ts     = WCSC_Share_Links::to_timestamp( $view['row']->expires_at );
		?>
		<header class="wcsc-preview__header">
			<h1 class="wcsc-preview__title"><?php echo esc_html( (string) WCSC_Settings::get( 'msg_recipient_heading' ) ); ?></h1>
			<p class="wcsc-preview__desc"><?php echo esc_html( (string) WCSC_Settings::get( 'msg_recipient_desc' ) ); ?></p>
			<p class="wcsc-preview__expiry">
				<?php
				printf(
					/* translators: 1: relative time (e.g. "3 days"), 2: date */
					esc_html__( 'Link active – expires in %1$s (%2$s).', 'wc-share-cart' ),
					esc_html( human_time_diff( time(), $expires_ts ) ),
					esc_html( WCSC_Share_Links::format_date( $view['row']->expires_at ) )
				);
				?>
			</p>
		</header>

		<?php if ( $preview['issues'] > 0 ) : ?>
			<p class="wcsc-preview__warning" role="status">
				<?php esc_html_e( 'Some items are not available and will not be added. Nothing is substituted automatically.', 'wc-share-cart' ); ?>
			</p>
		<?php endif; ?>

		<ul class="wcsc-items">
			<?php foreach ( $preview['rows'] as $item ) : ?>
				<li class="wcsc-item <?php echo $item['ok'] ? '' : 'wcsc-item--unavailable'; ?>">
					<div class="wcsc-item__image"><?php echo wp_kses_post( $item['image'] ); ?></div>
					<div class="wcsc-item__details">
						<strong class="wcsc-item__name"><?php echo esc_html( $item['name'] ); ?></strong>
						<?php foreach ( $item['meta'] as $line ) : ?>
							<span class="wcsc-item__meta"><?php echo esc_html( $line ); ?></span>
						<?php endforeach; ?>
						<span class="wcsc-item__status wcsc-item__status--<?php echo esc_attr( $item['status'] ); ?>"><?php echo esc_html( $item['status_label'] ); ?></span>
					</div>
					<div class="wcsc-item__qty"><span class="wcsc-item__label"><?php esc_html_e( 'Qty', 'wc-share-cart' ); ?></span> <?php echo esc_html( (string) $item['quantity'] ); ?></div>
					<div class="wcsc-item__price"><span class="wcsc-item__label"><?php esc_html_e( 'Price', 'wc-share-cart' ); ?></span> <?php echo wp_kses_post( $item['price_html'] ); ?></div>
					<div class="wcsc-item__subtotal"><span class="wcsc-item__label"><?php esc_html_e( 'Subtotal', 'wc-share-cart' ); ?></span> <?php echo wp_kses_post( $item['subtotal'] ); ?></div>
				</li>
			<?php endforeach; ?>
		</ul>

		<div class="wcsc-total">
			<span><?php esc_html_e( 'Estimated total', 'wc-share-cart' ); ?></span>
			<strong><?php echo wp_kses_post( $preview['total_html'] ); ?></strong>
		</div>
		<p class="wcsc-total__note"><?php esc_html_e( 'Prices come from the current catalogue. Final taxes, shipping and discounts are calculated in your cart and at checkout.', 'wc-share-cart' ); ?></p>

		<form class="wcsc-import" method="post" action="<?php echo esc_url( WCSC_Share_Links::get_url( $view['token'] ) ); ?>">
			<?php if ( $can_import ) : ?>
				<fieldset class="wcsc-modes">
					<legend><?php esc_html_e( 'How should the shared items be added?', 'wc-share-cart' ); ?></legend>
					<?php if ( $enable_merge ) : ?>
						<label class="wcsc-mode">
							<input type="radio" name="wcsc_mode" value="merge" <?php checked( 'merge', $default_mode ); ?> />
							<span>
								<strong><?php esc_html_e( 'Merge With My Cart', 'wc-share-cart' ); ?></strong>
								<small><?php esc_html_e( 'Keep what is already in your cart and add the shared products.', 'wc-share-cart' ); ?></small>
							</span>
						</label>
					<?php endif; ?>
					<?php if ( $enable_replace ) : ?>
						<label class="wcsc-mode">
							<input type="radio" name="wcsc_mode" value="replace" <?php checked( 'replace', $default_mode ); ?> />
							<span>
								<strong><?php esc_html_e( 'Replace My Cart', 'wc-share-cart' ); ?></strong>
								<small><?php esc_html_e( 'Remove your current items and load the shared products instead.', 'wc-share-cart' ); ?></small>
							</span>
						</label>
					<?php endif; ?>
				</fieldset>

				<?php wp_nonce_field( 'wcsc_import', 'wcsc_nonce' ); ?>
				<input type="hidden" name="wcsc_import" value="1" />
			<?php elseif ( $enable_merge || $enable_replace ) : ?>
				<p class="wcsc-preview__warning" role="status"><?php esc_html_e( 'None of these items can be added right now.', 'wc-share-cart' ); ?></p>
			<?php endif; ?>

			<div class="wcsc-actions">
				<?php if ( $can_import ) : ?>
					<button type="submit" class="wcsc-btn wcsc-btn--primary"><?php esc_html_e( 'Add Shared Items to Cart', 'wc-share-cart' ); ?></button>
				<?php endif; ?>
				<a class="wcsc-btn wcsc-btn--secondary" href="<?php echo esc_url( $shop_url ); ?>"><?php esc_html_e( 'Continue Shopping', 'wc-share-cart' ); ?></a>
			</div>
		</form>
	<?php endif; ?>
</div>

<?php
/**
 * Share modal (hidden until the Share Cart button is clicked).
 *
 * Override in your theme: wc-share-cart/share-modal.php
 *
 * @var string $heading Modal heading.
 * @var string $desc    Supporting text.
 * @var array  $opts    Enabled share options.
 *
 * @package WC_Share_Cart
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wcsc-modal-overlay" id="wcsc-modal-overlay" hidden>
	<div class="wcsc-modal" id="wcsc-modal" role="dialog" aria-modal="true" aria-labelledby="wcsc-modal-title" aria-describedby="wcsc-modal-desc" tabindex="-1">
		<button type="button" class="wcsc-modal__close" data-wcsc-close aria-label="<?php esc_attr_e( 'Close', 'wc-share-cart' ); ?>">
			<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M18.3 5.7a1 1 0 0 0-1.4 0L12 10.6 7.1 5.7a1 1 0 0 0-1.4 1.4L10.6 12l-4.9 4.9a1 1 0 1 0 1.4 1.4L12 13.4l4.9 4.9a1 1 0 0 0 1.4-1.4L13.4 12l4.9-4.9a1 1 0 0 0 0-1.4z"/></svg>
		</button>

		<h2 class="wcsc-modal__title" id="wcsc-modal-title"><?php echo esc_html( $heading ); ?></h2>
		<p class="wcsc-modal__desc" id="wcsc-modal-desc"><?php echo esc_html( $desc ); ?></p>

		<div class="wcsc-modal__status" data-wcsc-loading hidden>
			<span class="wcsc-spinner" aria-hidden="true"></span>
			<span data-wcsc-loading-text></span>
		</div>

		<div class="wcsc-modal__error" data-wcsc-error role="alert" hidden></div>

		<div class="wcsc-modal__unsupported" data-wcsc-unsupported hidden>
			<p data-wcsc-unsupported-title></p>
			<ul data-wcsc-unsupported-list></ul>
			<button type="button" class="wcsc-btn wcsc-btn--primary" data-wcsc-share-partial></button>
		</div>

		<div class="wcsc-modal__body" data-wcsc-body hidden>
			<div class="wcsc-excluded" data-wcsc-excluded hidden></div>

			<label class="wcsc-label" for="wcsc-link-input"><?php esc_html_e( 'Shared cart link', 'wc-share-cart' ); ?></label>
			<div class="wcsc-link-row">
				<input type="text" id="wcsc-link-input" class="wcsc-link-input" data-wcsc-link readonly />
				<?php if ( ! empty( $opts['copy'] ) ) : ?>
					<button type="button" class="wcsc-btn wcsc-btn--primary wcsc-btn--copy" data-wcsc-copy>
						<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M16 1H4a2 2 0 0 0-2 2v14h2V3h12V1zm3 4H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2zm0 16H8V7h11v14z"/></svg>
						<span data-wcsc-copy-label><?php esc_html_e( 'Copy Link', 'wc-share-cart' ); ?></span>
					</button>
				<?php endif; ?>
			</div>

			<div class="wcsc-share-actions">
				<?php if ( ! empty( $opts['whatsapp'] ) ) : ?>
					<a class="wcsc-btn wcsc-btn--whatsapp" data-wcsc-whatsapp href="#" target="_blank" rel="noopener noreferrer">
						<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12.04 2a9.9 9.9 0 0 0-8.4 15.1L2 22l5-1.6A9.9 9.9 0 1 0 12.04 2zm0 18a8 8 0 0 1-4.1-1.1l-.3-.2-3 .9.9-2.9-.2-.3A8 8 0 1 1 12.04 20zm4.4-6c-.2-.1-1.4-.7-1.6-.8s-.4-.1-.5.1-.6.8-.8 1-.3.2-.5.1a6.5 6.5 0 0 1-3.2-2.8c-.2-.4.2-.4.6-1.2.1-.1 0-.3 0-.4l-.7-1.6c-.2-.4-.4-.4-.5-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.8 11.9 11.9 0 0 0 4.6 4.1c1.7.7 2.4.8 3.2.7a2.7 2.7 0 0 0 1.8-1.3 2.2 2.2 0 0 0 .2-1.3c-.1-.1-.3-.2-.5-.3z"/></svg>
						<span><?php esc_html_e( 'Share on WhatsApp', 'wc-share-cart' ); ?></span>
					</a>
				<?php endif; ?>
				<?php if ( ! empty( $opts['email'] ) ) : ?>
					<a class="wcsc-btn wcsc-btn--email" data-wcsc-email href="#">
						<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4-8 5-8-5V6l8 5 8-5v2z"/></svg>
						<span><?php esc_html_e( 'Share via Email', 'wc-share-cart' ); ?></span>
					</a>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $opts['qr'] ) ) : ?>
				<div class="wcsc-qr" data-wcsc-qr-wrap>
					<canvas class="wcsc-qr__canvas" data-wcsc-qr width="256" height="256" role="img" aria-label="<?php esc_attr_e( 'QR code for your shared cart link', 'wc-share-cart' ); ?>"></canvas>
					<?php if ( ! empty( $opts['qrDownload'] ) ) : ?>
						<a class="wcsc-btn wcsc-btn--secondary" data-wcsc-qr-download download="shared-cart-qr.png" href="#">
							<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M5 20h14v-2H5v2zM19 9h-4V3H9v6H5l7 7 7-7z"/></svg>
							<span><?php esc_html_e( 'Download QR Code', 'wc-share-cart' ); ?></span>
						</a>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<p class="wcsc-expiry" data-wcsc-expiry></p>

			<button type="button" class="wcsc-link-button" data-wcsc-revoke><?php esc_html_e( 'Revoke this link', 'wc-share-cart' ); ?></button>
		</div>

		<div class="wcsc-modal__footer">
			<button type="button" class="wcsc-btn wcsc-btn--secondary" data-wcsc-close><?php esc_html_e( 'Close', 'wc-share-cart' ); ?></button>
		</div>

		<div class="screen-reader-text wcsc-sr-only" data-wcsc-live aria-live="polite" aria-atomic="true"></div>
	</div>
</div>

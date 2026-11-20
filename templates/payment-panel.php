<?php
/**
 * Payment panel.
 *
 * Override in your theme: yourtheme/upi-direct-for-woocommerce/payment-panel.php
 *
 * @var WC_Order   $order         Order.
 * @var array      $state         state, label, message, reference.
 * @var object|null $txn          Transaction.
 * @var array      $links         App launch links.
 * @var array      $config        JS config.
 * @var string     $instructions  Instructions text.
 * @var string     $vpa           Payee VPA.
 * @var bool       $ask_reference Whether to ask for a reference.
 * @var string     $ref_format    utr12|alnum.
 * @var array|null $flash         Flash message.
 * @var string     $retry_url     Pay-for-order URL.
 *
 * @package UPI_Direct_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

$upidw_amount = wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) );
$upidw_open   = in_array( $state['state'], array( 'awaiting_payment', 'awaiting_verification' ), true );
$upidw_class  = 'upidw-panel' . ( ! empty( $config['showQr'] ) ? ' upidw-has-qr' : '' ) . ( empty( $links ) ? ' upidw-no-apps' : '' );
?>
<section class="<?php echo esc_attr( $upidw_class ); ?>" data-upidw="<?php echo esc_attr( wp_json_encode( $config ) ); ?>" data-state="<?php echo esc_attr( $state['state'] ); ?>" aria-labelledby="upidw-title-<?php echo esc_attr( $order->get_id() ); ?>">

	<header class="upidw-head">
		<div class="upidw-head__main">
			<h2 class="upidw-title" id="upidw-title-<?php echo esc_attr( $order->get_id() ); ?>"><?php esc_html_e( 'Pay with UPI', 'upi-direct-for-woocommerce' ); ?></h2>
			<p class="upidw-amount">
				<span class="upidw-amount__value"><?php echo esc_html( $upidw_amount ); ?></span>
				<span class="upidw-amount__meta">
					<?php
					/* translators: %s: order number. */
					echo esc_html( sprintf( __( 'for order #%s', 'upi-direct-for-woocommerce' ), $order->get_order_number() ) );
					?>
				</span>
			</p>
		</div>
		<p class="upidw-badge" data-upidw-badge><?php echo esc_html( $state['label'] ); ?></p>
	</header>

	<div class="upidw-status" role="status" aria-live="polite" data-upidw-message>
		<?php echo esc_html( $state['message'] ); ?>
	</div>

	<?php if ( ! empty( $flash ) ) : ?>
		<div class="upidw-flash upidw-flash--<?php echo esc_attr( $flash['type'] ); ?>" role="alert"><?php echo esc_html( $flash['text'] ); ?></div>
	<?php endif; ?>

	<?php if ( $upidw_open ) : ?>
	<div class="upidw-pay" data-upidw-pay>

		<?php if ( ! empty( $links ) ) : ?>
		<div class="upidw-apps">
			<h3 class="upidw-h"><?php esc_html_e( 'On your phone', 'upi-direct-for-woocommerce' ); ?></h3>
			<p class="upidw-sub"><?php esc_html_e( 'Open your UPI app with the amount filled in.', 'upi-direct-for-woocommerce' ); ?></p>
			<ul class="upidw-apps__list">
				<?php foreach ( $links as $upidw_id => $upidw_link ) : ?>
					<li<?php echo ! empty( $upidw_link['android_only'] ) ? ' data-platform="android"' : ''; ?>>
						<a class="upidw-app upidw-app--<?php echo esc_attr( $upidw_id ); ?>" href="<?php echo esc_url( $upidw_link['uri'], array( 'upi', 'tez', 'phonepe', 'paytmmp', 'intent' ) ); ?>" rel="nofollow">
							<?php echo esc_html( $upidw_link['label'] ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $config['showQr'] ) ) : ?>
		<div class="upidw-qr">
			<h3 class="upidw-h"><?php esc_html_e( 'On another device', 'upi-direct-for-woocommerce' ); ?></h3>
			<p class="upidw-sub"><?php esc_html_e( 'Scan this code with any UPI app.', 'upi-direct-for-woocommerce' ); ?></p>
			<div class="upidw-qr__frame">
				<div class="upidw-qr__code" data-upidw-qr role="img" aria-label="<?php esc_attr_e( 'UPI payment QR code', 'upi-direct-for-woocommerce' ); ?>"></div>
			</div>
		</div>
		<?php endif; ?>

		<div class="upidw-vpa">
			<span class="upidw-vpa__label"><?php esc_html_e( 'Or pay to this UPI ID', 'upi-direct-for-woocommerce' ); ?></span>
			<code class="upidw-vpa__value" data-upidw-vpa><?php echo esc_html( $vpa ); ?></code>
			<button type="button" class="upidw-copy" data-upidw-copy hidden><?php esc_html_e( 'Copy', 'upi-direct-for-woocommerce' ); ?></button>
		</div>
	</div>

	<?php if ( $ask_reference ) : ?>
	<form class="upidw-ref" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-upidw-form novalidate>
		<input type="hidden" name="action" value="upidw_submit_reference">
		<input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>">
		<input type="hidden" name="order_key" value="<?php echo esc_attr( $order->get_order_key() ); ?>">
		<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $config['nonce'] ); ?>">

		<label class="upidw-ref__label" for="upidw-ref-<?php echo esc_attr( $order->get_id() ); ?>">
			<?php esc_html_e( 'After paying, enter your UPI reference', 'upi-direct-for-woocommerce' ); ?>
		</label>
		<p class="upidw-sub" id="upidw-ref-hint-<?php echo esc_attr( $order->get_id() ); ?>">
			<?php
			echo esc_html(
				'alnum' === $ref_format
					? __( 'Find it in your app under the payment details (UTR, UPI Ref or Transaction ID).', 'upi-direct-for-woocommerce' )
					: __( 'A 12-digit number labelled UTR or UPI Ref No. in your payment app.', 'upi-direct-for-woocommerce' )
			);
			?>
		</p>
		<div class="upidw-ref__row">
			<input
				class="upidw-ref__input"
				type="text"
				id="upidw-ref-<?php echo esc_attr( $order->get_id() ); ?>"
				name="reference"
				value=""
				autocomplete="off"
				autocapitalize="characters"
				spellcheck="false"
				<?php echo 'alnum' === $ref_format ? 'maxlength="35"' : 'inputmode="numeric" maxlength="14"'; ?>
				aria-describedby="upidw-ref-hint-<?php echo esc_attr( $order->get_id() ); ?> upidw-ref-error-<?php echo esc_attr( $order->get_id() ); ?>"
				required
				<?php echo 'alnum' === $ref_format ? 'pattern="[A-Za-z0-9 \-]{8,40}"' : 'pattern="[0-9 \-]{12,14}"'; ?>
				placeholder="<?php echo 'alnum' === $ref_format ? '' : '4012 3456 7890'; ?>"
			>
			<button type="submit" class="upidw-ref__submit button alt" data-upidw-submit>
				<span data-upidw-submit-label><?php esc_html_e( 'Confirm payment', 'upi-direct-for-woocommerce' ); ?></span>
			</button>
		</div>
		<p class="upidw-ref__error" id="upidw-ref-error-<?php echo esc_attr( $order->get_id() ); ?>" data-upidw-error role="alert" hidden></p>
	</form>
	<?php endif; ?>

	<?php if ( '' !== trim( $instructions ) ) : ?>
	<details class="upidw-help">
		<summary><?php esc_html_e( 'Payment instructions', 'upi-direct-for-woocommerce' ); ?></summary>
		<p><?php echo esc_html( $instructions ); ?></p>
	</details>
	<?php endif; ?>
	<?php endif; ?>

	<?php if ( 'rejected' === $state['state'] ) : ?>
		<p class="upidw-retry"><a class="button" href="<?php echo esc_url( $retry_url ); ?>"><?php esc_html_e( 'Try paying again', 'upi-direct-for-woocommerce' ); ?></a></p>
	<?php endif; ?>

	<noscript>
		<p class="upidw-noscript"><?php esc_html_e( 'JavaScript is off, so the QR code and live status are unavailable. You can still use the buttons, the UPI ID and the reference form.', 'upi-direct-for-woocommerce' ); ?></p>
	</noscript>
</section>

<?php
/**
 * "Your box has shipped" email. Copy to yourtheme/woocommerce/emails/rinnovobox-shipped.php to change it.
 *
 * @package RinnovoBoxes
 * @var string   $email_heading
 * @var string   $additional_content
 * @var WC_Email $email
 * @var string   $first_name
 * @var WC_Order $order
 * @var array[]  $tracking
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
$color = get_option( 'woocommerce_email_base_color', '#7f54b3' );
?>
<p>
<?php
/* translators: %s: first name. */
echo esc_html( sprintf( __( 'Hi %s,', 'rinnovo-physical-subscription-boxes-pro' ), $first_name ) );
?>
</p>
<p>
<?php
/* translators: %s: order number. */
echo esc_html( sprintf( __( 'Good news: your box (order #%s) is on its way.', 'rinnovo-physical-subscription-boxes-pro' ), $order->get_order_number() ) );
?>
</p>
<?php foreach ( $tracking as $t ) : ?>
	<p>
		<?php
		$carriers = RINNOVOBOX_Emails::carriers();
		$name     = isset( $carriers[ $t['carrier'] ] ) && 'other' !== $t['carrier'] ? $carriers[ $t['carrier'] ] : $t['carrier'];
		echo esc_html( trim( $name . ' ' . __( 'tracking number:', 'rinnovo-physical-subscription-boxes-pro' ) ) ) . ' <strong>' . esc_html( $t['number'] ) . '</strong>';
		?>
	</p>
	<?php if ( '' !== $t['url'] ) : ?>
		<p style="margin:16px 0 24px"><a href="<?php echo esc_url( $t['url'] ); ?>" style="background:<?php echo esc_attr( $color ); ?>;color:#ffffff;padding:12px 22px;border-radius:6px;text-decoration:none;font-weight:bold;display:inline-block"><?php esc_html_e( 'Track my box', 'rinnovo-physical-subscription-boxes-pro' ); ?></a></p>
	<?php endif; ?>
<?php endforeach; ?>
<?php
do_action( 'woocommerce_email_order_details', $order, false, false, $email );
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );

<?php
/**
 * "Your next box" email. Copy to yourtheme/woocommerce/emails/rinnovobox-upcoming.php to change it.
 *
 * @package RinnovoBoxes
 * @var string   $email_heading
 * @var string   $additional_content
 * @var WC_Email $email
 * @var string   $first_name
 * @var object   $sub
 * @var array    $box
 * @var string   $date
 * @var string   $amount
 * @var bool     $automatic
 * @var string   $deadline
 * @var string   $manage_url
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
echo esc_html(
	$automatic
		/* translators: 1: date, 2: amount. */
		? sprintf( __( 'Your next box is coming. On %1$s we will charge %2$s to your card and send it out.', 'rinnovo-physical-subscription-boxes-pro' ), $date, $amount )
		/* translators: 1: date, 2: amount. */
		: sprintf( __( 'Your next box is coming. On %1$s you will get an invoice for %2$s; we send the box once it is paid.', 'rinnovo-physical-subscription-boxes-pro' ), $date, $amount )
);
?>
</p>
<table cellspacing="0" cellpadding="8" style="width:100%;border:1px solid #e5e5e5;border-collapse:collapse;margin:0 0 16px">
	<?php foreach ( $box['items'] as $item ) : ?>
		<tr>
			<td style="border:1px solid #e5e5e5"><?php echo esc_html( RINNOVOBOX_Box::item_name( $item ) ); ?></td>
			<td style="border:1px solid #e5e5e5;text-align:right">× <?php echo (int) $item['qty']; ?></td>
		</tr>
	<?php endforeach; ?>
</table>
<?php if ( '' !== $deadline ) : ?>
	<p>
	<?php
	/* translators: %s: date. */
	echo esc_html( sprintf( __( 'Want something different? Skip, swap or change this box until %s.', 'rinnovo-physical-subscription-boxes-pro' ), $deadline ) );
	?>
	</p>
<?php endif; ?>
<p style="margin:24px 0"><a href="<?php echo esc_url( $manage_url ); ?>" style="background:<?php echo esc_attr( $color ); ?>;color:#ffffff;padding:12px 22px;border-radius:6px;text-decoration:none;font-weight:bold;display:inline-block"><?php esc_html_e( 'Skip, swap or change my box', 'rinnovo-physical-subscription-boxes-pro' ); ?></a></p>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );

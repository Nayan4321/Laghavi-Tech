<?php
/**
 * "Your prepaid plan has ended" email. Copy to yourtheme/woocommerce/emails/rinnovobox-prepaid-ending.php to change it.
 *
 * @package RinnovoBoxes
 * @var string   $email_heading
 * @var string   $additional_content
 * @var WC_Email $email
 * @var string   $first_name
 * @var int      $boxes
 * @var string   $name
 * @var string   $url
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
/* translators: 1: number of boxes, 2: product. */
echo esc_html( sprintf( __( 'The last of your %1$d prepaid boxes of %2$s is on its way. We hope you enjoyed them!', 'rinnovo-physical-subscription-boxes-pro' ), $boxes, $name ) );
?>
</p>
<p style="margin:24px 0"><a href="<?php echo esc_url( $url ); ?>" style="background:<?php echo esc_attr( $color ); ?>;color:#ffffff;padding:12px 22px;border-radius:6px;text-decoration:none;font-weight:bold;display:inline-block"><?php esc_html_e( 'Keep the boxes coming', 'rinnovo-physical-subscription-boxes-pro' ); ?></a></p>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );

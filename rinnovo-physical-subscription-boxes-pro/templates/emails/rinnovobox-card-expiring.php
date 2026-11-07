<?php
/**
 * "Your card expires soon" email. Copy to yourtheme/woocommerce/emails/rinnovobox-card-expiring.php to change it.
 *
 * @package RinnovoBoxes
 * @var string   $email_heading
 * @var string   $additional_content
 * @var WC_Email $email
 * @var string   $first_name
 * @var array    $card
 * @var string   $expires
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
/* translators: 1: card brand, 2: last 4 digits, 3: month and year. */
echo esc_html( sprintf( __( 'The %1$s card ending in %2$s that pays for your subscription expires at the end of %3$s. Add your new card now so your next box arrives on time.', 'rinnovo-physical-subscription-boxes-pro' ), ucfirst( $card['brand'] ), $card['last4'], $expires ) );
?>
</p>
<p style="margin:24px 0"><a href="<?php echo esc_url( $manage_url ); ?>" style="background:<?php echo esc_attr( $color ); ?>;color:#ffffff;padding:12px 22px;border-radius:6px;text-decoration:none;font-weight:bold;display:inline-block"><?php esc_html_e( 'Update my card', 'rinnovo-physical-subscription-boxes-pro' ); ?></a></p>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );

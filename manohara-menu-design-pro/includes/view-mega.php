<?php
/**
 * A mega menu on its own, for editors (preview). Shown as it opens under a menu.
 *
 * @package ManoharaMenuDesignPro
 */

defined( 'ABSPATH' ) || exit;

wp_enqueue_style( 'mnhp-panels' );
wp_enqueue_script( 'mnhp-panels' );
$mnhp_id = get_queried_object_id();
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex" />
<?php wp_head(); ?>
</head>
<body <?php body_class( 'mnhp-view' ); ?>>
<?php wp_body_open(); ?>
<p class="mnhp-view-note"><?php esc_html_e( 'Preview of the mega menu. On the site it opens under its menu item.', 'manohara-menu-design-pro' ); ?></p>
<div class="mnhp-view-stage">
	<?php
	$mnhp_s = MNHP_Panels::settings( $mnhp_id );
	echo '<div class="mnhp-panel mnhp-pw-' . esc_attr( $mnhp_s['width'] ) . ( $mnhp_s['shadow'] ? ' mnhp-shadow' : '' ) . ' mnhp-static" style="--mnhp-pmax:' . (int) $mnhp_s['width_px'] . 'px"><div class="mnhp-panel-body">';
	while ( have_posts() ) {
		the_post();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered blocks.
		echo MNHP_Panels::content_from_blocks( get_the_content() );
	}
	echo '</div></div>';
	?>
</div>
<?php wp_footer(); ?>
</body>
</html>

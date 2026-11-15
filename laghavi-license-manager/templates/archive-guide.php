<?php
/**
 * Guides list (domain.com/guides/ and guide categories), full width in the theme's header and footer.
 *
 * @package LaghaviLicenseManager
 */

defined( 'ABSPATH' ) || exit;

get_header();
echo LLM_Guides::archive_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
get_footer();

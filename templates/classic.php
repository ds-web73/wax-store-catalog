<?php
/**
 * Catalog pages for classic themes: release, catalog and archives,
 * between the header and footer of the active theme.
 *
 * @package WaxStore\Catalog
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div class="waxsc-classic">
	<?php echo \WaxStore\Catalog\Front\Templates::render_classic(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered blocks. ?>
</div>
<?php
get_footer();

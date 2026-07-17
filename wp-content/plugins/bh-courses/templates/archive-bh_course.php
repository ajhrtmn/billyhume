<?php
/**
 * Fallback /courses/ archive template — only used when the active theme
 * doesn't supply its own archive-bh_course.php (see
 * BHC_Render::maybe_use_archive_template()'s docblock in class-render.php
 * for why this only fires as a fallback, not an override). Deliberately
 * plain: get_header()/get_footer() so it inherits the active theme's
 * chrome, with BHC_Render::render_catalog() doing all the real work
 * (search/filter/sort/pagination) — same division of labor as every
 * shortcode-driven view in this plugin.
 */
if (!defined('ABSPATH')) exit;

get_header();
?>
<main id="bhc-course-archive" class="bhc-archive-main">
	<header class="bhc-archive-header">
		<h1 class="bhc-archive-title"><?php esc_html_e('Courses', 'bh-courses'); ?></h1>
	</header>
	<?php echo BHC_Render::render_catalog(); ?>
</main>
<?php
get_footer();

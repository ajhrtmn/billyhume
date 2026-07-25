<?php
/**
 * Fallback /courses/ archive template — only used when the active theme
 * doesn't supply its own archive-bh_course.php (see
 * BHC_Render::maybe_use_archive_template()'s docblock in class-render.php
 * for why this only fires as a fallback, not an override). Deliberately
 * plain: inherits the active theme's real chrome, with
 * BHC_Render::render_catalog() doing all the real work (search/filter/
 * sort/pagination) — same division of labor as every shortcode-driven
 * view in this plugin.
 *
 * Block-theme aware: a classic theme's get_header()/get_footer() include
 * the WHOLE document shell (doctype/head/wp_head/body/wp_body_open) plus
 * nav — a block theme (Twenty Twenty-Five and every other core theme
 * since 5.9) ships no header.php/footer.php at all, so those calls used
 * to silently fall through to WordPress core's own decades-old
 * theme-compat stub (wp-includes/theme-compat/header.php) — bare,
 * unstyled, no nav, confirmed live on this install via the rendered
 * `#header #headerimg` markup that stub produces. block_header_area()/
 * block_footer_area() (wp-includes/block-template-utils.php, core since
 * 5.9) are WordPress's own documented way to print a block theme's REAL
 * header/footer template parts from a plugin's custom full-page
 * template — whatever the active block theme (or the site owner's own
 * Site Editor customization of it) actually defines, not a hardcoded
 * guess. This keeps the plain get_header()/get_footer() path for any
 * classic theme that already worked correctly, and only takes the
 * block-template path when the active theme actually is one.
 */
if (!defined('ABSPATH')) exit;

// Computed BEFORE any header output deliberately — render_catalog() is
// what calls BH_SEO::set_page_data() (ecosystem depth-pass Tier 2, SEO
// coverage), and both wp_head() (below) and get_header() fire it
// immediately. Calling render_catalog() after header output, as this
// template used to, meant wp_head always rendered with no page data set
// yet — silently no-op.
$catalog_html = BHC_Render::render_catalog();

$is_block_theme = function_exists('wp_is_block_theme') && wp_is_block_theme();

if ($is_block_theme && function_exists('block_header_area') && function_exists('block_footer_area')) {
    ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<?php block_header_area(); ?>
<main id="bhc-course-archive" class="bhc-archive-main">
    <header class="bhc-archive-header">
        <h1 class="bhc-archive-title"><?php esc_html_e('Courses', 'bh-courses'); ?></h1>
    </header>
    <?php echo $catalog_html; ?>
</main>
<?php block_footer_area(); ?>
<?php wp_footer(); ?>
</body>
</html>
    <?php
} else {
    get_header();
    ?>
<main id="bhc-course-archive" class="bhc-archive-main">
    <header class="bhc-archive-header">
        <h1 class="bhc-archive-title"><?php esc_html_e('Courses', 'bh-courses'); ?></h1>
    </header>
    <?php echo $catalog_html; ?>
</main>
    <?php
    get_footer();
}

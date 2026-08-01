<?php
if (!defined('ABSPATH')) exit;
/**
 * Shared archive/page/search/404 header — same kicker + display title +
 * accent speed-line rule shape as bh-courses's .bhc-archive-header, so
 * theme-rendered titles read as the same system as plugin catalog pages.
 *
 * Args (via get_template_part('template-parts/page-header', null, $args)):
 *   kicker (string, optional)
 *   title  (string, required — may contain markup, e.g. the_archive_title())
 *   lede   (string, optional)
 */
$kicker = $args['kicker'] ?? '';
$title = $args['title'] ?? '';
$lede = $args['lede'] ?? '';
if ($title === '') return;
?>
<header class="oust-page-header">
    <?php if ($kicker !== '') : ?>
        <span class="oust-page-header-kicker"><?php echo esc_html($kicker); ?></span>
    <?php endif; ?>
    <h1 class="oust-page-header-title"><?php echo wp_kses_post($title); ?></h1>
    <div class="oust-page-header-rule"></div>
    <?php if ($lede !== '') : ?>
        <p class="oust-page-header-lede"><?php echo wp_kses_post($lede); ?></p>
    <?php endif; ?>
</header>

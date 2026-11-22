<?php
if (!defined('ABSPATH')) exit;
/**
 * Shared archive/page/search/404 header. Args via get_template_part(): kicker (optional),
 * title (required, may contain markup), lede (optional).
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

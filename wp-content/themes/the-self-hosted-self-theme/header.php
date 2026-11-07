<?php if (!defined('ABSPATH')) exit; ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="profile" href="https://gmpg.org/xfn/11">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="oust-skip-link screen-reader-text" href="#oust-main"><?php esc_html_e('Skip to content', 'the-self-hosted-self-theme'); ?></a>

<?php $oust_header_image = get_header_image(); ?>
<header class="oust-site-header<?php echo $oust_header_image ? ' has-header-image' : ''; ?>"<?php echo $oust_header_image ? ' style="--oust-header-image: url(\'' . esc_url($oust_header_image) . '\');"' : ''; ?>>
    <div class="oust-site-header-inner">
        <a class="oust-site-brand" href="<?php echo esc_url(home_url('/')); ?>">
            <?php if (has_custom_logo()) : ?>
                <?php the_custom_logo(); ?>
            <?php else : ?>
                <span class="oust-site-brand-name"><?php bloginfo('name'); ?></span>
            <?php endif; ?>
        </a>

        <nav class="oust-primary-nav" aria-label="<?php esc_attr_e('Primary', 'the-self-hosted-self-theme'); ?>">
            <?php
            wp_nav_menu([
                'theme_location' => 'primary',
                'container' => false,
                'menu_class' => 'oust-nav-list',
                'fallback_cb' => 'oust_default_menu',
                'depth' => 2,
                // "More" overflow trigger; theme.js moves top-level items
                // that don't fit into it (never the account CTA).
                'items_wrap' => '<ul id="%1$s" class="%2$s">%3$s<li class="oust-nav-more"><button type="button" aria-haspopup="true" aria-expanded="false">' . esc_html__('More', 'the-self-hosted-self-theme') . ' <span class="oust-nav-more-caret" aria-hidden="true">&#9662;</span></button><ul></ul></li></ul>',
            ]);
            ?>
        </nav>

        <button class="oust-nav-toggle" aria-expanded="false" aria-controls="oust-mobile-nav">
            <span class="oust-nav-toggle-bar"></span>
            <span class="oust-nav-toggle-bar"></span>
            <span class="oust-nav-toggle-bar"></span>
            <span class="screen-reader-text"><?php esc_html_e('Menu', 'the-self-hosted-self-theme'); ?></span>
        </button>
    </div>

    <nav id="oust-mobile-nav" class="oust-mobile-nav" aria-label="<?php esc_attr_e('Primary (mobile)', 'the-self-hosted-self-theme'); ?>">
        <?php
        wp_nav_menu([
            'theme_location' => 'primary',
            'container' => false,
            'menu_class' => 'oust-nav-list',
            'fallback_cb' => 'oust_default_menu',
            'depth' => 2,
        ]);
        ?>
    </nav>
</header>

<?php
function oust_default_menu() {
    echo '<ul class="oust-nav-list">';
    echo '<li><a href="' . esc_url(home_url('/')) . '">' . esc_html__('Home', 'the-self-hosted-self-theme') . '</a></li>';
    $pages = get_pages(['sort_column' => 'menu_order', 'number' => 6]);
    foreach ($pages as $p) {
        echo '<li><a href="' . esc_url(get_permalink($p->ID)) . '">' . esc_html(get_the_title($p->ID)) . '</a></li>';
    }
    // Last-resort fallback when no 'primary' menu is assigned (OUS_MenuSync
    // normally creates one), so it adds its own Account/Log In link.
    if (class_exists('BHI_Portal')) {
        $url = esc_url(home_url('/account/'));
        $label = is_user_logged_in() ? esc_html__('Account', 'the-self-hosted-self-theme') : esc_html__('Log In', 'the-self-hosted-self-theme');
        echo '<li class="oust-nav-account-link"><a href="' . $url . '">' . $label . '</a></li>';
    }
    echo '</ul>';
}
?>

<main id="oust-main" class="oust-main">

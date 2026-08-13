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
<a class="oust-skip-link screen-reader-text" href="#oust-main"><?php esc_html_e('Skip to content', 'own-ur-shit-theme'); ?></a>

<header class="oust-site-header">
    <div class="oust-site-header-inner">
        <a class="oust-site-brand" href="<?php echo esc_url(home_url('/')); ?>">
            <?php if (has_custom_logo()) : ?>
                <?php the_custom_logo(); ?>
            <?php else : ?>
                <span class="oust-site-brand-name"><?php bloginfo('name'); ?></span>
            <?php endif; ?>
        </a>

        <nav class="oust-primary-nav" aria-label="<?php esc_attr_e('Primary', 'own-ur-shit-theme'); ?>">
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

        <button class="oust-nav-toggle" aria-expanded="false" aria-controls="oust-mobile-nav">
            <span class="oust-nav-toggle-bar"></span>
            <span class="oust-nav-toggle-bar"></span>
            <span class="oust-nav-toggle-bar"></span>
            <span class="screen-reader-text"><?php esc_html_e('Menu', 'own-ur-shit-theme'); ?></span>
        </button>
    </div>

    <nav id="oust-mobile-nav" class="oust-mobile-nav" aria-label="<?php esc_attr_e('Primary (mobile)', 'own-ur-shit-theme'); ?>">
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
    echo '<li><a href="' . esc_url(home_url('/')) . '">' . esc_html__('Home', 'own-ur-shit-theme') . '</a></li>';
    $pages = get_pages(['sort_column' => 'menu_order', 'number' => 6]);
    foreach ($pages as $p) {
        echo '<li><a href="' . esc_url(get_permalink($p->ID)) . '">' . esc_html(get_the_title($p->ID)) . '</a></li>';
    }
    // This fallback only runs when NO menu is assigned to 'primary' at
    // all — a state OUS_MenuSync's ensure_default_menu_exists() (own-
    // ur-shit 3.10.20+) now actively prevents by auto-creating one the
    // first time it ever syncs. Kept as a genuine last-resort safety
    // net, not the normal path anymore, so it still needs its own
    // Account/Log In link — nothing else would add one here.
    if (class_exists('BHI_Portal')) {
        $url = esc_url(home_url('/account/'));
        $label = is_user_logged_in() ? esc_html__('Account', 'own-ur-shit-theme') : esc_html__('Log In', 'own-ur-shit-theme');
        echo '<li class="oust-nav-account-link"><a href="' . $url . '">' . $label . '</a></li>';
    }
    echo '</ul>';
}
?>

<main id="oust-main" class="oust-main">

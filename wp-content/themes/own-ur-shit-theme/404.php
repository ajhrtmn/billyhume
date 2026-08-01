<?php if (!defined('ABSPATH')) exit; get_header(); ?>

<div class="oust-container oust-container-narrow">
    <section class="oust-404">
        <span class="oust-404-code">404</span>
        <header class="oust-page-header oust-page-header-center">
            <span class="oust-page-header-kicker"><?php esc_html_e('Not Found', 'own-ur-shit-theme'); ?></span>
            <h1 class="oust-page-header-title"><?php esc_html_e('This page took a wrong turn.', 'own-ur-shit-theme'); ?></h1>
            <div class="oust-page-header-rule oust-page-header-rule-center"></div>
            <p class="oust-page-header-lede"><?php esc_html_e('The page you&rsquo;re looking for doesn&rsquo;t exist, or has moved. Try a search, or head back home.', 'own-ur-shit-theme'); ?></p>
        </header>

        <form role="search" method="get" class="oust-search-form oust-search-form-center" action="<?php echo esc_url(home_url('/')); ?>">
            <label class="screen-reader-text" for="oust-404-search"><?php esc_html_e('Search for:', 'own-ur-shit-theme'); ?></label>
            <input type="search" id="oust-404-search" class="oust-search-input" name="s" placeholder="<?php esc_attr_e('Search this site&hellip;', 'own-ur-shit-theme'); ?>">
            <button type="submit" class="oust-btn oust-btn-primary"><?php esc_html_e('Search', 'own-ur-shit-theme'); ?></button>
        </form>

        <a class="oust-btn oust-btn-secondary oust-404-home" href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('&larr; Back to Home', 'own-ur-shit-theme'); ?></a>
    </section>
</div>

<?php get_footer(); ?>

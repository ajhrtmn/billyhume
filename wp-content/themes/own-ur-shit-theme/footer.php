</main><!-- .oust-main -->

<footer class="oust-site-footer">
    <div class="oust-site-footer-rule"></div>
    <div class="oust-site-footer-inner">
        <div class="oust-footer-col oust-footer-brand">
            <span class="oust-footer-kicker"><?php bloginfo('name'); ?></span>
            <p class="oust-footer-tagline"><?php bloginfo('description'); ?></p>
        </div>

        <?php if (is_active_sidebar('footer-1')) : ?>
            <div class="oust-footer-col oust-footer-widgets">
                <?php dynamic_sidebar('footer-1'); ?>
            </div>
        <?php endif; ?>

        <nav class="oust-footer-col oust-footer-nav" aria-label="<?php esc_attr_e('Footer', 'own-ur-shit-theme'); ?>">
            <?php
            wp_nav_menu([
                'theme_location' => 'primary',
                'container' => false,
                'menu_class' => 'oust-footer-nav-list',
                'fallback_cb' => false,
                'depth' => 1,
            ]);
            ?>
        </nav>
    </div>

    <div class="oust-site-footer-bottom">
        <p>&copy; <?php echo esc_html(date('Y')); ?> <?php bloginfo('name'); ?>. <?php esc_html_e('All rights reserved.', 'own-ur-shit-theme'); ?></p>
    </div>
</footer>

<?php wp_footer(); ?>
</body>
</html>

<?php if (!defined('ABSPATH')) exit; ?>
<div class="oust-empty-state">
    <div class="oust-empty-state-mark" aria-hidden="true"></div>
    <h2 class="oust-empty-state-title">
        <?php if (is_search()) : ?>
            <?php esc_html_e('Nothing matched your search.', 'the-self-hosted-self-theme'); ?>
        <?php else : ?>
            <?php esc_html_e('Nothing here yet.', 'the-self-hosted-self-theme'); ?>
        <?php endif; ?>
    </h2>
    <p class="oust-empty-state-text">
        <?php if (is_search()) : ?>
            <?php esc_html_e('Try a different search term, or browse from the menu above.', 'the-self-hosted-self-theme'); ?>
        <?php else : ?>
            <?php esc_html_e('Check back soon &mdash; new content is on the way.', 'the-self-hosted-self-theme'); ?>
        <?php endif; ?>
    </p>
</div>

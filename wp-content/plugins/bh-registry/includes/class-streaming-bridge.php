<?php
if (!defined('ABSPATH')) exit;

/**
 * Entirely optional, entirely one-directional convenience: IF
 * bh-streaming happens to be active on this same site, add a "Feature
 * an artist from the Registry" helper on its Feed Sources screen so an
 * admin can search this registry and prefill a bhs_feed_source's feed
 * URL from GET /bhr/v1/artists/{id}/feed-url, instead of copy-pasting a
 * URL by hand between two admin screens.
 *
 * bh-registry never requires bh-streaming — this whole class only does
 * anything if BHS_Player already exists, checked on plugins_loaded (see
 * bh-registry.php), the same rule every cross-plugin check in this
 * ecosystem follows. Delete bh-streaming, and this plugin (browsing,
 * search, submission, verification) is completely unaffected.
 */
class BHR_StreamingBridge {
    public static function init() {
        if (!class_exists('BHS_Player')) return;
        add_action('add_meta_boxes', [self::class, 'add_meta_box']);
    }

    public static function add_meta_box() {
        add_meta_box(
            'bhr_feature_from_registry',
            'Feature an artist from the Registry',
            [self::class, 'render'],
            'bhs_feed_source',
            'side'
        );
    }

    public static function render($post) {
        $url = get_post_meta($post->ID, '_bhs_feed_url', true);
        echo '<p class="description">Search the global BH Registry for an artist and pull in their verified feed URL automatically.</p>';
        echo '<input type="text" id="bhr-bridge-search" placeholder="Search registry…" style="width:100%;">';
        echo '<div id="bhr-bridge-results" style="margin-top:8px;"></div>';
        ?>
        <script>
        (function () {
            var input = document.getElementById('bhr-bridge-search');
            var results = document.getElementById('bhr-bridge-results');
            var restBase = '<?php echo esc_url_raw(rest_url('bhr/v1/')); ?>';
            var feedField = document.querySelector('input[name="bhs_feed_url"]');
            var timer;

            input.addEventListener('input', function () {
                clearTimeout(timer);
                var q = input.value;
                if (!q) { results.innerHTML = ''; return; }
                timer = setTimeout(function () {
                    fetch(restBase + 'artists?search=' + encodeURIComponent(q) + '&protocol=feed')
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            results.innerHTML = (data.artists || []).map(function (a) {
                                return '<div style="padding:4px 0;"><a href="#" data-id="' + a.id + '">' + a.display_name + '</a></div>';
                            }).join('') || '<em>No matches.</em>';
                            results.querySelectorAll('a').forEach(function (link) {
                                link.addEventListener('click', function (e) {
                                    e.preventDefault();
                                    fetch(restBase + 'artists/' + link.dataset.id + '/feed-url')
                                        .then(function (r) { return r.json(); })
                                        .then(function (fd) {
                                            if (feedField && fd.feed_url) feedField.value = fd.feed_url;
                                        });
                                });
                            });
                        });
                }, 300);
            });
        })();
        </script>
        <?php
    }
}

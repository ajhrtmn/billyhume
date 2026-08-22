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
    public static function init(): void {
        if (!class_exists('BHS_Player')) return;
        add_action('add_meta_boxes', [self::class, 'add_meta_box']);

        // Real gap found in a direct vision check (2026-08-21): the
        // meta-box above is search-only — an admin has to already know
        // an artist's name. Being registered never surfaced anyone
        // automatically, only made them findable if searched for. This
        // is the actual "browse the global library and curate from it"
        // experience — one click adds a registry artist as a real
        // bhs_feed_source and triggers an immediate sync, rather than
        // making the admin hand-create a post and copy a URL in first.
        if (class_exists('BHS_Feeds')) {
            add_action('admin_menu', [self::class, 'add_browse_page']);
            add_action('admin_post_bhr_add_from_registry', [self::class, 'handle_add_from_registry']);
        }
    }

    public static function add_meta_box(): void {
        add_meta_box(
            'bhr_feature_from_registry',
            'Feature an artist from the Registry',
            [self::class, 'render'],
            'bhs_feed_source',
            'side'
        );
    }

    public static function render(\WP_Post $post): void {
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

            function esc(s) {
                var div = document.createElement('div');
                div.textContent = s || '';
                return div.innerHTML;
            }

            input.addEventListener('input', function () {
                clearTimeout(timer);
                var q = input.value;
                if (!q) { results.innerHTML = ''; return; }
                results.textContent = 'Searching…';
                timer = setTimeout(function () {
                    fetch(restBase + 'artists?search=' + encodeURIComponent(q) + '&protocol=feed')
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            // display_name comes from public registry
                            // submissions — must be escaped, not
                            // interpolated raw into innerHTML.
                            results.innerHTML = (data.artists || []).map(function (a) {
                                return '<div style="padding:4px 0;"><a href="#" data-id="' + a.id + '">' + esc(a.display_name) + '</a></div>';
                            }).join('') || '<em>No matches.</em>';
                            results.querySelectorAll('a').forEach(function (link) {
                                link.addEventListener('click', function (e) {
                                    e.preventDefault();
                                    var originalText = link.textContent;
                                    link.textContent = 'Loading…';
                                    fetch(restBase + 'artists/' + link.dataset.id + '/feed-url')
                                        .then(function (r) { return r.json(); })
                                        .then(function (fd) {
                                            link.textContent = originalText;
                                            if (feedField && fd.feed_url) {
                                                feedField.value = fd.feed_url;
                                                feedField.style.transition = 'background-color .3s';
                                                feedField.style.backgroundColor = '#d4f7d4';
                                                setTimeout(function () { feedField.style.backgroundColor = ''; }, 900);
                                                results.innerHTML = '<em>Feed URL filled in from ' + esc(originalText) + '.</em>';
                                            } else {
                                                results.innerHTML = '<em>That artist has no feed URL on file.</em>';
                                            }
                                        })
                                        .catch(function () {
                                            link.textContent = originalText;
                                            results.innerHTML = '<em>Could not reach the registry — check your connection and try again.</em>';
                                        });
                                });
                            });
                        })
                        .catch(function () {
                            results.innerHTML = '<em>Could not reach the registry — check your connection and try again.</em>';
                        });
                }, 300);
            });
        })();
        </script>
        <?php
    }

    /* ---------- browse-all library (2026-08-21) ---------- */

    public static function add_browse_page(): void {
        add_submenu_page(
            BHS_PostTypes::MENU_PARENT, 'Browse Registry', 'Browse Registry',
            'manage_options', 'bhr-browse-registry', [self::class, 'render_browse_page']
        );
    }

    // Every feed URL already in this site's own library, so the browse
    // grid can show "Already added" instead of inviting a duplicate
    // bhs_feed_source for the same artist.
    /** @return array<string, bool> feed URL => true */
    private static function existing_feed_urls(): array {
        $sources = get_posts(['post_type' => 'bhs_feed_source', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids']);
        $out = [];
        foreach ($sources as $id) {
            $url = get_post_meta($id, '_bhs_feed_url', true);
            if ($url) $out[$url] = true;
        }
        return $out;
    }

    // Internal REST dispatch (WordPress core's own rest_do_request()) —
    // reuses BHR_API::list_artists()'s real query/verification logic
    // exactly as the public endpoint does, rather than duplicating the
    // SQL here. No network round-trip; this is a same-process call.
    /** @return array<int, array<string, mixed>> */
    private static function browse_artists(): array {
        $req = new WP_REST_Request('GET', '/bhr/v1/artists');
        $req->set_param('protocol', 'feed');
        $response = rest_do_request($req);
        if ($response->is_error()) return [];
        $data = $response->get_data();
        return is_array($data['artists'] ?? null) ? $data['artists'] : [];
    }

    public static function render_browse_page(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        BHY_UI::shell_open('Browse Registry', 'Every artist in the global BH Registry publishing an open feed — add one to your own library in a click. Nothing plays from here directly; adding a card creates a real Feed Source and pulls their tracks in the same way as any manually-added one.');

        $artists = self::browse_artists();
        $existing = self::existing_feed_urls();

        if (isset($_GET['bhr_added'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Added — syncing now, tracks will appear shortly.</p></div>';
        }

        if (!$artists) {
            echo '<p>No verified artists in the registry yet.</p>';
        } else {
            echo '<div class="bhr-browse-grid" style="display:grid;grid-template-columns:repeat(auto-fill, minmax(240px, 1fr));gap:16px;margin-top:16px;">';
            foreach ($artists as $artist) {
                $feed_link = null;
                foreach ($artist['links'] as $link) {
                    if ($link['protocol'] === 'feed') { $feed_link = $link; break; }
                }
                if (!$feed_link) continue;

                $already = isset($existing[$feed_link['url']]);
                echo '<div class="bhy-card">';
                echo '<h3 style="margin-top:0;">' . esc_html($artist['display_name']) . '</h3>';
                if ($artist['bio']) echo '<p class="description">' . esc_html(wp_trim_words($artist['bio'], 20)) . '</p>';
                if ($already) {
                    echo '<p><em>Already in your library.</em></p>';
                } else {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                    echo '<input type="hidden" name="action" value="bhr_add_from_registry">';
                    echo '<input type="hidden" name="artist_id" value="' . (int) $artist['id'] . '">';
                    wp_nonce_field('bhr_add_from_registry_' . $artist['id']);
                    echo '<button type="submit" class="button button-primary">Add to my library</button>';
                    echo '</form>';
                }
                echo '</div>';
            }
            echo '</div>';
        }

        BHY_UI::shell_close();
    }

    public static function handle_add_from_registry(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        $artist_id = (int) ($_POST['artist_id'] ?? 0);
        if (!$artist_id || !check_admin_referer('bhr_add_from_registry_' . $artist_id)) wp_die('Security check failed.');

        $req = new WP_REST_Request('GET', '/bhr/v1/artists/' . $artist_id . '/feed-url');
        $response = rest_do_request($req);
        $feed_url = $response->is_error() ? '' : (string) ($response->get_data()['feed_url'] ?? '');

        if ($feed_url === '') {
            wp_safe_redirect(admin_url('edit.php?post_type=bhs_track&page=bhr-browse-registry&bhr_error=1'));
            exit;
        }

        $post_id = wp_insert_post([
            'post_type'   => 'bhs_feed_source',
            'post_status' => 'publish',
            'post_title'  => 'Registry import #' . $artist_id,
        ], true);

        if (!is_wp_error($post_id)) {
            update_post_meta($post_id, '_bhs_feed_url', esc_url_raw($feed_url));
            // The real, immediate sync — same public entry point the
            // twice-daily cron and OUS_Jobs fan-out both already use
            // (class-feeds.php), not a duplicated fetch/parse here.
            if (class_exists('BHS_Feeds')) BHS_Feeds::sync_one_job(['feed_id' => $post_id]);
        }

        wp_safe_redirect(admin_url('edit.php?post_type=bhs_track&page=bhr-browse-registry&bhr_added=1'));
        exit;
    }
}

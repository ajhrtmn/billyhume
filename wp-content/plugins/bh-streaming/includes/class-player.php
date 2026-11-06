<?php
if (!defined('ABSPATH')) exit;

class BHS_Player {
    public static function init(): void {
        add_shortcode('bh_streaming', [self::class, 'render']);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue']);
        // OUS_Search provider: results deep-link via ?bhs_track=ID (handled by
        // player.ts maybeOpenTrackDeepLink()). Titles are public; gates still apply.
        add_filter('ous_search_providers', [self::class, 'register_search_provider']);
        add_action('template_redirect', [self::class, 'maybe_set_seo_data_early']);
    }

    /**
     * WHY: render() runs inside the_content(), after wp_head has printed
     * BH_SEO tags, so read the shortcode attributes early and set SEO data first.
     */
    public static function maybe_set_seo_data_early(): void {
        if (!class_exists('BH_SEO')) return;
        $atts = BH_SEO::shortcode_atts_on_current_page('bh_streaming');
        if ($atts === null) return;
        self::maybe_set_seo_data($atts);
    }

    /**
     * @param array<string, mixed> $providers
     * @return array<string, mixed>
     */
    public static function register_search_provider(array $providers): array {
        $providers['tracks'] = [self::class, 'search_tracks'];
        return $providers;
    }

    /** @return array<int, array<string, mixed>> */
    public static function search_tracks(string $query, int $limit): array {
        $page_id = (int) get_option('bhs_streaming_page_id', 0);
        if (!$page_id || get_post_status($page_id) !== 'publish') return []; // nowhere to link to yet

        $posts = get_posts([
            'post_type' => 'bhs_track', 'post_status' => 'publish',
            's' => $query, 'posts_per_page' => $limit,
        ]);
        $base_url = get_permalink($page_id);
        $out = [];
        foreach ($posts as $p) {
            $artist = get_post_meta($p->ID, '_bhs_artist', true);
            $out[] = [
                'type' => 'Track',
                'title' => $p->post_title,
                'excerpt' => $artist ? (string) $artist : '',
                'url' => add_query_arg('bhs_track', $p->ID, $base_url),
                'icon' => 'dashicons-format-audio',
            ];
        }
        return $out;
    }

    public static function maybe_enqueue(): void {
        if (!is_singular()) return;
        global $post;
        // has_block() too: a 'bhs/player' block page has no shortcode text but
        // still needs player.js/player.css enqueued.
        if (!$post || (!has_shortcode($post->post_content, 'bh_streaming') && !has_block('bhs/player', $post))) return;

        wp_enqueue_style('bhs-player', BHS_URL . 'assets/css/player.css', [], BHS_VER);
        // BHY_Style is optional (core style tokens); guard it.
        if (class_exists('BHY_Style')) wp_add_inline_style('bhs-player', BHY_Style::inline_css());
        wp_enqueue_script('bhs-player', BHS_URL . 'assets/js/player.js', [], BHS_VER, true);
        wp_localize_script('bhs-player', 'BHSData', [
            'rest'     => esc_url_raw(rest_url('bhs/v1/')),
            'identity' => esc_url_raw(rest_url('bhi/v1/')),
            'nonce'    => wp_create_nonce('wp_rest'),
            'loggedIn' => is_user_logged_in(),
            // Shared empty-state markup (BHY_Style::empty_state_html()) rendered
            // server-side for player.ts. cta_url is a same-page anchor because
            // import is a JS modal; player.ts delegates the click.
            'emptyStateZero' => class_exists('BHY_Style') ? BHY_Style::empty_state_html([
                'reason' => 'zero',
                'title' => 'Your library is empty',
                'description' => 'Import your music to start building your streaming library.',
                'cta_label' => 'Import my music',
                'cta_url' => '#bhs-import',
            ]) : '',
            'emptyStateFiltered' => class_exists('BHY_Style') ? BHY_Style::empty_state_html([
                'reason' => 'filtered',
                'title' => 'No tracks match',
                'clear_url' => '#bhs-clear-filters',
            ]) : '',
            'emptyStateReleases' => class_exists('BHY_Style') ? BHY_Style::empty_state_html([
                'reason' => 'zero',
                'title' => 'No releases yet',
                'description' => 'Group your tracks into an album or EP to see it here.',
            ]) : '',
            'emptyStateLiked' => class_exists('BHY_Style') ? BHY_Style::empty_state_html([
                'reason' => 'zero',
                'title' => 'Nothing liked yet',
                'description' => 'Tap the heart on a track to save it here.',
            ]) : '',
            'emptyStatePlaylistsLoggedOut' => class_exists('BHY_Style') ? BHY_Style::empty_state_html([
                'reason' => 'zero',
                'title' => 'Log in to create playlists',
            ]) : '',
            'emptyStatePlaylistsEmpty' => class_exists('BHY_Style') ? BHY_Style::empty_state_html([
                'reason' => 'zero',
                'title' => 'No playlists yet',
                'description' => 'Use the + button on a track while it\'s playing to start one.',
            ]) : '',
        ]);
    }

    /** @param array<string, mixed>|string $atts */
    public static function render($atts = []): string {
        // Hidden when BHS_FORCE_HIDDEN is defined (see class-env.php).
        if (BHS_Env::hidden_in_production()) return '';

        self::maybe_set_seo_data($atts);

        return '
        <div class="bhs-app" id="bhs-app">
            <div class="bhs-topbar">
                <div class="bhs-account" id="bhs-account">
                    <button type="button" class="bhs-btn" id="bhs-login-open" style="display:none;">Log in</button>
                    <span id="bhs-account-info" style="display:none;">
                        <span id="bhs-account-username"></span>
                        <button type="button" class="bhs-link-btn" id="bhs-logout">Log out</button>
                    </span>
                </div>
                <div class="bhs-tabs" id="bhs-tabs">
                    <button type="button" class="bhs-tab active" data-view="all">All Tracks</button>
                    <button type="button" class="bhs-tab" data-view="releases">Releases</button>
                    <button type="button" class="bhs-tab" data-view="liked">Liked Songs</button>
                    <button type="button" class="bhs-tab" data-view="playlists">My Playlists</button>
                </div>
                <div class="bhs-filters">
                    <input type="text" id="bhs-search" placeholder="Search title or artist…">
                    <select id="bhs-genre-filter"><option value="">All genres</option></select>
                    <button type="button" class="bhs-btn" id="bhs-import-open">Import my music</button>
                </div>
            </div>

            <div class="bhs-import-modal" id="bhs-import-modal" style="display:none;">
                <div class="bhs-import-modal-inner">
                    <button type="button" class="bhs-modal-close" id="bhs-import-close">&times;</button>
                    <h3>Import your own music</h3>
                    <p class="bhs-modal-note">Upload a local audio file (MP3, WAV, AIFF, etc.) into your own personal library — separate from the site\'s catalog and from any featured feeds. Requires an account with upload permission.</p>
                    <input type="file" id="bhs-import-file" accept="audio/*">
                    <input type="text" id="bhs-import-title" placeholder="Title (optional — defaults to the filename)">
                    <input type="text" id="bhs-import-artist" placeholder="Artist (optional)">
                    <button type="button" class="bhs-btn" id="bhs-import-submit">Import</button>
                    <div id="bhs-import-error" class="bhs-auth-error"></div>
                </div>
            </div>

            <div class="bhs-auth-modal" id="bhs-auth-modal" style="display:none;">
                <div class="bhs-auth-modal-inner">
                    <button type="button" class="bhs-modal-close" id="bhs-auth-close">&times;</button>
                    <div class="bhs-auth-tabs">
                        <button type="button" class="bhs-auth-tab active" data-mode="login">Log In</button>
                        <button type="button" class="bhs-auth-tab" data-mode="register">Sign Up</button>
                    </div>
                    <div id="bhs-auth-error" class="bhs-auth-error"></div>
                    <input type="text" id="bhs-auth-username" placeholder="Username">
                    <input type="email" id="bhs-auth-email" placeholder="Email" style="display:none;">
                    <div class="bhs-pass-wrap">
                        <input type="password" id="bhs-auth-password" placeholder="Password">
                        <button type="button" class="bhs-pass-toggle" aria-label="Show password" aria-pressed="false">&#128065;</button>
                    </div>
                    <button type="button" class="bhs-btn" id="bhs-auth-submit">Log In</button>
                </div>
            </div>

            <div class="bhs-main">
                <div class="bhs-library" id="bhs-library"><p class="bhs-empty">Loading…</p></div>
                <div class="bhs-related" id="bhs-related" style="display:none;">
                    <h3>Related</h3>
                    <div id="bhs-related-list"></div>
                </div>
            </div>

            <div class="bhs-nowplaying" id="bhs-nowplaying" style="display:none;">
                <canvas class="bhs-visualizer" id="bhs-visualizer" width="60" height="40"></canvas>
                <img class="bhs-np-art" id="bhs-np-art" alt="">
                <div class="bhs-np-info">
                    <div class="bhs-np-title" id="bhs-np-title"></div>
                    <div class="bhs-np-artist" id="bhs-np-artist"></div>
                </div>
                <div class="bhs-np-controls">
                    <button type="button" id="bhs-shuffle-toggle" aria-label="Shuffle">&#128256;</button>
                    <button type="button" id="bhs-prev" aria-label="Previous">&#9198;</button>
                    <button type="button" id="bhs-playpause" aria-label="Play">&#9658;</button>
                    <button type="button" id="bhs-next" aria-label="Next">&#9197;</button>
                    <button type="button" id="bhs-like" aria-label="Like" class="bhs-like-btn">&#9825;</button>
                    <button type="button" id="bhs-add-playlist" aria-label="Add to playlist">&#65291;</button>
                    <button type="button" id="bhs-lyrics-toggle" aria-label="Lyrics">Lyrics</button>
                    <button type="button" id="bhs-quality-toggle" aria-label="Quality" style="display:none;">Quality</button>
                    <button type="button" id="bhs-eq-toggle" aria-label="Equalizer">EQ</button>
                    <button type="button" id="bhs-viz-toggle" aria-label="Toggle visualizer">Viz</button>
                    <button type="button" id="bhs-jam-toggle" aria-label="Jam">Jam</button>
                    <button type="button" id="bhs-queue-toggle" aria-label="Queue">&#9776;</button>
                </div>
                <div class="bhs-np-volume">
                    <span>&#128266;</span>
                    <input type="range" id="bhs-volume" min="0" max="100" value="100">
                </div>
                <input type="range" id="bhs-seek" class="bhs-seek" min="0" max="100" value="0">
                <div class="bhs-np-chapters" id="bhs-np-chapters" style="display:none;"></div>
                <div class="bhs-jam-banner" id="bhs-jam-banner" style="display:none;"></div>
            </div>

            <div class="bhs-queue-panel" id="bhs-queue-panel" style="display:none;">
                <h3>Queue</h3>
                <div id="bhs-queue-list"></div>
            </div>

            <div class="bhs-jam-modal" id="bhs-jam-modal" style="display:none;">
                <div class="bhs-jam-modal-inner">
                    <button type="button" class="bhs-modal-close" id="bhs-jam-close">&times;</button>
                    <div id="bhs-jam-body">
                        <h3>Jam</h3>
                        <p class="bhs-modal-note">Listen together in real time — one host controls playback (or, in vote-to-skip mode, anyone can vote a track away), everyone hears the same thing at the same point.</p>
                        <label class="bhs-jam-mode-check"><input type="checkbox" id="bhs-jam-vote-mode"> Let anyone vote to skip a track</label>
                        <label class="bhs-jam-mode-check"><input type="checkbox" id="bhs-jam-approval-mode"> Require my approval before someone joins</label>
                        <label class="bhs-jam-mode-check">Max listeners (0 = no limit) <input type="number" id="bhs-jam-max-participants" min="0" max="200" value="0" style="width:60px;"></label>
                        <button type="button" class="bhs-btn" id="bhs-jam-create">Start a Jam from the current queue</button>
                        <p>— or —</p>
                        <input type="text" id="bhs-jam-code-input" placeholder="Enter invite code">
                        <button type="button" class="bhs-btn" id="bhs-jam-join">Join</button>
                        <div id="bhs-jam-error" class="bhs-auth-error"></div>
                    </div>
                </div>
            </div>

            <div class="bhs-lyrics-panel" id="bhs-lyrics-panel" style="display:none;">
                <h3>Lyrics</h3>
                <div id="bhs-lyrics-body" class="bhs-lyrics-body"><p class="bhs-empty">No lyrics for this track.</p></div>
            </div>

            <div class="bhs-quality-panel" id="bhs-quality-panel" style="display:none;">
                <h3>Quality</h3>
                <div id="bhs-quality-list"></div>
            </div>

            <div class="bhs-eq-panel" id="bhs-eq-panel" style="display:none;">
                <h3>Equalizer</h3>
                <div id="bhs-eq-bands" class="bhs-eq-bands"></div>
                <button type="button" class="bhs-link-btn" id="bhs-eq-reset">Reset</button>
            </div>

            <div class="bhs-playlist-picker" id="bhs-playlist-picker" style="display:none;">
                <div class="bhs-playlist-picker-inner">
                    <h3>Add to playlist</h3>
                    <div id="bhs-playlist-picker-list"></div>
                    <p><input type="text" id="bhs-new-playlist-name" placeholder="New playlist name"> <button type="button" id="bhs-new-playlist-create" class="bhs-btn">Create</button></p>
                    <button type="button" id="bhs-playlist-picker-close" class="bhs-btn">Close</button>
                </div>
            </div>
        </div>';
    }

    /**
     * Sets per-item schema.org data when the shortcode names a track or release
     * (`[bh_streaming track="123"]`); metadata only, track wins. Gated tracks
     * are still indexed, describing the work rather than the audio.
     */
    /** @param array<string, mixed>|string $atts */
    private static function maybe_set_seo_data($atts): void {
        if (!class_exists('BH_SEO')) return;
        $atts = shortcode_atts(['track' => '', 'release' => ''], is_array($atts) ? $atts : []);

        if ($atts['track'] !== '') {
            $track_id = (int) $atts['track'];
            $post = $track_id ? get_post($track_id) : null;
            if (!$post || $post->post_type !== 'bhs_track') return;

            $artist = get_post_meta($track_id, '_bhs_artist', true);
            $art_id = (int) get_post_meta($track_id, '_bhs_artwork_id', true);
            $image = $art_id ? wp_get_attachment_image_url($art_id, 'large') : null;
            $isrc = get_post_meta($track_id, '_bhs_isrc', true);
            // WHY: a mock ISRC (BHS_ISRC::is_mock()) is never published in
            // structured data; it would misrepresent registration status.
            if ($isrc && class_exists('BHS_ISRC') && BHS_ISRC::is_mock($isrc)) $isrc = '';

            BH_SEO::set_page_data([
                'title' => $post->post_title . ($artist ? ' by ' . $artist : '') . ' — ' . get_bloginfo('name'),
                'description' => $post->post_title . ($artist ? ', by ' . $artist : '') . ' on ' . get_bloginfo('name'),
                'image' => $image,
                'type' => 'music.song',
                'schema' => [
                    '@context' => 'https://schema.org',
                    '@type' => 'MusicRecording',
                    'name' => $post->post_title,
                    'byArtist' => $artist ? ['@type' => 'MusicGroup', 'name' => $artist] : null,
                    'image' => $image,
                    'isrcCode' => $isrc ?: null,
                ],
            ]);
        } elseif ($atts['release'] !== '') {
            $release_id = (int) $atts['release'];
            $post = $release_id ? get_post($release_id) : null;
            if (!$post || $post->post_type !== 'bhs_release') return;

            $artist = get_post_meta($release_id, '_bhs_release_artist', true);
            $art_id = (int) get_post_meta($release_id, '_bhs_release_artwork_id', true);
            $image = $art_id ? wp_get_attachment_image_url($art_id, 'large') : null;

            BH_SEO::set_page_data([
                'title' => $post->post_title . ($artist ? ' by ' . $artist : '') . ' — ' . get_bloginfo('name'),
                'description' => $post->post_title . ($artist ? ', by ' . $artist : '') . ' on ' . get_bloginfo('name'),
                'image' => $image,
                'type' => 'music.album',
                'schema' => [
                    '@context' => 'https://schema.org',
                    '@type' => 'MusicAlbum',
                    'name' => $post->post_title,
                    'byArtist' => $artist ? ['@type' => 'MusicGroup', 'name' => $artist] : null,
                    'image' => $image,
                ],
            ]);
        }
    }
}

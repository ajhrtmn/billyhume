<?php
if (!defined('ABSPATH')) exit;

/**
 * Track/release admin metaboxes. Genres need none: bhs_genre's show_ui
 * taxonomy already provides the tag picker.
 */
class BHS_Admin {
    public static function init(): void {
        add_action('add_meta_boxes', [self::class, 'add_meta_boxes']);
        add_action('save_post_bhs_track', [self::class, 'save_track']);
        add_action('save_post_bhs_track', [self::class, 'save_quality']);
        add_action('save_post_bhs_track', [self::class, 'save_lyrics']);
        add_action('save_post_bhs_release', [self::class, 'save_release']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_media']);

        // Columns via the shared OUS_ListTable helper.
        OUS_ListTable::register('bhs_track', [
            'bhs_artist' => 'Artist', 'bhs_audio' => 'Audio', 'bhs_plays' => 'Plays', 'bhs_flags' => 'Flags',
        ], [self::class, 'column_content']);
        add_action('wp_ajax_bhs_issue_isrc', [self::class, 'ajax_issue_isrc']);
    }

    /**
     * ISRC issuance is server-side so it can use BHS_ISRC's tracked sequence
     * and collision check rather than client-side randomness.
     */
    public static function ajax_issue_isrc(): void {
        check_ajax_referer('bhs_issue_isrc', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error(['message' => 'Not allowed.'], 403);
        $isrc = BHS_ISRC::issue();
        wp_send_json_success(['isrc' => $isrc, 'is_mock' => BHS_ISRC::is_mock($isrc)]);
    }

    public static function enqueue_media(string $hook): void {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;
        if (!in_array(get_post_type(), ['bhs_track', 'bhs_release'], true)) return;
        wp_enqueue_media();
    }

    // Three fixed roles (lossless, high, standard), any format per role.
    // _bhs_audio_id remains the fallback when no tier is filled in.
    const QUALITY_LABELS = ['lossless' => 'Lossless (WAV / AIFF / FLAC)', 'high' => 'High (e.g. 320kbps MP3)', 'standard' => 'Standard (e.g. 128–192kbps MP3)'];

    public static function add_meta_boxes(): void {
        add_meta_box('bhs_track_details', 'Track Details', [self::class, 'render_track_metabox'], 'bhs_track', 'normal', 'high');
        add_meta_box('bhs_track_quality', 'Quality Encodes', [self::class, 'render_quality_metabox'], 'bhs_track', 'normal', 'default');
        add_meta_box('bhs_track_lyrics', 'Lyrics', [self::class, 'render_lyrics_metabox'], 'bhs_track', 'normal', 'default');
        add_meta_box('bhs_track_monetization', 'Monetization', [self::class, 'render_track_monetization_metabox'], 'bhs_track', 'normal', 'default');
        add_meta_box('bhs_release_details', 'Release Details', [self::class, 'render_release_metabox'], 'bhs_release', 'normal', 'high');
        add_meta_box('bhs_release_monetization', 'Monetization', [self::class, 'render_release_monetization_metabox'], 'bhs_release', 'normal', 'default');
    }

    /* ---------- monetization extension point ---------- */

    // Renders nothing itself: a do_action() hook point where an optional
    // plugin (bh-monetization-woo) injects its own UI. A no-op otherwise.
    public static function render_track_monetization_metabox(\WP_Post $post): void {
        if (!has_action('bhs_track_monetization_ui')) {
            echo '<p class="description">No monetization plugin is active. Install <strong>BH Monetization (WooCommerce)</strong> to sell this track, gate it behind a supporter tier, or accept tips.</p>';
            return;
        }
        do_action('bhs_track_monetization_ui', $post);
    }

    public static function render_release_monetization_metabox(\WP_Post $post): void {
        if (!has_action('bhs_release_monetization_ui')) {
            echo '<p class="description">No monetization plugin is active. Install <strong>BH Monetization (WooCommerce)</strong> to sell this release, gate it behind a supporter tier, or accept tips.</p>';
            return;
        }
        do_action('bhs_release_monetization_ui', $post);
    }

    /* ---------- track metabox ---------- */

    public static function render_track_metabox(\WP_Post $post): void {
        wp_nonce_field('bhs_save_track', 'bhs_track_nonce');
        $artist  = get_post_meta($post->ID, '_bhs_artist', true);
        $aid     = (int) get_post_meta($post->ID, '_bhs_audio_id', true);
        $art_id  = (int) get_post_meta($post->ID, '_bhs_artwork_id', true);
        $aurl    = $aid ? wp_get_attachment_url($aid) : '';
        $art_url = $art_id ? wp_get_attachment_image_url($art_id, 'medium') : '';
        $release_id = (int) get_post_meta($post->ID, '_bhs_release_id', true);
        $is_external = get_post_meta($post->ID, '_bhs_source', true) === 'external';

        if ($is_external) {
            echo '<p class="description">This track was imported from an external feed — see Feed Sources. Audio and artist come from that feed and aren\'t editable here.</p>';
        }

        echo '<p><label><strong>Artist</strong><br><input type="text" name="bhs_artist" value="' . esc_attr($artist) . '" style="width:100%;" placeholder="Artist name"' . ($is_external ? ' disabled' : '') . '></label></p>';

        // ISRC feeds schema.org MusicRecording isrcCode (see
        // maybe_set_seo_data() in class-player.php).
        $isrc = get_post_meta($post->ID, '_bhs_isrc', true);
        $is_mock = (bool) get_post_meta($post->ID, '_bhs_isrc_is_mock', true);
        $has_registrant = class_exists('BHS_ISRC') && BHS_ISRC::is_real_registrant_configured();
        $gen_label = $has_registrant ? 'Generate ISRC' : 'Generate placeholder';
        echo '<p><label><strong>ISRC</strong> <span class="description">(International Standard Recording Code, optional)</span><br>'
           . '<input type="text" id="bhs_isrc_field" name="bhs_isrc" value="' . esc_attr($isrc) . '" style="width:75%;" placeholder="e.g. USRC17607839" pattern="[A-Za-z]{2}[A-Za-z0-9]{3}\d{2}\d{5}"> '
           . '<button type="button" class="button" id="bhs_isrc_mock_btn">' . esc_html($gen_label) . '</button> '
           . '<span id="bhs_isrc_spinner" style="display:none;">…</span></label></p>';
        if (!$has_registrant) {
            echo '<p class="description">No real ISRC registrant is on file yet — <a href="' . esc_url(admin_url('admin.php?page=bhs-isrc-registrant')) . '">set one up</a> once you\'ve completed the real registrant application, and this button starts issuing real codes instead of placeholders.</p>';
        }
        echo '<p class="description" id="bhs_isrc_mock_note" style="' . ($is_mock ? '' : 'display:none;') . 'color:#996800;">'
           . 'This is a placeholder, not a real registered ISRC — see BHS_ISRC::issue() in class-isrc.php. It won\'t be published in this track\'s structured data until replaced with a real code.</p>';
        echo '<script>
        document.getElementById("bhs_isrc_mock_btn").addEventListener("click", function () {
            var btn = this, field = document.getElementById("bhs_isrc_field"), note = document.getElementById("bhs_isrc_mock_note"), spinner = document.getElementById("bhs_isrc_spinner");
            btn.disabled = true; spinner.style.display = "";
            var body = new URLSearchParams({ action: "bhs_issue_isrc", nonce: ' . wp_json_encode(wp_create_nonce('bhs_issue_isrc')) . ' });
            fetch(ajaxurl, { method: "POST", body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    btn.disabled = false; spinner.style.display = "none";
                    if (!res.success) { alert((res.data && res.data.message) || "Could not issue an ISRC."); return; }
                    field.value = res.data.isrc;
                    note.style.display = res.data.is_mock ? "" : "none";
                })
                .catch(function () {
                    btn.disabled = false; spinner.style.display = "none";
                    alert("Could not reach the server — check your connection and try again.");
                });
        });
        document.getElementById("bhs_isrc_field").addEventListener("input", function () {
            var note = document.getElementById("bhs_isrc_mock_note");
            // Audit fix (2026-07-25): sourced from BHS_ISRC::MOCK_PATTERN
            // (the one PHP source of truth) instead of a second, hand-typed
            // JS literal that could silently drift from it.
            var mockPattern = new RegExp(' . wp_json_encode(trim(BHS_ISRC::MOCK_PATTERN, '/')) . ');
            if (!mockPattern.test(this.value)) note.style.display = "none";
        });
        </script>';

        echo '<p><strong>Release</strong> <span class="description">(optional — groups this track into an album/EP)</span></p>';
        echo '<select name="bhs_release_id"><option value="">— None —</option>';
        foreach (get_posts(['post_type' => 'bhs_release', 'post_status' => 'publish', 'posts_per_page' => -1]) as $r) {
            echo '<option value="' . esc_attr((string) $r->ID) . '" ' . selected($release_id, $r->ID, false) . '>' . esc_html($r->post_title) . '</option>';
        }
        echo '</select>';

        if (!$is_external) {
            echo '<p style="margin-top:14px;"><strong>Audio file</strong></p>';
            echo '<input type="hidden" id="bhs_audio_id" name="bhs_audio_id" value="' . esc_attr((string) $aid) . '">';
            echo '<div id="bhs_audio_preview">' . ($aurl ? "<audio controls src='" . esc_url($aurl) . "' style='width:100%;'></audio>" : '<p><em>No audio attached.</em></p>') . '</div>';
            echo '<p><button type="button" class="button" id="bhs_audio_upload">Choose audio…</button></p>';

            // Preview clip used by the public feed export for gated tracks
            // (whose real audio must never leak); a gated track with none is omitted.
            $preview_id = (int) get_post_meta($post->ID, '_bhs_preview_audio_id', true);
            $preview_url = $preview_id ? wp_get_attachment_url($preview_id) : '';
            echo '<p style="margin-top:14px;"><strong>Public preview clip</strong> <span class="description">— only used if this track requires supporter access. A short, separate teaser file you choose; the full file above is never exposed to the public feed/global library for a gated track.</span></p>';
            echo '<input type="hidden" id="bhs_preview_audio_id" name="bhs_preview_audio_id" value="' . esc_attr((string) $preview_id) . '">';
            echo '<div id="bhs_preview_audio_preview">' . ($preview_url ? "<audio controls src='" . esc_url($preview_url) . "' style='width:100%;'></audio>" : '<p><em>No preview set.</em></p>') . '</div>';
            echo '<p><button type="button" class="button" id="bhs_preview_audio_upload">Choose preview clip…</button></p>';
        }

        echo '<p><strong>Artwork</strong> <span class="description">(falls back to the release\'s artwork, or a generated placeholder)</span></p>';
        echo '<input type="hidden" id="bhs_artwork_id" name="bhs_artwork_id" value="' . esc_attr((string) $art_id) . '">';
        echo '<div id="bhs_artwork_preview" style="width:120px;height:120px;background:#f0f0f0;border-radius:6px;overflow:hidden;">' . ($art_url ? '<img src="' . esc_url($art_url) . '" style="width:100%;height:100%;object-fit:cover;">' : '') . '</div>';
        echo '<p><button type="button" class="button" id="bhs_artwork_upload">Choose artwork…</button></p>';

        self::render_media_picker_script();
    }

    /* ---------- quality encodes metabox ---------- */

    public static function render_quality_metabox(\WP_Post $post): void {
        $is_external = get_post_meta($post->ID, '_bhs_source', true) === 'external';
        if ($is_external) {
            echo '<p class="description">Not available for externally-imported tracks — this site doesn\'t host their audio at all, so there\'s nothing here to attach an alternate encode to.</p>';
            return;
        }
        wp_nonce_field('bhs_save_quality', 'bhs_quality_nonce');
        $qualities = json_decode((string) get_post_meta($post->ID, '_bhs_audio_qualities', true), true);
        if (!is_array($qualities)) $qualities = [];

        foreach (self::QUALITY_LABELS as $key => $label) {
            $aid = (int) ($qualities[$key] ?? 0);
            $aurl = $aid ? wp_get_attachment_url($aid) : '';
            echo '<div style="margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #dcdcde;">';
            echo '<p><strong>' . esc_html($label) . '</strong></p>';
            echo '<input type="hidden" id="bhs_quality_' . esc_attr($key) . '" name="bhs_quality[' . esc_attr($key) . ']" value="' . esc_attr((string) $aid) . '">';
            echo '<div id="bhs_quality_' . esc_attr($key) . '_preview">' . ($aurl ? "<audio controls src='" . esc_url($aurl) . "' style='width:100%;'></audio>" : '<p><em>Not set — falls back to the main audio file above.</em></p>') . '</div>';
            echo '<p><button type="button" class="button bhs-quality-upload" data-key="' . esc_attr($key) . '">Choose file…</button> '
               . '<button type="button" class="button-link bhs-quality-clear" data-key="' . esc_attr($key) . '" style="color:#b3261e;">Remove</button></p>';
            echo '</div>';
        }
        ?>
        <script>
        (function () {
            if (!window.wp || !window.wp.media) return;
            document.querySelectorAll('.bhs-quality-upload').forEach(function (btn) {
                if (btn.dataset.bhsBound) return;
                btn.dataset.bhsBound = '1';
                var key = btn.dataset.key;
                var frame = null;
                btn.addEventListener('click', function () {
                    if (frame) { frame.open(); return; }
                    frame = wp.media({ title: 'Choose an audio file', button: { text: 'Use this' }, multiple: false, library: { type: 'audio' } });
                    frame.on('select', function () {
                        var att = frame.state().get('selection').first().toJSON();
                        document.getElementById('bhs_quality_' + key).value = att.id;
                        document.getElementById('bhs_quality_' + key + '_preview').innerHTML =
                            '<audio controls src="' + att.url + '" style="width:100%;"></audio>';
                    });
                    frame.open();
                });
            });
            document.querySelectorAll('.bhs-quality-clear').forEach(function (btn) {
                if (btn.dataset.bhsBound) return;
                btn.dataset.bhsBound = '1';
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    var key = btn.dataset.key;
                    document.getElementById('bhs_quality_' + key).value = '';
                    document.getElementById('bhs_quality_' + key + '_preview').innerHTML = '<p><em>Not set — falls back to the main audio file above.</em></p>';
                });
            });
        })();
        </script>
        <?php
    }

    /* ---------- lyrics metabox ---------- */

    public static function render_lyrics_metabox(\WP_Post $post): void {
        wp_nonce_field('bhs_save_lyrics', 'bhs_lyrics_nonce');
        $plain = (string) get_post_meta($post->ID, '_bhs_lyrics_text', true);
        $lrc = (string) get_post_meta($post->ID, '_bhs_lyrics_lrc', true);

        echo '<p><strong>Synced lyrics (LRC format)</strong> <span class="description">— optional; one <code>[mm:ss.xx]</code>-prefixed line per line. Only source this from lyrics the artist/importer actually supplied with timing — this is not a lookup against a third-party lyrics database.</span></p>';
        echo '<textarea name="bhs_lyrics_lrc" rows="6" style="width:100%;font-family:monospace;">' . esc_textarea($lrc) . '</textarea>';

        echo '<p style="margin-top:12px;"><strong>Plain-text lyrics</strong> <span class="description">— shown as a fallback whenever synced timing isn\'t available.</span></p>';
        echo '<textarea name="bhs_lyrics_text" rows="8" style="width:100%;">' . esc_textarea($plain) . '</textarea>';
    }

    /* ---------- release metabox ---------- */

    public static function render_release_metabox(\WP_Post $post): void {
        wp_nonce_field('bhs_save_release', 'bhs_release_nonce');
        $artist = get_post_meta($post->ID, '_bhs_release_artist', true);
        $art_id = (int) get_post_meta($post->ID, '_bhs_release_artwork_id', true);
        $art_url = $art_id ? wp_get_attachment_image_url($art_id, 'medium') : '';

        echo '<p><label><strong>Artist</strong><br><input type="text" name="bhs_release_artist" value="' . esc_attr($artist) . '" style="width:100%;"></label></p>';
        echo '<p><strong>Artwork</strong></p>';
        echo '<input type="hidden" id="bhs_artwork_id" name="bhs_release_artwork_id" value="' . esc_attr((string) $art_id) . '">';
        echo '<div id="bhs_artwork_preview" style="width:160px;height:160px;background:#f0f0f0;border-radius:6px;overflow:hidden;">' . ($art_url ? '<img src="' . esc_url($art_url) . '" style="width:100%;height:100%;object-fit:cover;">' : '') . '</div>';
        echo '<p><button type="button" class="button" id="bhs_artwork_upload">Choose artwork…</button></p>';

        self::render_media_picker_script();
    }

    private static function render_media_picker_script(): void {
        ?>
        <script>
        (function () {
            function pick(buttonId, hiddenId, previewId, isImage) {
                var btn = document.getElementById(buttonId);
                if (!btn || btn.dataset.bhsBound || !window.wp || !window.wp.media) return;
                btn.dataset.bhsBound = '1';
                var frame = null;
                btn.addEventListener('click', function () {
                    if (frame) { frame.open(); return; }
                    frame = wp.media({ title: 'Choose a file', button: { text: 'Use this' }, multiple: false, library: isImage ? { type: 'image' } : { type: 'audio' } });
                    frame.on('select', function () {
                        var att = frame.state().get('selection').first().toJSON();
                        document.getElementById(hiddenId).value = att.id;
                        var preview = document.getElementById(previewId);
                        if (isImage) {
                            preview.innerHTML = '<img src="' + (att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url) + '" style="width:100%;height:100%;object-fit:cover;">';
                        } else {
                            preview.innerHTML = '<audio controls src="' + att.url + '" style="width:100%;"></audio>';
                        }
                    });
                    frame.open();
                });
            }
            pick('bhs_audio_upload', 'bhs_audio_id', 'bhs_audio_preview', false);
            pick('bhs_preview_audio_upload', 'bhs_preview_audio_id', 'bhs_preview_audio_preview', false);
            pick('bhs_artwork_upload', 'bhs_artwork_id', 'bhs_artwork_preview', true);
        })();
        </script>
        <?php
    }

    /* ---------- saving ---------- */

    public static function save_track(int $post_id): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['bhs_track_nonce']) || !wp_verify_nonce($_POST['bhs_track_nonce'], 'bhs_save_track')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $is_external = get_post_meta($post_id, '_bhs_source', true) === 'external';
        if (!$is_external) {
            if (isset($_POST['bhs_artist']))   update_post_meta($post_id, '_bhs_artist', sanitize_text_field($_POST['bhs_artist']));
            if (isset($_POST['bhs_audio_id'])) update_post_meta($post_id, '_bhs_audio_id', (int) $_POST['bhs_audio_id']);
            if (isset($_POST['bhs_preview_audio_id'])) update_post_meta($post_id, '_bhs_preview_audio_id', (int) $_POST['bhs_preview_audio_id']);
        }
        if (isset($_POST['bhs_artwork_id']))  update_post_meta($post_id, '_bhs_artwork_id', (int) $_POST['bhs_artwork_id']);
        if (isset($_POST['bhs_release_id']))  update_post_meta($post_id, '_bhs_release_id', (int) $_POST['bhs_release_id']);
        // Editable even on imported tracks: the rights holder assigns ISRC.
        if (isset($_POST['bhs_isrc'])) {
            $isrc_val = sanitize_text_field($_POST['bhs_isrc']);
            update_post_meta($post_id, '_bhs_isrc', $isrc_val);
            // Mock status re-derived server-side via BHS_ISRC::is_mock(), never from POST.
            update_post_meta($post_id, '_bhs_isrc_is_mock', class_exists('BHS_ISRC') && BHS_ISRC::is_mock($isrc_val) ? 1 : 0);
        }

        // Lets the monetization plugin verify its own nonce and save its own meta.
        do_action('bhs_track_monetization_save', $post_id);
    }

    // Separate nonce/hook from save_track(): its own add_meta_box() registration.
    public static function save_quality(int $post_id): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['bhs_quality_nonce']) || !wp_verify_nonce($_POST['bhs_quality_nonce'], 'bhs_save_quality')) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (get_post_meta($post_id, '_bhs_source', true) === 'external') return; // metabox isn't rendered for these; belt and suspenders

        $submitted = $_POST['bhs_quality'] ?? [];
        if (!is_array($submitted)) return;

        $qualities = [];
        foreach (array_keys(self::QUALITY_LABELS) as $key) {
            $aid = (int) ($submitted[$key] ?? 0);
            if ($aid) $qualities[$key] = $aid;
        }
        update_post_meta($post_id, '_bhs_audio_qualities', wp_json_encode($qualities));
    }

    public static function save_lyrics(int $post_id): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['bhs_lyrics_nonce']) || !wp_verify_nonce($_POST['bhs_lyrics_nonce'], 'bhs_save_lyrics')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        // Strips tags but keeps line breaks, as plain lyrics and LRC need.
        if (isset($_POST['bhs_lyrics_lrc']))  update_post_meta($post_id, '_bhs_lyrics_lrc', sanitize_textarea_field($_POST['bhs_lyrics_lrc']));
        if (isset($_POST['bhs_lyrics_text'])) update_post_meta($post_id, '_bhs_lyrics_text', sanitize_textarea_field($_POST['bhs_lyrics_text']));
    }

    public static function save_release(int $post_id): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['bhs_release_nonce']) || !wp_verify_nonce($_POST['bhs_release_nonce'], 'bhs_save_release')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (isset($_POST['bhs_release_artist']))     update_post_meta($post_id, '_bhs_release_artist', sanitize_text_field($_POST['bhs_release_artist']));
        if (isset($_POST['bhs_release_artwork_id'])) update_post_meta($post_id, '_bhs_release_artwork_id', (int) $_POST['bhs_release_artwork_id']);

        do_action('bhs_release_monetization_save', $post_id);
    }

    /* ---------- list table ---------- */

    public static function column_content(string $col, int $post_id): void {
        if ($col === 'bhs_artist') echo esc_html(get_post_meta($post_id, '_bhs_artist', true));
        if ($col === 'bhs_audio') {
            $has_audio = (bool) BHS_API::audio_url_for($post_id);
            echo $has_audio ? '<span style="color:#1DB954;">&#10003; attached</span>' : '<span style="color:#b3261e;">missing</span>';
        }
        if ($col === 'bhs_plays') echo esc_html((string) (int) get_post_meta($post_id, '_bhs_play_count', true));
        if ($col === 'bhs_flags' && class_exists('BHS_AudioHash')) echo BHS_AudioHash::flag_notice_html($post_id);
    }
}

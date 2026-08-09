<?php
if (!defined('ABSPATH')) exit;

/**
 * Registry contribution only — bh-live's actual settings UI lives
 * inside OUS_MediaWizard (own-ur-shit/includes/class-media-wizard.php),
 * not a separate admin screen, per the "one place for setup" call in
 * wondrous-mixing-forest.md: live-engine setup is one more step in the
 * same wizard that already handles storage/CDN, not a second onboarding
 * flow to discover.
 */
class BHL_Admin {
    public static function init() {
        add_filter('ous_registered_plugins', [self::class, 'register']);
        add_action('add_meta_boxes', [self::class, 'add_meta_boxes']);
        add_action('save_post_bhl_stream', [self::class, 'save_replay']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_media']);
        add_action('admin_menu', [self::class, 'add_automation_page']);
    }

    // Broadcaster-level, not per-stream — a submenu page rather than a
    // second wizard step, since OUS_MediaWizard's own "one place for
    // setup" call (see class docblock above) covers engine/host/chat
    // choice, not a per-user OBS connection + rule list a broadcaster
    // will come back and edit repeatedly.
    public static function add_automation_page() {
        add_submenu_page('edit.php?post_type=bhl_stream', 'OBS Automation', 'OBS Automation', 'edit_posts', 'bhl-obs-automation', [self::class, 'render_automation_page']);
    }

    public static function render_automation_page() {
        $rest = esc_url_raw(rest_url('bhl/v1/'));
        $nonce = wp_create_nonce('wp_rest');
        echo '<div class="wrap"><h1>OBS Automation</h1>
        <p class="description">Connects a local OBS instance to real ecosystem events (a contest round advancing, a vote, a purchase…) so scenes switch automatically. Runs through a hidden Browser Source, not a separate app — see the setup note below once your connection is saved.</p>
        <h2>OBS connection</h2>
        <table class="form-table">
            <tr><th>Host</th><td><input type="text" id="bhl_obs_host" placeholder="127.0.0.1"></td></tr>
            <tr><th>Port</th><td><input type="number" id="bhl_obs_port" placeholder="4455"></td></tr>
            <tr><th>Password</th><td><input type="password" id="bhl_obs_password" placeholder="(leave blank to keep current)"></td></tr>
        </table>
        <p><button type="button" class="button button-primary" id="bhl_obs_save_settings">Save connection</button> <span id="bhl_obs_settings_status"></span></p>
        <p><strong>Automation Browser Source URL</strong> (add this once as a Browser Source in OBS, any size — it never needs to be visible):<br>
        <input type="text" readonly id="bhl_obs_bridge_url" style="width:100%;max-width:640px;" onclick="this.select();"></p>

        <h2>Chat sources</h2>
        <p class="description">Pulls Twitch/YouTube chat into this ecosystem\'s own chat and overlay — read-only, no posting back. Twitch needs no account/app of its own (anonymous read); YouTube needs a free API key from Google Cloud Console. Leave either blank to skip it.</p>
        <table class="form-table">
            <tr><th>Twitch channel</th><td><input type="text" id="bhl_chat_twitch" placeholder="your_channel_name"></td></tr>
            <tr><th>YouTube API key</th><td><input type="password" id="bhl_chat_yt_key" placeholder="(leave blank to keep current)"></td></tr>
            <tr><th>YouTube live video ID</th><td><input type="text" id="bhl_chat_yt_video" placeholder="the 11-character ID from your live video\'s URL"></td></tr>
        </table>
        <p><button type="button" class="button button-primary" id="bhl_chat_save_sources">Save chat sources</button> <span id="bhl_chat_sources_status"></span></p>

        <h2>Rules</h2>
        <table class="widefat" id="bhl_obs_rules_table"><thead><tr><th>Event</th><th>Action</th><th>Target</th><th></th></tr></thead><tbody></tbody></table>
        <h3>Add a rule</h3>
        <p>
            <select id="bhl_obs_new_event"></select>
            <select id="bhl_obs_new_action"></select>
            <input type="text" id="bhl_obs_new_target" placeholder="Scene name, or Scene:Source for &quot;Enable a source&quot;">
            <button type="button" class="button" id="bhl_obs_add_rule">Add rule</button>
        </p>
        </div>
        <script>
        (function () {
            var rest = ' . wp_json_encode($rest) . ';
            var nonce = ' . wp_json_encode($nonce) . ';
            function api(path, method, body) {
                return fetch(rest + path, {
                    method: method || "GET",
                    headers: Object.assign({"Content-Type": "application/json"}, method && method !== "GET" ? {"X-WP-Nonce": nonce} : {"X-WP-Nonce": nonce}),
                    body: body ? JSON.stringify(body) : undefined,
                }).then(function (r) { return r.json(); });
            }

            function refreshRules() {
                api("automation/rules").then(function (res) {
                    if (!res || !res.success) return;
                    var evtSel = document.getElementById("bhl_obs_new_event");
                    var actSel = document.getElementById("bhl_obs_new_action");
                    if (!evtSel.dataset.filled) {
                        evtSel.dataset.filled = "1";
                        Object.keys(res.event_types).forEach(function (k) {
                            var o = document.createElement("option"); o.value = k; o.textContent = res.event_types[k]; evtSel.appendChild(o);
                        });
                        Object.keys(res.actions).forEach(function (k) {
                            var o = document.createElement("option"); o.value = k; o.textContent = res.actions[k]; actSel.appendChild(o);
                        });
                    }
                    var tbody = document.querySelector("#bhl_obs_rules_table tbody");
                    tbody.innerHTML = "";
                    res.rules.forEach(function (r) {
                        var tr = document.createElement("tr");
                        tr.innerHTML = "<td></td><td></td><td></td><td><button type=\"button\" class=\"button-link-delete\">Remove</button></td>";
                        tr.children[0].textContent = res.event_types[r.event_type] || r.event_type;
                        tr.children[1].textContent = res.actions[r.action] || r.action;
                        tr.children[2].textContent = r.target;
                        tr.children[3].querySelector("button").addEventListener("click", function () {
                            api("automation/rules/" + r.id, "DELETE").then(refreshRules);
                        });
                        tbody.appendChild(tr);
                    });
                });
            }

            document.getElementById("bhl_obs_add_rule").addEventListener("click", function () {
                var target = document.getElementById("bhl_obs_new_target").value.trim();
                if (!target) return;
                api("automation/rules", "POST", {
                    event_type: document.getElementById("bhl_obs_new_event").value,
                    action: document.getElementById("bhl_obs_new_action").value,
                    target: target,
                }).then(function () {
                    document.getElementById("bhl_obs_new_target").value = "";
                    refreshRules();
                });
            });

            document.getElementById("bhl_obs_save_settings").addEventListener("click", function () {
                api("automation/settings", "POST", {
                    host: document.getElementById("bhl_obs_host").value,
                    port: document.getElementById("bhl_obs_port").value,
                    password: document.getElementById("bhl_obs_password").value,
                }).then(function (res) {
                    document.getElementById("bhl_obs_settings_status").textContent = res && res.success ? "Saved." : "Failed to save.";
                    loadSettings();
                });
            });

            function loadSettings() {
                api("automation/settings").then(function (res) {
                    if (!res || !res.success) return;
                    document.getElementById("bhl_obs_host").value = res.settings.host;
                    document.getElementById("bhl_obs_port").value = res.settings.port;
                    document.getElementById("bhl_obs_bridge_url").value = rest + "overlay/0/automation-bridge?token=" + encodeURIComponent(res.settings.token);
                });
            }

            function loadChatSources() {
                api("automation/chat-sources").then(function (res) {
                    if (!res || !res.success) return;
                    document.getElementById("bhl_chat_twitch").value = res.sources.twitch_channel || "";
                    document.getElementById("bhl_chat_yt_video").value = res.sources.youtube_video_id || "";
                    document.getElementById("bhl_chat_yt_key").placeholder = res.sources.youtube_api_key_set ? "(leave blank to keep current)" : "(none set)";
                });
            }

            document.getElementById("bhl_chat_save_sources").addEventListener("click", function () {
                api("automation/chat-sources", "POST", {
                    twitch_channel: document.getElementById("bhl_chat_twitch").value,
                    youtube_api_key: document.getElementById("bhl_chat_yt_key").value,
                    youtube_video_id: document.getElementById("bhl_chat_yt_video").value,
                }).then(function (res) {
                    document.getElementById("bhl_chat_sources_status").textContent = res && res.success ? "Saved." : "Failed to save.";
                    document.getElementById("bhl_chat_yt_key").value = "";
                    loadChatSources();
                });
            });

            loadSettings();
            loadChatSources();
            refreshRules();
        })();
        </script>';
    }

    public static function enqueue_media($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;
        if (get_post_type() !== 'bhl_stream') return;
        wp_enqueue_media();
    }

    public static function add_meta_boxes() {
        add_meta_box('bhl_stream_details', 'Stream Details', [self::class, 'render_details_metabox'], 'bhl_stream', 'normal', 'high');
        add_meta_box('bhl_stream_overlays', 'OBS Overlays', [self::class, 'render_overlays_metabox'], 'bhl_stream', 'normal', 'default');
        add_meta_box('bhl_stream_replay', 'Replay', [self::class, 'render_replay_metabox'], 'bhl_stream', 'normal', 'default');
    }

    /**
     * ROADMAP-obs-integration.md Phase 1 — the URLs a broadcaster pastes
     * into an OBS Browser Source, plus a live health check so "is OBS
     * actually able to pull this" is answered here instead of guessed
     * at (the #1 documented StreamElements complaint this was scoped to
     * avoid). Votes overlay needs a contest slug/id typed in by hand —
     * bhl_stream has no stored link to a bh-contest post, so this stays
     * a plain input rather than a new meta field for a v1 that may
     * never need one.
     */
    public static function render_overlays_metabox($post) {
        $chat_url = rest_url('bhl/v1/overlay/' . $post->ID . '/chat');
        $health_url = rest_url('bhl/v1/overlay/' . $post->ID . '/health');
        echo '<p><label><strong>Chat overlay URL</strong><br>'
            . '<input type="text" readonly style="width:100%;" value="' . esc_attr($chat_url) . '" onclick="this.select();"></label></p>';
        echo '<p id="bhl_overlay_health">Checking overlay status…</p>';

        if (class_exists('BH_API')) {
            echo '<p><label><strong>Contest votes overlay</strong> — enter a contest slug or ID:<br>'
                . '<input type="text" id="bhl_votes_contest" placeholder="e.g. summer-2026" style="width:200px;">'
                . '</label><br><input type="text" readonly id="bhl_votes_url" style="width:100%;margin-top:4px;" value="" onclick="this.select();"></p>';
            echo '<script>
            (function () {
                var input = document.getElementById("bhl_votes_contest");
                var out = document.getElementById("bhl_votes_url");
                var base = ' . wp_json_encode(rest_url('bhl/v1/overlay/')) . ';
                input.addEventListener("input", function () {
                    out.value = input.value ? base + encodeURIComponent(input.value) + "/votes" : "";
                });
            })();
            </script>';
        } else {
            echo '<p class="description">Contest votes overlay needs bh-contest active.</p>';
        }

        echo '<script>
        (function () {
            fetch(' . wp_json_encode($health_url) . ')
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    var el = document.getElementById("bhl_overlay_health");
                    if (res && res.configured) {
                        el.innerHTML = "● <span style=\"color:#2a2;\">Chat overlay ready</span>";
                    } else if (res && res.stream_exists) {
                        el.innerHTML = "● <span style=\"color:#a90;\">Stream found, but no chat engine is configured yet</span>";
                    } else {
                        el.innerHTML = "● <span style=\"color:#c33;\">Not reachable — save this stream first</span>";
                    }
                })
                .catch(function () {
                    document.getElementById("bhl_overlay_health").innerHTML = "● <span style=\"color:#c33;\">Health check failed</span>";
                });
        })();
        </script>';
    }

    public static function render_details_metabox($post) {
        $status = get_post_meta($post->ID, '_bhl_status', true) ?: BHL_PostTypes::STATUS_LIVE;
        $started = get_post_meta($post->ID, '_bhl_started_at', true);
        $ended = get_post_meta($post->ID, '_bhl_ended_at', true);
        echo '<p><strong>Status:</strong> ' . ($status === BHL_PostTypes::STATUS_LIVE ? '&#128308; Live' : '&#9899; Ended (replay)') . '</p>';
        echo '<p><strong>Started:</strong> ' . esc_html($started ?: '—') . '</p>';
        if ($status !== BHL_PostTypes::STATUS_LIVE) echo '<p><strong>Ended:</strong> ' . esc_html($ended ?: '—') . '</p>';
        echo '<p class="description">This record is created and closed automatically by BHL_Streams\' own polling check — status/timestamps aren\'t editable by hand here.</p>';
    }

    /**
     * The one manual step in bh-live's whole VOD story. Owncast's own
     * recording-to-file feature (a real, documented Owncast setting)
     * writes the finished recording to THAT server's own local disk —
     * its public API has no endpoint to fetch that file remotely, so
     * there is no honest way to auto-import it into this WordPress
     * install without either direct filesystem/SFTP access to the
     * Owncast box (out of scope for a "thin integration" plugin) or a
     * custom upload step Owncast itself doesn't provide. Until that
     * changes, whoever runs the Owncast server downloads the finished
     * recording and uploads it here — same real wp.media video-type
     * picker bh-video's own admin metabox already uses, so at least
     * the WordPress-side half of this is exactly as easy as it can be.
     */
    public static function render_replay_metabox($post) {
        wp_nonce_field('bhl_save_replay', 'bhl_replay_nonce');
        $status = get_post_meta($post->ID, '_bhl_status', true);
        if ($status === BHL_PostTypes::STATUS_LIVE) {
            echo '<p class="description">Still live — a replay can be attached once this stream ends.</p>';
            return;
        }
        $vid = (int) get_post_meta($post->ID, '_bhl_replay_attachment_id', true);
        $vurl = $vid ? wp_get_attachment_url($vid) : '';
        echo '<p class="description">Owncast writes its recording to its own server — download the finished file from there and upload it here to make it the public replay.</p>';
        echo '<div id="bhl_replay_preview" style="max-width:480px;margin-bottom:8px;">' . ($vurl ? '<video controls src="' . esc_url($vurl) . '" style="width:100%;"></video>' : '<em>No replay uploaded yet.</em>') . '</div>';
        echo '<input type="hidden" id="bhl_replay_id" name="bhl_replay_id" value="' . esc_attr((string) $vid) . '">';
        echo '<button type="button" class="button" id="bhl_replay_upload">' . ($vurl ? 'Replace replay' : 'Upload replay') . '</button></p>';
        echo '<script>
        (function () {
            var btn = document.getElementById("bhl_replay_upload");
            if (!btn || btn.dataset.bhlBound || !window.wp || !window.wp.media) return;
            btn.dataset.bhlBound = "1";
            var frame = null;
            btn.addEventListener("click", function () {
                if (frame) { frame.open(); return; }
                frame = wp.media({ title: "Choose the replay video", button: { text: "Use this video" }, multiple: false, library: { type: "video" } });
                frame.on("select", function () {
                    var att = frame.state().get("selection").first().toJSON();
                    document.getElementById("bhl_replay_id").value = att.id;
                    document.getElementById("bhl_replay_preview").innerHTML = "<video controls src=\"" + att.url + "\" style=\"width:100%;\"></video>";
                    btn.textContent = "Replace replay";
                });
                frame.open();
            });
        })();
        </script>';
    }

    public static function save_replay($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['bhl_replay_nonce']) || !wp_verify_nonce($_POST['bhl_replay_nonce'], 'bhl_save_replay')) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (isset($_POST['bhl_replay_id'])) {
            update_post_meta($post_id, '_bhl_replay_attachment_id', (int) $_POST['bhl_replay_id']);
        }
    }

    public static function register($plugins) {
        $plugins['bh-live'] = [
            'label'          => 'BH Live',
            'file'           => 'bh-live/bh-live.php',
            'depends_on'     => [],
            'check_class'    => 'BHL_OwncastEngine',
            'description'    => 'Two-way interactive live streaming via a self-hosted Owncast server, configured from the Media & CDN Setup wizard.',
            'dashboard_link' => 'admin.php?page=ous-media-setup',
            'bundled_zip'    => 'bh-live.zip',
        ];
        return $plugins;
    }
}

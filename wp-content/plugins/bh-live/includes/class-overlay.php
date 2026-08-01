<?php
if (!defined('ABSPATH')) exit;

/**
 * Phase 1 of ROADMAP-obs-integration.md — Browser Source overlay pages
 * a broadcaster pastes straight into OBS. Each route returns a bare,
 * transparent-background HTML page (not JSON), styled from
 * BHY_Style::inline_css() so it matches the site's own tokens with zero
 * configuration, per that roadmap's "sane defaults, not a blank canvas"
 * principle.
 *
 * The chat overlay reuses window.BHLChatWidget (assets/js/chat.js)
 * as-is — same 2-4s polling client the on-page [bh_live] widget already
 * uses via live-player.js, just mounted directly instead of behind a
 * status-fetch gate. No new polling logic, one implementation for both
 * surfaces.
 *
 * The votes overlay is guarded by class_exists('BH_API') — bh-contest
 * is an optional peer plugin, same discipline every cross-plugin
 * integration in this ecosystem follows — and 404s cleanly if it's not
 * active rather than fataling.
 */
class BHL_Overlay {
    public static function register_routes() {
        register_rest_route('bhl/v1', '/overlay/(?P<stream_id>\d+)/chat', [
            'methods' => 'GET', 'callback' => [self::class, 'render_chat'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route('bhl/v1', '/overlay/(?P<contest>[a-zA-Z0-9_-]+)/votes', [
            'methods' => 'GET', 'callback' => [self::class, 'render_votes'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route('bhl/v1', '/overlay/(?P<stream_id>\d+)/health', [
            'methods' => 'GET', 'callback' => [self::class, 'health'], 'permission_callback' => '__return_true',
        ]);
        // Phase 2 — the hidden Browser Source that bridges local OBS
        // control to ecosystem events. Public route (permission_callback
        // '__return_true') same as the others — the real gate is the
        // ?token= query param BHL_Automation::get_events() checks, since
        // OBS's embedded Chromium has no WordPress login session to
        // check here (see class-automation.php's own docblock).
        register_rest_route('bhl/v1', '/overlay/(?P<stream_id>\d+)/automation-bridge', [
            'methods' => 'GET', 'callback' => [self::class, 'render_automation_bridge'], 'permission_callback' => '__return_true',
        ]);
    }

    // WP_REST_Response always JSON-encodes its data through the REST
    // server, so a raw HTML string can't just be handed to it as
    // $data — headers alone don't change that serialization. Overlay
    // pages bypass the REST response cycle entirely instead, matching
    // the one honest way to serve arbitrary HTML from within a
    // register_rest_route callback.
    private static function output_html($html) {
        status_header(200);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    private static function page_shell($title, $body_html, $extra_css = '') {
        $css = class_exists('BHY_Style') ? BHY_Style::inline_css() : '';
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . esc_html($title) . '</title>'
            . '<style>html,body{margin:0;padding:0;background:transparent;overflow:hidden;}' . $css . $extra_css . '</style>'
            . '</head><body>' . $body_html . '</body></html>';
    }

    public static function render_chat($req) {
        $stream_id = (int) $req->get_param('stream_id');
        if (get_post_type($stream_id) !== 'bhl_stream') {
            self::output_html(self::page_shell('Stream not found', '<p style="color:#f88;font-family:sans-serif;">Stream not found.</p>'));
        }

        $extra_css = '.bhl-chat-slot{width:420px;height:600px;font-family:sans-serif;}
            .bhl-chat-log{overflow-y:auto;} .bhl-chat-message{padding:2px 6px;}
            .bhl-chat-form{display:none;}
            .bhl-chat-source-tag{display:inline-block;font-size:10px;font-weight:700;text-transform:uppercase;padding:1px 5px;border-radius:3px;margin-right:2px;background:#333;color:#ccc;}
            .bhl-chat-message--twitch .bhl-chat-source-tag{background:#9146ff;color:#fff;}
            .bhl-chat-message--youtube .bhl-chat-source-tag{background:#f00;color:#fff;}'; // overlay is display-only — OBS can't type into it
        $body = '<div class="bhl-chat-slot" id="bhl-overlay-chat"></div>';

        // Manual <script> tags rather than wp_enqueue_script()/wp_print_scripts()
        // — this REST callback never goes through wp_head/wp_footer, so
        // there's no guarantee the enqueue would ever actually print.
        $data_json = wp_json_encode([
            'rest' => esc_url_raw(rest_url('bhl/v1/')), 'nonce' => '', 'loggedIn' => false,
        ]);
        $scripts = '<script>var BHLData = ' . $data_json . ';</script>'
            . '<script src="' . esc_url(BHL_URL . 'assets/js/chat.js?ver=' . BHL_VER) . '"></script>'
            . '<script>window.addEventListener("DOMContentLoaded",function(){window.BHLChatWidget.mount(document.getElementById("bhl-overlay-chat"),' . $stream_id . ');});</script>';

        self::output_html(self::page_shell('Stream chat', $body . $scripts, $extra_css));
    }

    public static function render_votes($req) {
        if (!class_exists('BH_API')) {
            self::output_html(self::page_shell('Contest votes', '<p style="color:#f88;font-family:sans-serif;">bh-contest is not active.</p>'));
        }
        $contest = sanitize_text_field((string) $req->get_param('contest'));

        $extra_css = '.bhl-votes{font-family:sans-serif;color:var(--bh-text,#fff);min-width:280px;}
            .bhl-votes h4{margin:0 0 6px;} .bhl-votes ol{margin:0;padding-left:1.4em;}
            .bhl-votes li{padding:2px 0;}';
        $body = '<div class="bhl-votes" id="bhl-overlay-votes"><h4>Loading…</h4></div>';

        $boot = '<script>
        (function () {
            var el = document.getElementById("bhl-overlay-votes");
            var url = ' . wp_json_encode(esc_url_raw(rest_url('bh/v1/results'))) . ' + "?contest=" + encodeURIComponent(' . wp_json_encode($contest) . ');
            function poll() {
                fetch(url).then(function (r) { return r.json(); }).then(function (res) {
                    if (!res || !res.success) { el.innerHTML = "<p>Results not published yet.</p>"; return; }
                    var html = "";
                    (res.categories || []).forEach(function (cat) {
                        html += "<h4>" + (cat.name || "Results") + "</h4><ol>";
                        (cat.results || []).slice(0, 5).forEach(function (row) {
                            html += "<li>" + (row.title || "") + " — " + row.votes + "</li>";
                        });
                        html += "</ol>";
                    });
                    el.innerHTML = html || "<p>No results yet.</p>";
                }).catch(function () {});
            }
            poll();
            setInterval(poll, 10000);
        })();
        </script>';

        self::output_html(self::page_shell('Contest votes', $body . $boot, $extra_css));
    }

    // The invisible Browser Source that connects out to the
    // broadcaster's own local OBS WebSocket and polls
    // BHL_Automation::get_events() for triggers — see that class's
    // docblock for why this is token-gated rather than session-gated.
    public static function render_automation_bridge($req) {
        $token = (string) $req->get_param('token');
        $user_id = class_exists('BHL_Automation') ? BHL_Automation::user_for_token($token) : 0;
        if (!$user_id) {
            self::output_html(self::page_shell('OBS Automation', '<p style="color:#f88;font-family:sans-serif;">Invalid or missing token — copy the URL again from the OBS Automation settings page.</p>'));
        }
        $settings = BHL_Automation::settings($user_id);
        $chat_sources = BHL_Automation::chat_sources($user_id);
        // {stream_id} in this route's own URL is NOT used as the relay
        // target — the bridge is added to OBS once, well before a real
        // bhl_stream post necessarily exists for that broadcast (that
        // post is only created once BHL_Streams' own polling job
        // notices the engine went live). obs-bridge.js instead resolves
        // the CURRENT live stream_id itself by polling the already-
        // public bhl/v1/status endpoint, same one the [bh_live]
        // shortcode's own status check already uses.

        $data_json = wp_json_encode([
            'rest'           => esc_url_raw(rest_url('bhl/v1/')),
            'token'          => $token,
            'obsHost'        => $settings['host'],
            'obsPort'        => (int) $settings['port'],
            'obsPassword'    => $settings['password'],
            // Phase 3 — each independently optional; obs-bridge.js skips
            // whichever one is blank rather than erroring.
            'twitchChannel'  => $chat_sources['twitch_channel'],
            'youtubeApiKey'  => $chat_sources['youtube_api_key'],
            'youtubeVideoId' => $chat_sources['youtube_video_id'],
        ]);
        $body = '<div id="bhl-automation-status" style="font-family:monospace;font-size:11px;color:#0f0;background:#000;padding:6px;">Connecting…</div>';
        $scripts = '<script>var BHLAutomationData = ' . $data_json . ';</script>'
            . '<script src="' . esc_url(BHL_URL . 'assets/js/obs-bridge.js?ver=' . BHL_VER) . '"></script>';

        self::output_html(self::page_shell('OBS Automation', $body . $scripts));
    }

    public static function health($req) {
        $stream_id = (int) $req->get_param('stream_id');
        $stream_exists = get_post_type($stream_id) === 'bhl_stream';
        $chat_configured = false;
        if ($stream_exists && class_exists('BHL_EngineRegistry')) {
            $chat = BHL_EngineRegistry::active_chat();
            $chat_configured = $chat && $chat->is_configured();
        }
        return new WP_REST_Response([
            'success'           => true,
            'stream_exists'     => $stream_exists,
            'configured'        => $stream_exists && $chat_configured,
            'checked_at'        => current_time('mysql'),
        ], 200);
    }
}

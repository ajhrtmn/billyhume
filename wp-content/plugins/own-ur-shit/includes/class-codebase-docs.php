<?php
if (!defined('ABSPATH')) exit;

/**
 * Compiles the hand-written codebase curriculum (CODEBASE-WALKTHROUGH.md,
 * one directory above the plugins themselves) into an in-admin, browsable
 * reference — AND keeps its file:line references honest by fetching the
 * REAL, CURRENT contents of the referenced file live, on click, rather
 * than trusting a snippet that was pasted into the doc once and can go
 * stale the moment the underlying code changes.
 *
 * Deliberately does NOT duplicate OUS_ApiDocs' viewer or pull in a
 * Swagger-UI bundle — that page already generates real OpenAPI 3.0 live
 * from the route table and is linked from the top of this one, keeping
 * this ecosystem's existing "no external JS/CDN, no build step" viewer
 * convention intact rather than quietly replacing it. This class is
 * specifically the CODE-LEVEL walkthrough, not the endpoint reference.
 *
 * Same production lock as API Docs/Debug Tools (OUS_Debug::is_locked()) —
 * this is dev/reference tooling, not something a real visitor ever needs.
 */
class OUS_CodebaseDocs {
    // Only files inside the plugins root are ever readable through the
    // live-snippet endpoint below, and only these extensions — never
    // wp-config.php, never anything outside this directory tree, no
    // matter what a crafted request asks for.
    const ALLOWED_EXTENSIONS = ['php', 'md'];

    public static function init() {
        // add_menu() (standalone admin.php?page=ous-codebase-docs page)
        // is deliberately NOT hooked anymore — confirmed via Query
        // Monitor on the live install that WordPress's own page-hook
        // resolution fails for this specific standalone page
        // (get_current_screen() resolves to the PARENT page's hook, not
        // this one), denying access every time despite registration and
        // capability both being correct. See VISION.md's "New dev/
        // admin-only pages default to a Debug Tools SECTION" entry for
        // the full incident. The real, working access point is
        // register_debug_section() below — a dead, always-broken link in
        // the sidebar was worse than no standalone page at all. add_menu()
        // itself is left defined (not deleted) in case a future session
        // gets a real fix for the underlying WordPress issue and wants
        // to re-enable it.
        add_action('wp_ajax_ous_codebase_docs_snippet', [self::class, 'ajax_snippet']);
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
    }

    public static function register_debug_section($tools) {
        $tools['codebase-docs'] = ['label' => 'Codebase Docs', 'render' => [self::class, 'render_section'], 'handle' => null, 'reset' => null];
        return $tools;
    }

    public static function render_section() {
        self::render_content();
    }

    public static function add_menu() {
        // Diagnostic: add_submenu_page() returns a real hook_suffix even
        // when the CURRENT user lacks the registered capability — WP's
        // actual access gate for that case is a separate internal check
        // (current_user_can() re-evaluated when the page is requested),
        // so a successful-looking registration log doesn't rule this out.
        // REMOVED the is_locked() gate around registration itself — this
        // and OUS_ApiDocs were the only two pages in the whole ecosystem
        // that conditionally skipped their own add_submenu_page() call.
        // Every other page (Debug Tools, Job Queue, every peer plugin's
        // admin screens) registers unconditionally; is_locked() exists to
        // gate DESTRUCTIVE seed/reset actions, not a read-only viewer
        // page's mere existence in the menu. Real reported symptom this
        // responds to: this page consistently denied access ("Sorry, you
        // are not allowed to access this page") even on requests where
        // logging proved registration had already succeeded and
        // current_user_can('manage_options') was TRUE — meaning something
        // about the CONDITIONAL registration path itself (not a simple
        // logic bug in is_locked()) was the actual problem. Registering
        // unconditionally, like every other working page, removes that
        // asymmetry entirely rather than chasing it further blind.
        // Un-throttled and includes the exact request URI specifically so
        // the entry from the ACTUAL failing click (GET .../page=ous-
        // codebase-docs) is unambiguous in Console & Logs, not lost among
        // entries from every other admin page load also triggering this
        // same admin_menu callback.
        $hook = add_submenu_page('ous-debug', 'Codebase Docs', 'Codebase Docs', 'manage_options', 'ous-codebase-docs', [self::class, 'render']);
        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('info', 'add_submenu_page() for Codebase Docs returned: ' . ($hook === false ? 'FALSE (registration failed)' : "'$hook'") . ' | current_user_can(manage_options): ' . (current_user_can('manage_options') ? 'TRUE' : 'FALSE'), [
                'hook_suffix' => $hook,
                'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            ], 'Codebase Docs');
        }
    }

    private static function plugins_root() {
        // own-ur-shit/includes/class-codebase-docs.php -> .../plugins
        return realpath(OUS_PATH . '../');
    }

    private static function walkthrough_path() {
        return self::plugins_root() . '/CODEBASE-WALKTHROUGH.md';
    }

    public static function render() {
        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('info', 'OUS_CodebaseDocs::render() was entered — the page callback is actually running.', [], 'Codebase Docs');
        }
        BHY_UI::shell_open('Codebase Docs', 'A guided, sequential tour of this whole ecosystem — generated from CODEBASE-WALKTHROUGH.md, with every referenced file readable live below its mention (always the current code, never a stale pasted-in snippet).');
        self::render_content();
        BHY_UI::shell_close();
    }

    // The actual body content, shared by both the standalone page (render())
    // and the Debug Tools section (render_section()) — factored out so
    // neither wraps the other in a second, nested page shell.
    private static function render_content() {
        echo '<p class="description">Looking for endpoint-by-endpoint API reference instead? <a href="#ous-section-api-docs">Open API Docs</a> (the section further down this page) — generated live from this site\'s own registered REST routes.</p>';

        $path = self::walkthrough_path();
        if (!$path || !file_exists($path)) {
            echo '<div class="notice notice-warning"><p>CODEBASE-WALKTHROUGH.md not found at the plugins root (' . esc_html($path ?: '(path could not be resolved)') . '). Nothing to render.</p></div>';
            return;
        }

        $md = file_get_contents($path);
        if ($md === false) {
            echo '<div class="notice notice-error"><p>Found the file but could not read it (permissions?).</p></div>';
            return;
        }

        echo '<div id="ous-codebase-docs-body">' . self::render_markdown($md) . '</div>';
        self::render_assets();
    }

    /* ---------------- markdown -> HTML, deliberately small ---------------- */

    /**
     * A purpose-built, minimal Markdown renderer — not a general-purpose
     * parser. Handles exactly what CODEBASE-WALKTHROUGH.md actually uses:
     * headers, fenced code blocks, inline code, bold, links, and
     * paragraphs/line breaks. No Composer dependency pulled in for this —
     * the doc's own shape is simple and stable enough that a ~40-line
     * renderer is the right amount of machinery, not an under-build.
     */
    private static function render_markdown($md) {
        $lines = explode("\n", $md);
        $html = '';
        $in_code = false;
        $paragraph = [];

        $flush_paragraph = function () use (&$paragraph, &$html) {
            if (!$paragraph) return;
            $text = self::inline_markdown(implode(' ', $paragraph));
            $html .= '<p>' . $text . '</p>';
            $paragraph = [];
        };

        foreach ($lines as $line) {
            if (preg_match('/^```/', $line)) {
                $flush_paragraph();
                if ($in_code) { $html .= '</pre>'; $in_code = false; }
                else { $html .= '<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px;border-radius:6px;overflow-x:auto;">'; $in_code = true; }
                continue;
            }
            if ($in_code) { $html .= esc_html($line) . "\n"; continue; }

            if (preg_match('/^(#{1,3})\s+(.*)$/', $line, $m)) {
                $flush_paragraph();
                $level = strlen($m[1]) + 2; // # -> h3, ## -> h4, ### -> h5 (stays under the page's own h1/h2 chrome)
                $text = self::inline_markdown($m[2]);
                $anchor = sanitize_title($m[2]);
                $html .= "<h{$level} id=\"" . esc_attr($anchor) . "\" style=\"scroll-margin-top:90px;\">{$text}</h{$level}>";
                continue;
            }

            if (preg_match('/^---+$/', trim($line))) { $flush_paragraph(); $html .= '<hr>'; continue; }
            if (trim($line) === '') { $flush_paragraph(); continue; }
            if (preg_match('/^[-*]\s+(.*)$/', $line, $m)) {
                $flush_paragraph();
                $html .= '<p>&bull; ' . self::inline_markdown($m[1]) . '</p>';
                continue;
            }

            $paragraph[] = trim($line);

            // Any line mentioning a real file inside this ecosystem gets a
            // "view live" affordance right under it — this is the part
            // that actually ties the doc back to the current code instead
            // of just being static prose about it.
            if (preg_match_all('/`?([a-z0-9_-]+(?:\/[a-z0-9_.-]+)+\.(?:php|md))`?/i', $line, $file_matches)) {
                foreach (array_unique($file_matches[1]) as $ref) {
                    if (self::path_is_safe($ref)) {
                        $flush_paragraph();
                        $line_hint = 0;
                        if (preg_match('/line[s]?\s+~?(\d+)/i', $line, $lm)) $line_hint = (int) $lm[1];
                        $html .= self::render_snippet_toggle($ref, $line_hint);
                    }
                }
            }
        }
        $flush_paragraph();
        if ($in_code) $html .= '</pre>';
        return $html;
    }

    private static function inline_markdown($text) {
        $text = esc_html($text);
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $text);
        return $text;
    }

    private static function render_snippet_toggle($ref, $line_hint) {
        $id = 'ous-snip-' . substr(md5($ref . $line_hint), 0, 10);
        $nonce = wp_create_nonce('ous_codebase_docs_snippet');
        $out = '<div class="bhy-card" style="margin:6px 0 12px;padding:8px 12px;">';
        $out .= '<button type="button" class="button button-small ous-view-live-code" '
              . 'data-file="' . esc_attr($ref) . '" data-line="' . (int) $line_hint . '" '
              . 'data-target="' . esc_attr($id) . '" data-nonce="' . esc_attr($nonce) . '">'
              . '&#128269; View live code: <code>' . esc_html($ref) . '</code>' . ($line_hint ? ' (near line ' . (int) $line_hint . ')' : '') . '</button>';
        $out .= '<div id="' . esc_attr($id) . '" style="margin-top:8px;display:none;"></div>';
        $out .= '</div>';
        return $out;
    }

    /* ---------------- live snippet fetch (AJAX) ---------------- */

    private static function path_is_safe($rel) {
        foreach (self::ALLOWED_EXTENSIONS as $ext) {
            if (substr($rel, -strlen($ext) - 1) === '.' . $ext) return true;
        }
        return false;
    }

    public static function ajax_snippet() {
        check_ajax_referer('ous_codebase_docs_snippet', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Not allowed.'], 403);

        $rel = isset($_POST['file']) ? wp_unslash($_POST['file']) : '';
        $line = isset($_POST['line']) ? max(0, (int) $_POST['line']) : 0;

        if (!$rel || !self::path_is_safe($rel)) {
            wp_send_json_error(['message' => 'Invalid file reference.'], 400);
        }

        $root = self::plugins_root();
        $full = realpath($root . '/' . $rel);

        // The realpath()-then-strpos() check is the actual security
        // boundary here: it resolves any ../ traversal FIRST, then
        // confirms the resolved path still lives inside the plugins root
        // — a crafted $rel like "../../wp-config.php" resolves outside
        // that root and gets rejected, regardless of what string it
        // contained before resolution.
        if (!$full || !$root || strpos($full, $root) !== 0 || !is_readable($full)) {
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('warning', 'Codebase Docs snippet request rejected — resolved path outside plugins root or unreadable.', [
                    'requested' => $rel, 'resolved' => $full ?: '(failed to resolve)',
                ], 'OUS_CodebaseDocs');
            }
            wp_send_json_error(['message' => 'File not found or not accessible.'], 404);
        }

        $lines = file($full, FILE_IGNORE_NEW_LINES);
        if ($lines === false) wp_send_json_error(['message' => 'Could not read file.'], 500);

        $total = count($lines);
        if ($line > 0) {
            $start = max(0, $line - 16);
            $end = min($total, $line + 14);
        } else {
            $start = 0;
            $end = min($total, 40);
        }

        $snippet = [];
        for ($i = $start; $i < $end; $i++) {
            $snippet[] = ['n' => $i + 1, 'text' => $lines[$i]];
        }

        wp_send_json_success([
            'file' => $rel,
            'total_lines' => $total,
            'start' => $start + 1,
            'end' => $end,
            'lines' => $snippet,
            'highlight' => $line,
        ]);
    }

    private static function render_assets() {
        $ajax_url = esc_url(admin_url('admin-ajax.php'));
        ?>
        <style>
            .ous-code-snippet{background:#1e1e1e;color:#d4d4d4;padding:10px;border-radius:6px;overflow-x:auto;font-family:Consolas,Monaco,monospace;font-size:12px;line-height:1.6;}
            .ous-code-snippet .ln{color:#6a6a6a;display:inline-block;width:44px;text-align:right;margin-right:10px;user-select:none;}
            .ous-code-snippet .hl{background:rgba(255,214,10,0.18);display:block;}
        </style>
        <script>
        (function () {
            function esc(s){return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
            document.addEventListener('click', function (e) {
                var btn = e.target.closest('.ous-view-live-code');
                if (!btn) return;
                var target = document.getElementById(btn.dataset.target);
                if (!target) return;
                if (target.style.display === 'block') { target.style.display = 'none'; return; }
                target.style.display = 'block';
                target.innerHTML = '<p class="description">Loading live code…</p>';
                var body = new URLSearchParams();
                body.set('action', 'ous_codebase_docs_snippet');
                body.set('nonce', btn.dataset.nonce);
                body.set('file', btn.dataset.file);
                body.set('line', btn.dataset.line || '0');
                fetch('<?php echo $ajax_url; ?>', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString() })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res.success) { target.innerHTML = '<p class="description">Could not load: ' + esc(res.data && res.data.message || 'unknown error') + '</p>'; return; }
                        var d = res.data, html = '<div class="ous-code-snippet">';
                        d.lines.forEach(function (l) {
                            var cls = (d.highlight && l.n === d.highlight) ? ' class="hl"' : '';
                            html += '<span' + cls + '><span class="ln">' + l.n + '</span>' + esc(l.text) + '</span>';
                        });
                        html += '</div><p class="description">Lines ' + d.start + '–' + d.end + ' of ' + d.total_lines + ' in <code>' + esc(d.file) + '</code> — fetched live just now, always the current code on disk.</p>';
                        target.innerHTML = html;
                    })
                    .catch(function () { target.innerHTML = '<p class="description">Request failed.</p>'; });
            });
        })();
        </script>
        <?php
    }
}

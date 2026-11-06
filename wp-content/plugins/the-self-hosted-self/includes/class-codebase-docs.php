<?php
if (!defined('ABSPATH')) exit;

/**
 * Renders CODEBASE-WALKTHROUGH.md (plugins root) as a Debug Tools section,
 * with every referenced file viewable live via AJAX so snippets never go
 * stale. Endpoint reference lives in OUS_ApiDocs, not here.
 */
class OUS_CodebaseDocs {
    // WHY: the snippet endpoint only reads these extensions, and only inside
    // the plugins root.
    const ALLOWED_EXTENSIONS = ['php', 'md'];

    public static function init(): void {
        // add_menu() is deliberately not hooked: WP's page-hook resolution
        // denies the standalone page. The Debug Tools section is the access
        // point; add_menu() stays defined for a future re-enable.
        add_action('wp_ajax_ous_codebase_docs_snippet', [self::class, 'ajax_snippet']);
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
    }

    /**
     * @param array<string, mixed> $tools
     * @return array<string, mixed>
     */
    public static function register_debug_section($tools): array {
        $tools['codebase-docs'] = ['label' => 'Codebase Docs', 'render' => [self::class, 'render_section'], 'handle' => null, 'reset' => null, 'group' => OUS_Debug::GROUP_REFERENCE];
        return $tools;
    }

    public static function render_section(): void {
        self::render_content();
    }

    public static function add_menu(): void {
        // Registers unconditionally and logs the hook suffix for diagnosis.
        $hook = add_submenu_page('ous-debug', 'Codebase Docs', 'Codebase Docs', 'manage_options', 'ous-codebase-docs', [self::class, 'render']);
        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('info', 'add_submenu_page() for Codebase Docs returned: ' . ($hook === false ? 'FALSE (registration failed)' : "'$hook'") . ' | current_user_can(manage_options): ' . (current_user_can('manage_options') ? 'TRUE' : 'FALSE'), [
                'hook_suffix' => $hook,
                'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            ], 'Codebase Docs');
        }
    }

    private static function plugins_root(): string|false {
        return realpath(OUS_PATH . '../');
    }

    private static function walkthrough_path(): string {
        return self::plugins_root() . '/CODEBASE-WALKTHROUGH.md';
    }

    public static function render(): void {
        if (class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('info', 'OUS_CodebaseDocs::render() entered.', [], 'Codebase Docs');
        }
        BHY_UI::shell_open('Codebase Docs', 'A guided, sequential tour of this whole ecosystem — generated from CODEBASE-WALKTHROUGH.md, with every referenced file readable live below its mention (always the current code, never a stale pasted-in snippet).');
        self::render_content();
        BHY_UI::shell_close();
    }

    // Shared by the standalone page and the Debug Tools section.
    private static function render_content(): void {
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
     * Minimal Markdown renderer covering only what the walkthrough uses:
     * headers, fenced code, inline code, bold, links, paragraphs.
     */
    private static function render_markdown(string $md): string {
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
                $level = strlen($m[1]) + 2; // # -> h3, keeping under the page's h1/h2
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

            // A line mentioning an ecosystem file gets a "view live code" toggle.
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

    private static function inline_markdown(string $text): string {
        $text = esc_html($text);
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $text);
        return $text;
    }

    private static function render_snippet_toggle(string $ref, int $line_hint): string {
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

    private static function path_is_safe(string $rel): bool {
        foreach (self::ALLOWED_EXTENSIONS as $ext) {
            if (substr($rel, -strlen($ext) - 1) === '.' . $ext) return true;
        }
        return false;
    }

    public static function ajax_snippet(): void {
        check_ajax_referer('ous_codebase_docs_snippet', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Not allowed.'], 403);

        $rel = isset($_POST['file']) ? wp_unslash($_POST['file']) : '';
        $line = isset($_POST['line']) ? max(0, (int) $_POST['line']) : 0;

        if (!$rel || !self::path_is_safe($rel)) {
            wp_send_json_error(['message' => 'Invalid file reference.'], 400);
        }

        $root = self::plugins_root();
        $full = realpath($root . '/' . $rel);

        // WHY: realpath() resolves ../ first, then the resolved path must still be inside the plugins
        // root; this is the security boundary. The trailing separator stops a sibling like plugins-old/.
        if (!$full || !$root || strpos($full, rtrim($root, '/\\') . DIRECTORY_SEPARATOR) !== 0 || !is_readable($full)) {
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

    private static function render_assets(): void {
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

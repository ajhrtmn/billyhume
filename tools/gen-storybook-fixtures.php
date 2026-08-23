<?php
/**
 * Renders every shared component, in every state, through the SAME
 * BHY_UI:: helpers production uses, and writes the result to
 * .storybook/fixtures.json for Storybook to display.
 *
 * WHY generated rather than hand-written stories: a story containing
 * `<span class="bhy-badge bhy-badge-success">` would be one more copy of
 * markup that already exists elsewhere, free to drift the moment the real
 * renderer changes — the exact failure that produced eight hand-rolled
 * badge shapes and a .bhm-paywall copy that diverged in bh-streaming.
 * Generating from the real render path means the gallery cannot lie.
 *
 * Runs standalone (no WordPress bootstrap) — BHY_UI's component renderers
 * only need esc_html/esc_attr, stubbed below.
 *
 * Usage: php tools/gen-storybook-fixtures.php
 */

if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/../');

if (!function_exists('esc_html')) {
    function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_attr')) {
    function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_url')) {
    function esc_url($u) { return htmlspecialchars((string) $u, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('__'))      { function __($t, $d = null) { return $t; } }
if (!function_exists('esc_html__')) { function esc_html__($t, $d = null) { return esc_html($t); } }

require_once __DIR__ . '/../wp-content/plugins/own-ur-shit/includes/class-ui.php';

/** Long/awkward content, so every component is seen under real stress. */
$LONG  = 'A deliberately long label that should reveal how this component behaves when the text does not fit';
$WORD  = 'Supercalifragilisticexpialidocious';
$EMOJI = 'Ünïcödé 日本語 🎵';

$stories = [];

// ---- Badge: every variant, plus the states that actually break things ----
$badges = [];
foreach (BHY_UI::BADGE_VARIANTS as $v) {
    $badges[] = ['name' => ucfirst($v), 'html' => BHY_UI::badge(ucfirst($v), ['variant' => $v])];
}
$badges[] = ['name' => 'With dot',        'html' => BHY_UI::badge('Live', ['variant' => 'success', 'dot' => true])];
$badges[] = ['name' => 'Long label',      'html' => BHY_UI::badge($LONG, ['variant' => 'neutral'])];
$badges[] = ['name' => 'Long, truncated', 'html' => BHY_UI::badge($LONG, ['variant' => 'neutral', 'truncate' => true])];
$badges[] = ['name' => 'Unbreakable word','html' => BHY_UI::badge($WORD, ['variant' => 'info'])];
$badges[] = ['name' => 'Unicode / emoji', 'html' => BHY_UI::badge($EMOJI, ['variant' => 'info'])];
$badges[] = ['name' => 'Empty label',     'html' => BHY_UI::badge('', ['variant' => 'neutral'])];
$badges[] = ['name' => 'HTML injection',  'html' => BHY_UI::badge('<script>alert(1)</script>', ['variant' => 'danger'])];
$stories['Badge'] = $badges;

// ---- Alert ----
$alerts = [];
foreach (BHY_UI::ALERT_VARIANTS as $v) {
    $alerts[] = ['name' => ucfirst($v), 'html' => BHY_UI::alert(ucfirst($v) . ' — something worth telling the reader about.', ['variant' => $v])];
}
$alerts[] = ['name' => 'With title',  'html' => BHY_UI::alert('The body of the notice sits after its title.', ['variant' => 'warning', 'title' => 'Heads up'])];
$alerts[] = ['name' => 'Long body',   'html' => BHY_UI::alert(str_repeat($LONG . ' ', 4), ['variant' => 'info'])];
$alerts[] = ['name' => 'Empty body',  'html' => BHY_UI::alert('', ['variant' => 'info', 'title' => 'Title only'])];
$stories['Alert'] = $alerts;

// ---- Card ----
$cards = [];
$cards[] = ['name' => 'Plain',        'html' => BHY_UI::card('<p>Ordinary card body.</p>')];
$cards[] = ['name' => 'With title',   'html' => BHY_UI::card('<p>Body sits under the title.</p>', ['title' => 'Card title'])];
$cards[] = ['name' => 'With footer',  'html' => BHY_UI::card('<p>Body.</p>', ['title' => 'With a footer', 'footer' => '<a href="#">A footer action</a>'])];
$cards[] = ['name' => 'Long title',   'html' => BHY_UI::card('<p>Body.</p>', ['title' => $LONG])];
$cards[] = ['name' => 'Empty body',   'html' => BHY_UI::card('', ['title' => 'Nothing in here yet'])];
$cards[] = ['name' => 'Badges inside','html' => BHY_UI::card(
    '<p>Components compose: ' . BHY_UI::badge('Active', ['variant' => 'success'])
    . ' ' . BHY_UI::badge('Beta', ['variant' => 'warning']) . '</p>', ['title' => 'Composition'])];
$stories['Card'] = $cards;

// ---- Empty state (the pre-existing helper) ----
$empties = [];
$empties[] = ['name' => 'Zero data', 'html' => BHY_UI::empty_state_html(['reason' => 'zero'])];
$empties[] = ['name' => 'Filtered',  'html' => BHY_UI::empty_state_html(['reason' => 'filtered'])];
$empties[] = ['name' => 'Custom copy', 'html' => BHY_UI::empty_state_html([
    'title' => 'No supporters yet', 'description' => 'When someone subscribes they will show up here.'])];
$stories['Empty state'] = $empties;

// ---- Wide table ----
$rows = '';
for ($i = 1; $i <= 8; $i++) {
    $rows .= '<tr><td>Row ' . $i . '</td><td>' . BHY_UI::badge($i % 2 ? 'Active' : 'Paused', ['variant' => $i % 2 ? 'success' : 'warning']) . '</td>';
    for ($c = 1; $c <= 8; $c++) $rows .= '<td>Column ' . $c . ' value</td>';
    $rows .= '</tr>';
}
$head = '<tr><th>Name</th><th>Status</th>';
for ($c = 1; $c <= 8; $c++) $head .= '<th>Column ' . $c . '</th>';
$head .= '</tr>';
$table = '<table class="widefat"><thead>' . $head . '</thead><tbody>' . $rows . '</tbody></table>';
$stories['Wide table'] = [
    ['name' => 'Scrollable',  'html' => BHY_UI::table_wrap($table)],
    ['name' => 'Tall variant','html' => BHY_UI::table_wrap($table, ['tall' => true])],
];

// ---- the design-system CSS itself ----
// BHY_UI::design_system_css() is PHP-GENERATED and printed inline on
// admin_head — it is not a file, so no <link> can reach it. .bhy-alert in
// particular exists ONLY there (zero occurrences in admin-skin.css), which
// is why alerts rendered unstyled before this was emitted. Generating it
// from the same source keeps Storybook honest instead of hand-copying rules.
// The --bhy-* -> --shsas-* token bridge is ALSO printed inline (by the admin
// skin, on admin_head), so Storybook was falling back to class-ui.php's own
// light :root defaults and rendering alerts light-on-dark. Extracting the
// real bridge from its source function keeps the two in step; hand-copying
// the mapping is exactly how the ecosystem grew two definitions of one
// colour before.
$skin = file_get_contents(__DIR__ . '/../wp-content/plugins/self-hosted-self-admin-skin/self-hosted-self-admin-skin.php');
$bridge = '';
if (preg_match('/function shsas_bridge_bhy_tokens\(\): void \{(.*?)\n\}/s', $skin, $m)) {
    ob_start();
    eval($m[1]);
    $bridge = ob_get_clean();
    // The function echoes a full <style> element; Storybook wants bare CSS.
    $bridge = preg_replace('#</?style[^>]*>#', '', $bridge);
}
if ($bridge === '') {
    fwrite(STDERR, "WARNING: could not extract the --bhy-* token bridge; components will render with fallback colours.\n");
}

$css_path = __DIR__ . '/../.storybook/design-system.css';
$css = "/* GENERATED by tools/gen-storybook-fixtures.php from BHY_UI::design_system_css().\n"
     . "   Do not edit — regenerate with `npm run storybook:fixtures`. */\n"
     . BHY_UI::design_system_css()
     . "\n\n/* --bhy-* -> --shsas-* bridge, extracted from the admin skin's own\n"
     . "   shsas_bridge_bhy_tokens(). Must come AFTER design_system_css(), whose\n"
     . "   :root block defines the light fallbacks this overrides. */\n"
     . $bridge;
@mkdir(dirname($css_path), 0777, true);
file_put_contents($css_path, $css);
echo "wrote " . strlen($css) . " bytes of design-system CSS -> .storybook/design-system.css\n";

$out = ['generatedAt' => gmdate('c'), 'stories' => $stories];
$path = __DIR__ . '/../.storybook/fixtures.json';
@mkdir(dirname($path), 0777, true);
file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$count = 0;
foreach ($stories as $s) $count += count($s);
echo "wrote " . count($stories) . " component groups, {$count} states -> .storybook/fixtures.json\n";

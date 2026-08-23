<?php
/**
 * Renders every shared component, in every state, twice — once through Twig
 * and once through the PHP fallback — and fails if they disagree.
 *
 * WHY this exists: BHY_UI's component renderers keep a PHP fallback for hosts
 * where vendor/ was never built. A fallback that quietly drifts from the real
 * path is worse than no fallback, because it only runs where nobody is
 * looking. This pins them together.
 *
 * Usage: php tools/check-render-parity.php   (exit 1 on any mismatch)
 */
// Renders every component/state twice — once through Twig, once through the
// PHP fallback — and diffs. If these ever disagree, the fallback is a lie.
if (!defined('ABSPATH')) define('ABSPATH', dirname(__DIR__) . '/');
foreach ([['esc_html', ENT_QUOTES], ['esc_attr', ENT_QUOTES]] as $fn) {
    if (!function_exists($fn[0])) eval("function {$fn[0]}(\$t){ return htmlspecialchars((string)\$t, ENT_QUOTES, 'UTF-8'); }");
}
if (!function_exists('untrailingslashit')) { function untrailingslashit($s){ return rtrim($s,'/\\'); } }
if (!function_exists('apply_filters'))     { function apply_filters($t,$v){ return $v; } }
if (!function_exists('add_filter'))        { function add_filter(...$a){ return true; } }

define("OUS_PATH", dirname(__DIR__) . "/wp-content/plugins/own-ur-shit/");
require OUS_PATH . 'vendor/autoload.php';
// Deliberately NOT requiring class-view.php: with BHY_View absent,
// BHY_UI::view_engine_ready() is false and the renderers take their PHP
// fallback — which is the path under test. Twig is exercised directly below.
require OUS_PATH . 'includes/class-ui.php';

// Stand up Twig directly against the same views dir (no WordPress needed).
$twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader(OUS_PATH . 'views'), ['autoescape' => 'html']);
$render = function (string $tpl, array $ctx) use ($twig) { return trim($twig->render($tpl, $ctx)); };


/**
 * Renders the same component through Twig directly, mirroring the context
 * BHY_UI builds. Kept beside the fallback deliberately: if the two ever drift,
 * this is where it shows up.
 *
 * @param callable            $render
 * @param string              $method
 * @param array<int,mixed>    $args
 */
function render_via_twig(callable $render, string $method, array $args): string {
    [$first, $opts] = [$args[0], $args[1] ?? []];
    switch ($method) {
        case 'badge':
            $variant = $opts['variant'] ?? 'neutral';
            if (!in_array($variant, BHY_UI::BADGE_VARIANTS, true)) $variant = 'neutral';
            return $render('badge.twig', [
                'label' => $first, 'variant' => $variant,
                'dot' => !empty($opts['dot']), 'truncate' => !empty($opts['truncate']),
                'title' => $opts['title'] ?? null,
            ]);
        case 'alert':
            $variant = $opts['variant'] ?? 'info';
            if (!in_array($variant, BHY_UI::ALERT_VARIANTS, true)) $variant = 'info';
            return $render('alert.twig', [
                'variant' => $variant, 'title' => $opts['title'] ?? null,
                'message' => $first, 'body_html' => $opts['html'] ?? null,
            ]);
        case 'card':
            return $render('card.twig', [
                'body_html' => $first, 'title' => $opts['title'] ?? null,
                'footer_html' => $opts['footer'] ?? null, 'extra_class' => $opts['class'] ?? null,
            ]);
        case 'table_wrap':
            return $render('table-wrap.twig', ['table_html' => $first, 'tall' => !empty($opts['tall'])]);
    }
    throw new RuntimeException("unmapped component: {$method}");
}

$cases = [];
foreach (BHY_UI::BADGE_VARIANTS as $v) $cases[] = ['badge', ['Label ' . $v, ['variant' => $v]]];
$cases[] = ['badge', ['<script>alert(1)</script>', ['variant' => 'danger']]];
$cases[] = ['badge', ['Live', ['variant' => 'success', 'dot' => true]]];
$cases[] = ['badge', ['Long', ['variant' => 'neutral', 'truncate' => true]]];
$cases[] = ['badge', ['Quoted "x" & <y>', ['variant' => 'info', 'title' => 'a "title" & more']]];
$cases[] = ['badge', ['', ['variant' => 'neutral']]];
foreach (BHY_UI::ALERT_VARIANTS as $v) $cases[] = ['alert', ['Body ' . $v, ['variant' => $v]]];
$cases[] = ['alert', ['Body & <b>', ['variant' => 'warning', 'title' => 'Title & <i>']]];
$cases[] = ['alert', ['', ['variant' => 'info', 'title' => 'Title only']]];
$cases[] = ['alert', ['Body', ['variant' => 'info', 'html' => '<em>raw</em>']]];
$cases[] = ['card',  ['<p>Body</p>', []]];
$cases[] = ['card',  ['<p>Body</p>', ['title' => 'T & <x>', 'footer' => '<a href="#">f</a>']]];
$cases[] = ['card',  ['<p>B</p>', ['class' => 'extra-class']]];
$cases[] = ['table_wrap', ['<table></table>', []]];
$cases[] = ['table_wrap', ['<table></table>', ['tall' => true]]];

$fail = 0; $n = 0;
foreach ($cases as [$method, $args]) {
    $n++;
    // BHY_UI takes its PHP fallback here: Timber cannot boot outside WordPress
    // (Timber::compile() calls get_template_directory()), which is exactly why
    // the fallback has to exist and be correct.
    $viaPhp = BHY_UI::$method(...$args);
    $viaTwig = render_via_twig($render, $method, $args);

    if ($viaTwig !== $viaPhp) {
        $fail++;
        echo "  MISMATCH  {$method}(" . json_encode($args[0]) . ")\n";
        echo "    twig: {$viaTwig}\n    php : {$viaPhp}\n";
    }
}
echo $fail === 0
    ? "  PARITY OK — {$n} cases, Twig and PHP fallback byte-identical\n"
    : "  {$fail} of {$n} cases DIFFER\n";
exit($fail === 0 ? 0 : 1);

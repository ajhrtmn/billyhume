<?php
/**
 * PHPStan-only bootstrap: dummy values for the plugin-defined _URL/_PATH/
 * _VER constants (own-ur-shit.php etc. define these via plugin_dir_url()/
 * plugin_dir_path(), which aren't real functions under static analysis —
 * stub packages only provide type info, not runtime implementations).
 * Never loaded by WordPress; referenced only via phpstan.neon's
 * bootstrapFiles. Keep this list in sync with the `define('X_URL', ...)` /
 * `define('X_PATH', ...)` / `define('X_VER', ...)` calls in each analysed
 * plugin's main file — a new plugin added to phpstan.neon's `paths` needs
 * its own three constants added here too, or its includes/*.php files
 * will flood the report with false-positive "Constant not found" noise.
 */

// own-ur-shit
define('OUS_VER', '0.0.0');
define('OUS_PATH', __DIR__ . '/wp-content/plugins/own-ur-shit/');
define('OUS_URL', 'http://example.test/wp-content/plugins/own-ur-shit/');

// bh-contest
define('BH_VER', '0.0.0');
define('BH_PATH', __DIR__ . '/wp-content/plugins/bh-contest/');
define('BH_URL', 'http://example.test/wp-content/plugins/bh-contest/');

// bh-courses
define('BHC_VER', '0.0.0');
define('BHC_PATH', __DIR__ . '/wp-content/plugins/bh-courses/');
define('BHC_URL', 'http://example.test/wp-content/plugins/bh-courses/');

// bh-crm
define('BHCRM_VER', '0.0.0');
define('BHCRM_PATH', __DIR__ . '/wp-content/plugins/bh-crm/');
define('BHCRM_URL', 'http://example.test/wp-content/plugins/bh-crm/');

// bh-monetization-woo
define('BHM_VER', '0.0.0');
define('BHM_PATH', __DIR__ . '/wp-content/plugins/bh-monetization-woo/');
define('BHM_URL', 'http://example.test/wp-content/plugins/bh-monetization-woo/');

// bh-registry
define('BHR_VER', '0.0.0');
define('BHR_PATH', __DIR__ . '/wp-content/plugins/bh-registry/');
define('BHR_URL', 'http://example.test/wp-content/plugins/bh-registry/');

// bh-streaming
define('BHS_VER', '0.0.0');
define('BHS_PATH', __DIR__ . '/wp-content/plugins/bh-streaming/');
define('BHS_URL', 'http://example.test/wp-content/plugins/bh-streaming/');

// bh-mailpoet
define('BHMP_VER', '0.0.0');
define('BHMP_PATH', __DIR__ . '/wp-content/plugins/bh-mailpoet/');
define('BHMP_URL', 'http://example.test/wp-content/plugins/bh-mailpoet/');
define('BHMP_DEFAULT_LIST_NAME', 'test-list');

// bh-tickets
define('BHT_VER', '0.0.0');
define('BHT_PATH', __DIR__ . '/wp-content/plugins/bh-tickets/');
define('BHT_URL', 'http://example.test/wp-content/plugins/bh-tickets/');

// bh-video
define('BHV_PATH', __DIR__ . '/wp-content/plugins/bh-video/');
define('BHV_URL', 'http://example.test/wp-content/plugins/bh-video/');
define('BHV_VER', '0.0.0');

// bh-live
define('BHL_PATH', __DIR__ . '/wp-content/plugins/bh-live/');
define('BHL_URL', 'http://example.test/wp-content/plugins/bh-live/');
define('BHL_VER', '0.0.0');

// bh-feedback
define('BHF_PATH', __DIR__ . '/wp-content/plugins/bh-feedback/');
define('BHF_URL', 'http://example.test/wp-content/plugins/bh-feedback/');
define('BHF_VER', '0.0.0');

// bh-social
define('BHSO_PATH', __DIR__ . '/wp-content/plugins/bh-social/');
define('BHSO_URL', 'http://example.test/wp-content/plugins/bh-social/');
define('BHSO_VER', '0.0.0');

// WordPress core constants a handful of includes/*.php files reference
// directly that the WordPress stub package doesn't define values for.
// Deliberately NOT defining COOKIEPATH/COOKIE_DOMAIN here even though a
// few files reference them too: several call sites do `COOKIEPATH ?: '/'`
// specifically because COOKIEPATH can legitimately be empty at runtime —
// giving them ANY literal stub value here makes PHPStan wrongly treat
// that fallback as dead code ("ternary condition is always true"), a
// worse false positive than the "Constant not found" noise this leaves
// in its place.
define('WPINC', 'wp-includes');
define('DB_NAME', 'wordpress');

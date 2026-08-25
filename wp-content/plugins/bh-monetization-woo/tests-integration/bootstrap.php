<?php
/**
 * Real WordPress + real MySQL integration bootstrap — deliberately
 * separate from this plugin's tests/ directory (root composer.json's
 * "test" script), which is pure-logic PHPUnit against a hand-stubbed
 * $wpdb. That tier can't verify BHM_Wallet::debit()'s actual claim: that
 * the balance check and the balance write are one atomic UPDATE, safe
 * against a real concurrent-write race. A stub can only assert "the SQL
 * string looks right"; only a real database can prove the WHERE clause
 * actually prevents a negative balance. This is the first suite in this
 * ecosystem that can make that claim for real — see TOOLING-EVALUATION.md
 * and OPEN.md, both of which named this exact gap.
 *
 * Runs against @wordpress/env's own tests-cli container, which already
 * provides /wordpress-phpunit (WP core's real test framework) fully
 * configured against its own MySQL instance — nothing here reinvents
 * that, it only loads this ONE plugin into it before WP itself boots.
 */

require_once __DIR__ . '/vendor/autoload.php';

$_tests_dir = getenv('WP_TESTS_DIR');
if (!$_tests_dir) {
    $_tests_dir = '/wordpress-phpunit';
}

require_once $_tests_dir . '/includes/functions.php';

function _bhm_manually_load_plugin() {
    require dirname(__DIR__) . '/bh-monetization-woo.php';
}
tests_add_filter('muplugins_loaded', '_bhm_manually_load_plugin');

require $_tests_dir . '/includes/bootstrap.php';

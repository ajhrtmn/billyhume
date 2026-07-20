#!/usr/bin/env bash
# Runs every test this site has, in one pass:
#   1. Pure-logic PHPUnit suites (wp-content/plugins/*/tests/, no WP/DB needed)
#   2. The live OUS_TestRunner suites (wp-content/plugins/*/includes/class-*test-suite*.php),
#      registered via the 'bhcore_test_suites' filter and normally run from
#      Debug Tools in wp-admin — this just runs the same callbacks over WP-CLI-less
#      PHP against the real dev database, for a CLI/CI-friendly combined report.
#
# Requires: `composer install` already run once (installs PHPUnit into ./vendor).
# Local by Flywheel's MySQL socket differs from PHP CLI's default — set
# LOCAL_MYSQL_SOCKET if the DB-backed suites need to connect (see below).

set -uo pipefail
cd "$(dirname "$0")"

FAIL=0

echo "=================================================="
echo " Pure-logic PHPUnit suites"
echo "=================================================="
for dir in wp-content/plugins/*/tests; do
    [ -f "$dir/phpunit.xml" ] || continue
    echo "--- $dir ---"
    (cd "$dir" && "$OLDPWD/vendor/bin/phpunit") || FAIL=1
    echo
done

echo "=================================================="
echo " Live OUS_TestRunner suites (bhcore_test_suites)"
echo "=================================================="
SOCK="${LOCAL_MYSQL_SOCKET:-}"
PHP_FLAGS=()
if [ -n "$SOCK" ]; then
    PHP_FLAGS=(-d "mysqli.default_socket=$SOCK" -d "pdo_mysql.default_socket=$SOCK")
fi

php "${PHP_FLAGS[@]}" -r '
define("WP_USE_THEMES", false);
require "wp-load.php";
$suites = apply_filters("bhcore_test_suites", []);
if (!$suites) { echo "No suites registered.\n"; exit(1); }
$total = 0; $fail = 0;
foreach ($suites as $slug => $s) {
    $rows = [];
    try {
        $rows = call_user_func($s["callback"]);
    } catch (\Throwable $e) {
        echo "SUITE THREW [$slug]: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
        $fail++;
        continue;
    }
    $suite_fail = 0;
    foreach ($rows as $r) {
        $total++;
        if (empty($r["pass"])) { $fail++; $suite_fail++; echo "FAIL [$slug]: " . $r["name"] . " -- " . ($r["message"] ?? "") . "\n"; }
    }
    echo "$slug: " . count($rows) . " assertions, $suite_fail failed\n";
}
echo "\nTOTAL: $total assertions, $fail failed\n";
exit($fail > 0 ? 1 : 0);
' || FAIL=1

echo "=================================================="
if [ "$FAIL" -ne 0 ]; then
    echo "RESULT: one or more suites failed."
    exit 1
fi
echo "RESULT: all suites passed."

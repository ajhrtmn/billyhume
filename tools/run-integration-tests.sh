#!/usr/bin/env bash
# Runs bh-monetization-woo's real-database integration suite (real
# WordPress + real MySQL, via @wordpress/env's tests-cli container) --
# the suite that finally verifies BHM_Wallet::debit()'s own atomicity
# claim, which the pure-logic tests/ tier (a hand-stubbed $wpdb) never
# could. See wp-content/plugins/bh-monetization-woo/tests-integration/
# bootstrap.php for the full reasoning.
#
# Local use: this leaves wp-env running afterward (faster to iterate --
# `npx wp-env destroy` when you're done with it). CI always tears it
# down via the `stop` step in the workflow, not this script.
set -euo pipefail
cd "$(dirname "$0")/.."

echo "Starting @wordpress/env (first run downloads WordPress + MySQL images -- can take a few minutes)..."
npx @wordpress/env start

echo "Installing tests-integration's own composer dependencies (phpunit-polyfills)..."
(cd wp-content/plugins/bh-monetization-woo/tests-integration && composer install --no-interaction --quiet)

echo "Running the real-database suite..."
npx @wordpress/env run tests-cli bash -c \
  "cd /var/www/html/wp-content/plugins/bh-monetization-woo/tests-integration && WP_TESTS_DIR=/wordpress-phpunit vendor/bin/phpunit -c phpunit.xml"

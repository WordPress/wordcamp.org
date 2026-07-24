#!/bin/bash
#
# Entrypoint for the PHPUnit container. Waits for the database, installs the WordPress
# test suite on first run, then hands off to the container command (php-fpm). This
# removes the previously-manual `install-wp-tests.sh` step from the test workflow.

set -uo pipefail

DB_HOST="${WP_TESTS_DB_HOST:-phpunit_db}"
DB_NAME="${WP_TESTS_DB_NAME:-wordpress_test}"
DB_USER="${WP_TESTS_DB_USER:-root}"
DB_PASS="${WP_TESTS_DB_PASS:-}"
WP_VERSION="${WP_TESTS_WP_VERSION:-latest}"
TESTS_CONFIG="/tmp/wp/wordpress-tests-lib/wp-tests-config.php"

# Same pinned release used by local dev (see the groups-gatherpress-compat-test
# skill and PR #1793's test plan). GatherPress is gitignored/third-party, so the
# `wporg-groups-frontend`/`groups` PHPUnit suites need it installed before they run.
GATHERPRESS_VERSION="0.34.0-alpha.2"
GATHERPRESS_DIR="/app/public_html/wp-content/plugins/gatherpress"

db_ready() {
	if command -v mariadb-admin >/dev/null 2>&1; then
		mariadb-admin ping -h "$DB_HOST" --silent >/dev/null 2>&1
	else
		mysqladmin ping -h "$DB_HOST" --silent >/dev/null 2>&1
	fi
}

echo "Waiting for database at ${DB_HOST}..."
tries=0
until db_ready; do
	tries=$(( tries + 1 ))
	if [ "$tries" -ge 30 ]; then
		echo "WARNING: database did not become ready after 60s; starting anyway."
		break
	fi
	sleep 2
done

if [ -f "$TESTS_CONFIG" ]; then
	echo "WordPress test suite already installed."
else
	echo "Installing the WordPress test suite..."
	if /var/scripts/install-wp-tests.sh "$DB_NAME" "$DB_USER" "$DB_PASS" "$DB_HOST" "$WP_VERSION" true; then
		echo "WordPress test suite installed."
	else
		echo "WARNING: test-suite install failed (e.g. a download timeout). Re-run 'docker compose -f docker-compose.phpunit.yml up', or run /var/scripts/install-wp-tests.sh manually."
	fi
fi

if [ -f "$GATHERPRESS_DIR/gatherpress.php" ]; then
	echo "GatherPress already installed."
else
	echo "Installing GatherPress ${GATHERPRESS_VERSION}..."
	if curl -sL "https://github.com/GatherPress/gatherpress/releases/download/${GATHERPRESS_VERSION}/gatherpress.${GATHERPRESS_VERSION}.zip" -o /tmp/gatherpress.zip \
		&& unzip -q -o /tmp/gatherpress.zip -d /app/public_html/wp-content/plugins/ \
		&& rm -f /tmp/gatherpress.zip; then
		echo "GatherPress installed."
	else
		echo "WARNING: GatherPress install failed (e.g. a download timeout). The WordCamp Groups PHPUnit suite will not run correctly without it."
	fi
fi

exec "$@"

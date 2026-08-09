#!/usr/bin/env bash
#
# Run the WordPress Plugin Check (https://wordpress.org/plugins/plugin-check/)
# against the plugin as it ships.
#
# The check runs against a build of the distributed plugin rather than the
# working tree, so tests, tooling and dev dependencies are not reported. The
# file list comes from .distignore, the same list `composer zip` ships from.
#
# Plugin Check is itself a WordPress plugin, so it needs a WordPress install to
# run in. This script keeps a throwaway one in Docker volumes: the first run
# downloads WordPress and Plugin Check, later runs reuse them. Nothing is
# installed on the host, and the repository is never modified.
#
# Usage:
#   bash scripts/plugin-check.sh [wp plugin check options...]
#   bash scripts/plugin-check.sh --clean   # discard the cached environment
#
# Examples:
#   bash scripts/plugin-check.sh
#   bash scripts/plugin-check.sh --format=csv
#   bash scripts/plugin-check.sh --categories=security,plugin_repo
#
# Only static checks run here — that is the WP-CLI default. The runtime checks
# additionally need the plugin activated, which means a working GiveWP install.

set -euo pipefail

SLUG="wayforpay-givewp"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="$ROOT/build"
DIST_DIR="$BUILD_DIR/$SLUG"

PREFIX="$SLUG-pcp"
NETWORK="$PREFIX-net"
DB_CONTAINER="$PREFIX-db"
DB_VOLUME="$PREFIX-db-data"
WP_VOLUME="$PREFIX-wp"

DB_IMAGE="mysql:8.0"
WP_IMAGE="wordpress:cli"

# `Stable tag: trunk` is the placeholder this repository commits; the release
# tooling stamps the real version into readme.txt (scripts/changelog-to-readme.php),
# so the code only ever fires here. A genuine header/readme disagreement is
# reported as stable_tag_mismatch, which is deliberately not ignored.
IGNORED_CODES="trunk_stable_tag"

if ! command -v docker >/dev/null 2>&1; then
    echo "Error: Docker is required to run Plugin Check." >&2
    exit 1
fi

if [ "${1:-}" = "--clean" ]; then
    docker rm -f "$DB_CONTAINER" >/dev/null 2>&1 || true
    docker volume rm "$DB_VOLUME" "$WP_VOLUME" >/dev/null 2>&1 || true
    docker network rm "$NETWORK" >/dev/null 2>&1 || true
    echo "Removed the Plugin Check environment. The next run will set it up again."
    exit 0
fi

for tool in zip unzip composer; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        echo "Error: '$tool' is required to build the plugin." >&2
        exit 1
    fi
done

# Build the distributed plugin tree in build/wayforpay-givewp/.
cd "$ROOT"
rm -rf "$DIST_DIR"
mkdir -p "$DIST_DIR"

# Copying the files through zip applies the exclusions with the same tool, and
# the same pattern syntax, as `composer zip` — there is no second list to keep
# in sync. vendor/ is excluded here and rebuilt below, because the copy in the
# repository carries dev dependencies that are never shipped.
EXCLUDES="$BUILD_DIR/.distignore.build"
STAGE_ZIP="$BUILD_DIR/.stage.zip"
cp .distignore "$EXCLUDES"
echo 'vendor/*' >> "$EXCLUDES"
rm -f "$STAGE_ZIP"
zip -q -r "$STAGE_ZIP" . -x@"$EXCLUDES"
unzip -q "$STAGE_ZIP" -d "$DIST_DIR"
rm -f "$STAGE_ZIP" "$EXCLUDES"

# Install production-only dependencies out of tree, then move vendor/ into the
# build, so the repository's own vendor/ (with dev dependencies) is untouched.
DEPS_DIR="$BUILD_DIR/.deps"
rm -rf "$DEPS_DIR"
mkdir -p "$DEPS_DIR"
cp composer.json composer.lock "$DEPS_DIR/"
composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --quiet --working-dir="$DEPS_DIR"
mv "$DEPS_DIR/vendor" "$DIST_DIR/vendor"
rm -rf "$DEPS_DIR"

docker network inspect "$NETWORK" >/dev/null 2>&1 || docker network create "$NETWORK" >/dev/null
docker volume inspect "$DB_VOLUME" >/dev/null 2>&1 || docker volume create "$DB_VOLUME" >/dev/null
docker volume inspect "$WP_VOLUME" >/dev/null 2>&1 || docker volume create "$WP_VOLUME" >/dev/null

# The database is only needed for the length of the run; its data volume and the
# WordPress volume persist, so repeat runs stay fast.
trap 'docker rm -f "$DB_CONTAINER" >/dev/null 2>&1 || true' EXIT
docker rm -f "$DB_CONTAINER" >/dev/null 2>&1 || true
docker run -d --name "$DB_CONTAINER" --network "$NETWORK" \
    -e MYSQL_ROOT_PASSWORD=root \
    -e MYSQL_DATABASE=wordpress \
    -v "$DB_VOLUME:/var/lib/mysql" \
    "$DB_IMAGE" >/dev/null

printf 'Waiting for the database'
DB_READY=""
for _ in $(seq 1 60); do
    if docker exec "$DB_CONTAINER" mysqladmin ping -uroot -proot --silent >/dev/null 2>&1; then
        DB_READY=1
        break
    fi
    printf '.'
    sleep 2
done
echo
if [ -z "$DB_READY" ]; then
    echo "Error: the database did not become ready in time." >&2
    exit 1
fi

docker run --rm --network "$NETWORK" --user root \
    -v "$WP_VOLUME:/var/www/html" \
    -v "$DIST_DIR:/var/www/html/wp-content/plugins/$SLUG:ro" \
    -e WP_CLI_ALLOW_ROOT=1 \
    -e PLUGIN_SLUG="$SLUG" \
    -e IGNORED_CODES="$IGNORED_CODES" \
    -e DB_HOST="$DB_CONTAINER" \
    --entrypoint sh \
    "$WP_IMAGE" -c '
        set -e
        # Unzipping WordPress core exceeds the image default.
        WP="php -d memory_limit=1G /usr/local/bin/wp"

        if ! $WP core is-installed >/dev/null 2>&1; then
            echo "Setting up a throwaway WordPress install (first run only)..."
            $WP core download --force --quiet
            $WP config create --dbhost="$DB_HOST" --dbname=wordpress --dbuser=root --dbpass=root --force --quiet
            $WP core install --url=http://localhost --title="Plugin Check" \
                --admin_user=admin --admin_password=admin --admin_email=admin@example.com \
                --skip-email --quiet
        fi

        if ! $WP plugin is-installed plugin-check >/dev/null 2>&1; then
            echo "Installing Plugin Check..."
            $WP plugin install plugin-check --quiet
        fi
        # CI always runs the latest Plugin Check; keep the local copy in step.
        $WP plugin update plugin-check --quiet >/dev/null 2>&1 || true
        $WP plugin activate plugin-check --quiet >/dev/null 2>&1 || true

        echo "WordPress $($WP core version), Plugin Check $($WP plugin get plugin-check --field=version)"
        echo
        exec $WP plugin check "$PLUGIN_SLUG" --ignore-codes="$IGNORED_CODES" "$@"
    ' sh "$@"

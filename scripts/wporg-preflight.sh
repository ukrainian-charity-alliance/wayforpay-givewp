#!/usr/bin/env bash
#
# Check a release build before it is deployed to WordPress.org SVN.
#
# Usage: bash scripts/wporg-preflight.sh <tag> <build-dir>
#
# <build-dir> is the unpacked GitHub Release zip. Fails unless the build is
# stamped with the tag's version, the tag is on origin/main, and the version is
# new and newest in SVN. In GitHub Actions, writes version=X.Y.Z to
# $GITHUB_OUTPUT for the deploy step.

set -euo pipefail

SLUG="uca-payment-gateway-with-wayforpay-for-givewp"
SVN_URL="https://plugins.svn.wordpress.org/$SLUG"

die() {
    echo "Error: $*" >&2
    exit 1
}

[ $# -eq 2 ] || die "usage: bash scripts/wporg-preflight.sh <tag> <build-dir>"

TAG=$1
BUILD_DIR=${2%/}
VERSION=${TAG#v}

[[ $VERSION =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "tag '$TAG' is not X.Y.Z."

PLUGIN_FILE="$BUILD_DIR/$SLUG.php"
README="$BUILD_DIR/readme.txt"
[ -f "$PLUGIN_FILE" ] || die "$PLUGIN_FILE not found."
[ -f "$README" ] || die "$README not found."

# First value matching a sed expression, without CRs or surrounding blanks.
first() {
    sed -n "$1" "$2" | head -n 1 | tr -d '\r' | sed 's/^[[:space:]]*//; s/[[:space:]]*$//'
}

expect_version() {
    [ "$2" = "$VERSION" ] || die "$1 is '${2:-<missing>}', expected '$VERSION'."
}

expect_version "Version: header" "$(first 's/^ \* Version://p' "$PLUGIN_FILE")"
expect_version "WAYFORPAY_GIVEWP_VERSION" "$(first "s/.*define( 'WAYFORPAY_GIVEWP_VERSION', '\([^']*\)' );.*/\1/p" "$PLUGIN_FILE")"
expect_version "readme.txt Stable tag" "$(first 's/^Stable tag://p' "$README")"

tr -d '\r' < "$README" | grep -qxF "= $VERSION =" ||
    die "readme.txt has no '= $VERSION =' changelog entry."

# A tag on an unmerged commit must not ship.
git rev-parse -q --verify "refs/tags/$TAG^{commit}" >/dev/null ||
    die "tag '$TAG' not found locally. Fetch tags first."
git merge-base --is-ancestor "refs/tags/$TAG^{commit}" origin/main ||
    die "tag '$TAG' is not on origin/main. Fetch origin, or check the tag."

# The deploy action exits 0 without deploying when the SVN tag already exists.
status=$(curl -s -o /dev/null -w '%{http_code}' "$SVN_URL/tags/$VERSION/" || true)
case $status in
    404) ;;
    200) die "tags/$VERSION already exists in SVN, so $VERSION has already been deployed." ;;
    *) die "could not check $SVN_URL/tags/$VERSION/ (HTTP $status)." ;;
esac

# Deploying an older version would move trunk and Stable tag backwards.
listing=$(curl -sf "$SVN_URL/tags/") || die "could not list $SVN_URL/tags/."
newest=$(
    {
        grep -oE 'href="[0-9]+\.[0-9]+\.[0-9]+/"' <<< "$listing" | sed -E 's/^href="(.*)\/"$/\1/' || true
        echo "$VERSION"
    } | sort -V | tail -n 1
)
[ "$newest" = "$VERSION" ] || die "$VERSION is older than tags/$newest in SVN."

echo "Preflight passed for $VERSION."
if [ -n "${GITHUB_OUTPUT:-}" ]; then
    echo "version=$VERSION" >> "$GITHUB_OUTPUT"
fi

#!/usr/bin/env bash

set -e

# Usage: ./scripts/release.sh [major|minor|patch]

if [ -z "$1" ]; then
    echo "Usage: ./scripts/release.sh [major|minor|patch]"
    echo "Example: ./scripts/release.sh minor"
    exit 1
fi

BUMP_TYPE=$1

# Get latest tag. Suppress errors if no tags exist.
LATEST_TAG=$(git describe --tags --abbrev=0 2>/dev/null || echo "0.0.0")

# Strip 'v' if present for calculation (although we use standard format now)
VERSION=${LATEST_TAG#v}

# Parse version parts
IFS='.' read -r -a VERSION_PARTS <<< "$VERSION"

MAJOR=${VERSION_PARTS[0]:-0}
MINOR=${VERSION_PARTS[1]:-0}
PATCH=${VERSION_PARTS[2]:-0}

# Calculate new version
case $BUMP_TYPE in
    major)
        NEW_MAJOR=$((MAJOR + 1))
        NEW_MINOR=0
        NEW_PATCH=0
        ;;
    minor)
        NEW_MAJOR=$MAJOR
        NEW_MINOR=$((MINOR + 1))
        NEW_PATCH=0
        ;;
    patch)
        NEW_MAJOR=$MAJOR
        NEW_MINOR=$MINOR
        NEW_PATCH=$((PATCH + 1))
        ;;
    *)
        echo "Error: Invalid bump type '$BUMP_TYPE'."
        echo "Valid options: major, minor, patch"
        exit 1
        ;;
esac

NEW_VERSION="${NEW_MAJOR}.${NEW_MINOR}.${NEW_PATCH}"

echo "Current version: $LATEST_TAG"
echo "New version:     $NEW_VERSION"
echo ""

# Warn if the changelog has no unreleased entries to ship.
CHANGELOG="CHANGELOG.md"
if [ -f "$CHANGELOG" ]; then
    UNRELEASED=$(awk '/^## \[Unreleased\]/{f=1; next} /^## \[/{f=0} f' "$CHANGELOG" | grep -E '^\s*[-*]' || true)
    if [ -z "$UNRELEASED" ]; then
        echo "WARNING: CHANGELOG.md has no entries under [Unreleased]."
        echo "         The release changelog will be empty. Add entries first, or continue anyway."
        echo ""
    fi
fi

read -p "Do you want to create and push the release '$NEW_VERSION'? (y/n) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]
then
    BRANCH=$(git rev-parse --abbrev-ref HEAD)

    # Roll [Unreleased] into a dated, versioned section and open a fresh [Unreleased].
    if [ -f "$CHANGELOG" ]; then
        TODAY=$(date +%Y-%m-%d)
        perl -0pi -e "s/## \[Unreleased\]/## [Unreleased]\n\n## [$NEW_VERSION] - $TODAY/" "$CHANGELOG"
        git add "$CHANGELOG"
        git commit -m "Release $NEW_VERSION"
        echo "Pushing $BRANCH to origin..."
        git push origin "$BRANCH"
    fi

    echo "Creating tag $NEW_VERSION..."
    git tag "$NEW_VERSION"
    echo "Pushing tag to origin..."
    git push origin "$NEW_VERSION"
    echo "Done! The GitHub Action will now build the release for $NEW_VERSION."
else
    echo "Aborted."
    exit 0
fi

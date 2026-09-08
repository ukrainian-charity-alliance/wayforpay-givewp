#!/usr/bin/env bash

set -e

# Release tooling, in two phases.
#
#   composer release:prepare <major|minor|patch>
#   composer release:tag [version]
#
# Two phases because `main` is protected: a ruleset requires a pull request and
# a passing `test` check, with no bypass actors, so nothing can be pushed to it
# directly. The changelog roll therefore has to travel through a PR, and only
# once that has merged can the release be tagged.
#
# Neither phase acts on whatever branch you happen to have checked out:
# `prepare` branches from origin/main and `tag` tags origin/main. Your current
# checkout only has to be clean.

CHANGELOG="CHANGELOG.md"

usage() {
    echo "Usage: composer release:prepare <major|minor|patch>"
    echo "       composer release:tag [version]"
    echo ""
    echo "  prepare  Roll the [Unreleased] entries into a dated version section"
    echo "           on a release/X.Y.Z branch and open its pull request."
    echo "  tag      Tag origin/main once that pull request has merged, which"
    echo "           triggers the build-and-release workflow."
    exit 1
}

die() {
    echo "Error: $*" >&2
    exit 1
}

# The newest `## [X.Y.Z]` heading on stdin, ignoring [Unreleased].
#
# Versions come from the changelog rather than `git describe` because main is
# squash-merge only: a tag placed on a release branch never becomes an ancestor
# of main, so tag reachability cannot be trusted for version arithmetic.
latest_version() {
    grep -oE '^## \[[0-9]+\.[0-9]+\.[0-9]+\]' | head -n 1 | tr -d '#[] '
}

bump_version() {
    local version=$1 bump=$2
    IFS='.' read -r -a parts <<< "$version"
    local major=${parts[0]:-0} minor=${parts[1]:-0} patch=${parts[2]:-0}

    case $bump in
        major) echo "$((major + 1)).0.0" ;;
        minor) echo "${major}.$((minor + 1)).0" ;;
        patch) echo "${major}.${minor}.$((patch + 1))" ;;
    esac
}

# Dots are wildcards in ERE; match the literal version.
version_pattern() {
    local escaped=${1//./\\.}
    echo "^## \[$escaped\]"
}

require_clean_tree() {
    [ -z "$(git status --porcelain)" ] ||
        die "working tree is not clean. Commit or stash your changes first."
}

require_tag_absent() {
    local version=$1 hint=${2:-}
    ! git rev-parse -q --verify "refs/tags/$version" >/dev/null ||
        die "tag '$version' already exists locally.${hint:+ $hint}"
    [ -z "$(git ls-remote --tags origin "refs/tags/$version")" ] ||
        die "tag '$version' already exists on origin.${hint:+ $hint}"
}

confirm() {
    read -p "$1 (y/n) " -n 1 -r
    echo
    [[ $REPLY =~ ^[Yy]$ ]] || { echo "Aborted."; exit 0; }
}

cmd_prepare() {
    local bump=${1:-}
    case "$bump" in
        major | minor | patch) ;;
        *) usage ;;
    esac

    require_clean_tree

    echo "Fetching origin..."
    git fetch --quiet --tags origin

    local base_changelog current_version new_version release_branch
    base_changelog=$(git show "origin/main:$CHANGELOG")
    current_version=$(printf '%s\n' "$base_changelog" | latest_version)
    current_version=${current_version:-0.0.0}
    new_version=$(bump_version "$current_version" "$bump")
    release_branch="release/$new_version"

    echo "Current version: $current_version (newest section in origin/main's changelog)"
    echo "New version:     $new_version"
    echo ""

    require_tag_absent "$new_version"

    printf '%s\n' "$base_changelog" | grep -qE "$(version_pattern "$new_version")" &&
        die "origin/main's changelog already has a section for $new_version."

    ! git rev-parse -q --verify "refs/heads/$release_branch" >/dev/null ||
        die "branch '$release_branch' already exists locally."
    [ -z "$(git ls-remote --heads origin "$release_branch")" ] ||
        die "branch '$release_branch' already exists on origin."

    # Warn if there is nothing to ship. The build fails on an empty section,
    # since changelog-to-readme.php finds no entries to sync into readme.txt.
    local unreleased
    unreleased=$(printf '%s\n' "$base_changelog" |
        awk '/^## \[Unreleased\]/{f=1; next} /^## \[/{f=0} f' |
        grep -E '^\s*[-*]' || true)
    if [ -z "$unreleased" ]; then
        echo "WARNING: origin/main has no entries under [Unreleased]."
        echo "         The release changelog would be empty and the build would fail."
        echo ""
    fi

    confirm "Prepare release '$new_version' on $release_branch?"

    local original_branch
    original_branch=$(git rev-parse --abbrev-ref HEAD)

    git switch --quiet --create "$release_branch" origin/main

    # Roll [Unreleased] into a dated, versioned section, leaving [Unreleased]
    # in place for the next cycle.
    perl -0pi -e "s/## \[Unreleased\]/## [Unreleased]\n\n## [$new_version] - $(date +%Y-%m-%d)/" "$CHANGELOG"
    git add "$CHANGELOG"
    git commit --quiet -m "Release $new_version"

    echo "Pushing $release_branch to origin..."
    if ! git push --quiet --set-upstream origin "$release_branch"; then
        echo "Push failed. Rolling back the release branch." >&2
        git switch --quiet "$original_branch"
        git branch --quiet -D "$release_branch"
        exit 1
    fi

    if command -v gh >/dev/null 2>&1; then
        gh pr create \
            --base main \
            --head "$release_branch" \
            --title "Release $new_version" \
            --body "Rolls the \`[Unreleased]\` changelog entries into \`## [$new_version]\`.

Once this merges, run \`composer release:tag\` to tag \`main\` and trigger the
build-and-release workflow."
    else
        echo ""
        echo "gh CLI not found. Open the pull request manually:"
        echo "  https://github.com/ukrainian-charity-alliance/wayforpay-givewp/compare/main...$release_branch"
    fi

    git switch --quiet "$original_branch"

    echo ""
    echo "Done. Once the pull request has merged, run: composer release:tag"
}

cmd_tag() {
    echo "Fetching origin..."
    git fetch --quiet --tags origin

    local main_changelog version
    main_changelog=$(git show "origin/main:$CHANGELOG")

    version=${1:-}
    if [ -n "$version" ]; then
        [[ $version =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] ||
            die "'$version' is not a X.Y.Z version."
    else
        version=$(printf '%s\n' "$main_changelog" | latest_version)
        [ -n "$version" ] ||
            die "origin/main's changelog has no released version section to tag."
    fi

    # A version section only reaches origin/main when its release pull request
    # merges, so these two checks are what stop a release being tagged early.
    # The section check catches an explicitly named version that has not landed;
    # the tag check catches the derived case, where the newest section on main
    # is the *previous* release because the new one is still sitting in its PR.
    printf '%s\n' "$main_changelog" | grep -qE "$(version_pattern "$version")" ||
        die "origin/main's changelog has no section for $version. Has its release pull request merged?"

    require_tag_absent "$version" \
        "That is the newest version on origin/main, so nothing new has landed — has the release pull request merged?"

    local target
    target=$(git rev-parse origin/main)

    echo "Tagging $version at origin/main:"
    git --no-pager log --oneline -1 "$target"
    echo ""

    confirm "Create and push tag '$version'?"

    git tag "$version" "$target"
    echo "Pushing tag to origin..."
    if ! git push origin "$version"; then
        echo "Tag push failed. Removing the local tag." >&2
        git tag -d "$version"
        exit 1
    fi

    echo "Done! The GitHub Action will now build the release for $version."
}

case "${1:-}" in
    prepare)
        shift
        cmd_prepare "$@"
        ;;
    tag)
        shift
        cmd_tag "$@"
        ;;
    *)
        usage
        ;;
esac

#!/usr/bin/env bash
# Build wp-radius-seo.zip from tracked files only, excluding development files.
# Used by .github/workflows/release.yml; run locally with `bash bin/build-zip.sh` to inspect the package.
set -euo pipefail

cd "$(dirname "$0")/.."
rm -rf build wp-radius-seo.zip
mkdir -p build/wp-radius-seo

# Tracked files only (never local settings, vendor/, or untracked scratch files), minus dev-only paths.
git ls-files -z \
  | grep -zvE '^(\.github/|\.claude/|\.cursor/|tests/|bin/|docs/|tools/|workflows/|\.gitignore$|\.gitattributes$|\.distignore$|\.mcp\.json$|AGENTS\.md$|CLAUDE\.md$|CLAUDE\.local\.md$|CLAUDE\.local\.md\.example$|CONTRIBUTING\.md$|INTENT\.md$|intent\.md$|PLAN\.md$|plan\.md$|docker-compose\.yml$|composer\.(json|lock)$|phpcs\.xml\.dist$|phpunit\.xml\.dist$|marketing/.*\.(md|zip)$|marketing/guide/)' \
  | xargs -0 -I{} cp --parents {} build/wp-radius-seo/

( cd build && zip -qr9 ../wp-radius-seo.zip wp-radius-seo )
unzip -l wp-radius-seo.zip

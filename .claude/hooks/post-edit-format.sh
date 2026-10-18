#!/usr/bin/env bash
# PostToolUse. Format PHP with phpcbf when it is installed. Never blocks.
set -u

input="$(cat || true)"
path="$(printf '%s' "$input" | sed -n 's/.*"file_path"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n 1)"
path="${path//\\\\//}"
case "$path" in
  *.php|*.PHP) ;;
  *) exit 0 ;;
esac
if [[ ! -f "$path" ]]; then
  exit 0
fi

root="$(cd "$(dirname "$0")/../.." && pwd)"
standard="WordPress"
if [[ -f "$root/phpcs.xml.dist" ]]; then
  standard="$root/phpcs.xml.dist"
fi

if command -v phpcbf >/dev/null 2>&1; then
  phpcbf --standard="$standard" "$path" >/dev/null 2>&1 || true
elif [[ -x "$root/vendor/bin/phpcbf" ]]; then
  "$root/vendor/bin/phpcbf" --standard="$standard" "$path" >/dev/null 2>&1 || true
fi

exit 0

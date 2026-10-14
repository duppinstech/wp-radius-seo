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

if command -v phpcbf >/dev/null 2>&1; then
  phpcbf --standard=WordPress "$path" >/dev/null 2>&1 || true
elif [[ -x vendor/bin/phpcbf ]]; then
  vendor/bin/phpcbf --standard=WordPress "$path" >/dev/null 2>&1 || true
fi

exit 0

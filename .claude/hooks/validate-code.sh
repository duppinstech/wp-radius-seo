#!/usr/bin/env bash
# PostToolUse. php -l on PHP files Claude just wrote. Exit 0 allows. Exit 2 blocks.
set -u

input="$(cat || true)"
if [[ -z "${input}" ]]; then
  exit 0
fi

if ! command -v php >/dev/null 2>&1; then
  exit 0
fi

tmp="$(mktemp "${TMPDIR:-/tmp}/radius-hook.XXXXXX.php")"
trap 'rm -f "$tmp"' EXIT
cat > "$tmp" <<'PHP'
<?php
$input = stream_get_contents(STDIN);
$j = json_decode($input, true);
if (!is_array($j)) {
	exit(0);
}
$in = (isset($j['tool_input']) && is_array($j['tool_input'])) ? $j['tool_input'] : array();
$path = '';
foreach (array('file_path', 'path') as $key) {
	if (isset($in[$key]) && is_string($in[$key]) && $in[$key] !== '') {
		$path = $in[$key];
		break;
	}
}
if ($path === '' || !preg_match('/\.php$/i', $path)) {
	exit(0);
}
$path = str_replace('\\', '/', $path);
if (!is_file($path)) {
	exit(0);
}
$out = array();
$code = 0;
exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
if ($code !== 0) {
	fwrite(STDERR, 'PHP syntax check failed for ' . $path . PHP_EOL . implode(PHP_EOL, $out) . PHP_EOL);
	exit(2);
}
exit(0);
PHP

set -o pipefail
printf '%s' "$input" | php "$tmp"
exit $?

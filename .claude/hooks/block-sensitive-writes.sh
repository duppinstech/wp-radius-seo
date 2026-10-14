#!/usr/bin/env bash
# PreToolUse. Exit 0 allows. Exit 2 blocks. stderr is the reason Claude sees.
set -u

input="$(cat || true)"
if [[ -z "${input}" ]]; then
  exit 0
fi

if ! command -v php >/dev/null 2>&1; then
  if printf '%s' "$input" | grep -Eq '"file_path"[^"]*"([^"]*[/\\])?(\.env|\.env\.[^"]*|wp-config\.php|wp-config-local\.php|id_rsa|id_ed25519|credentials\.json)'; then
    echo "Blocked write to a secret or local config path." >&2
    exit 2
  fi
  if printf '%s' "$input" | grep -Eq 'git[[:space:]]+push[[:space:]]+.*(--force|[[:space:]]-f)'; then
    echo "Blocked force push." >&2
    exit 2
  fi
  if printf '%s' "$input" | grep -Eq 'git[[:space:]]+reset[[:space:]]+--hard'; then
    echo "Blocked git reset --hard." >&2
    exit 2
  fi
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
foreach (array('file_path', 'path', 'notebook_path') as $key) {
	if (isset($in[$key]) && is_string($in[$key]) && $in[$key] !== '') {
		$path = $in[$key];
		break;
	}
}
$cmd = (isset($in['command']) && is_string($in['command'])) ? $in['command'] : '';
$norm = strtolower(str_replace('\\', '/', $path));
$base = basename($norm);
$blocked = false;
$reason = '';
$names = array('.env', 'wp-config.php', 'wp-config-local.php', 'id_rsa', 'id_ed25519', 'credentials.json');
if ($path !== '') {
	$secret_name = in_array($base, $names, true) || strpos($base, '.env.') === 0 || substr($base, -4) === '.pem';
	if ($secret_name) {
		$blocked = true;
		$reason = 'Blocked write to a secret or local config path: ' . $path;
	}
	if (preg_match('#(^|/)\.git(/|$)#', $norm)) {
		$blocked = true;
		$reason = 'Blocked write inside .git: ' . $path;
	}
}
if ($cmd !== '') {
	if (preg_match('#\bgit\s+push\b.*(\s-f\b|\s--force\b)#i', $cmd)) {
		$blocked = true;
		$reason = 'Blocked force push. Push normally, or ask the user before rewriting history.';
	}
	if (preg_match('#\bgit\s+reset\s+--hard\b#i', $cmd)) {
		$blocked = true;
		$reason = 'Blocked git reset --hard.';
	}
	if (preg_match('#\bgit\s+clean\b[^\n]*\s-\S*f#i', $cmd)) {
		$blocked = true;
		$reason = 'Blocked git clean that deletes files.';
	}
	if (preg_match('#(^|[\s;&|])rm\s+-rf\s+(/|~|\.)(\s|$)#i', $cmd)) {
		$blocked = true;
		$reason = 'Blocked recursive delete of a root or home path.';
	}
}
if ($blocked) {
	fwrite(STDERR, $reason . PHP_EOL);
	exit(2);
}
exit(0);
PHP

set -o pipefail
printf '%s' "$input" | php "$tmp"
exit $?

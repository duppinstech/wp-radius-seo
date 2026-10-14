# Progress

Update this when a milestone lands. Keep the outstanding list to work that is still open. Do not invent product features here.

## Done

- 2026-10-14: Ownership recorded in `OWNERSHIP.md` and `CLAUDE.md`. Radius SEO is a Duppins Technology product. WhiteRail is the beta client and does not own the source.
- 2026-10-14: `PLAN.md` P0-02, P0-03, and P0-04 implemented in 1.6.108. Staging proof is still open. P0-01 is still open.
- 2026-10-14: Claude SDLC scaffold added from the project-structure map. Session contract (`CLAUDE.md`), roster (`AGENTS.md`), path-scoped rules, slash commands, skills, subagents, hooks, team memory, and workflows. Personal overrides are gitignored. These files are `export-ignore` and are not in the plugin ZIP.
- Plugin version at scaffold time: see `radius.php` (`RADIUS_VERSION`). No version bump for the scaffold, because it does not ship inside the plugin.

## Outstanding

### Needs a decision from the maintainer

- Which MCP servers belong in `.mcp.json`. The file is an empty `mcpServers` object so Claude Code does not try to start servers nobody configured. Candidates discussed in the structure map (GitHub, issue trackers, docs) are not wired.
- Local WordPress path, site URL, and PHP binary in `CLAUDE.local.md`. Until those are filled in, agents cannot run wp-admin or front-end checks.
- Whether the next engineering investment is a PHPUnit + WordPress test suite under `tests/`. None exists today.

### Engineering gaps

- No automated test harness. Verification is the manual checklist in `.claude/rules/testing-standard.md`.
- `phpcbf` and the WordPress PHPCS standard are not vendored. `post-edit-format.sh` no-ops until `phpcbf` is on `PATH` or at `vendor/bin/phpcbf`.
- Hooks are bash scripts. They need Git Bash, WSL, or another `bash` on Windows. `validate-code.sh` and the strict path of `block-sensitive-writes.sh` need `php` on `PATH`.
- Product backlog: the PHP tree has no `TODO` or `FIXME` markers. Outstanding product work is whatever the maintainer adds below this line.

### Product backlog

Production gaps are the open items in `PLAN.md`. Run that list in order. Do not copy the items here.

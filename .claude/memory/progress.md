# Progress

Update this when a milestone lands. Keep the outstanding list to work that is still open. Do not invent product features here.

## Done

- 2026-10-14: Named-site proofs. Beeline Transport Inc. (`https://beelinetransportinc.com`, Radius 1.6.111) was read only. P1-05, P1-06, and P1-07 closed from that look. P1-02 and P1-04 closed with a local Docker deploy plus the Beeline phone and Elementor observations. P2-10 closed on http://127.0.0.1:8088 (Apache in Docker). Nothing on the live site was edited. Docker was stopped afterward.
- 2026-10-14: Approved non-major plan items implemented in 1.6.113 on `feature/github-updater-standard`: P2-01 through P2-09 and P2-13 through P2-17. P2-11, P2-12, P0-01, and P1-08 are still open.
- 2026-10-14: Interface department added and seated at every department review, separate from Support. First interface note: `.claude/memory/meetings/2026-10-14-1556.md`. Approved work is PLAN.md P2-13 through P2-17. No product code was changed.
- 2026-10-14: First department meeting. Security, legal, finance, privacy, support, communications, and quality reported. Approved work is PLAN.md P2-01 through P2-10. P2-11 and P2-12 wait on the maintainer. Note: `.claude/memory/meetings/2026-10-14-1544.md`. No product code was changed.
- 2026-10-14: GitHub updater standard v1.0.0 is on branch `feature/github-updater-standard` (1.6.112). The class is the reference copy renamed to `Radius_GitHub_Updater`. PHPUnit covers it (`composer test`). A GitHub Release was not published. Docker was not used to open Dashboard → Updates.
- 2026-10-14: 1.6.111 deploy pair lock checked on the local Docker site. A retry updated the same landing, a planted duplicate was trashed, and two overlapping deploys left one published page. `duplicate_pages` passed.
- 2026-10-14: Local proof is `docker compose up -d`, then `docker compose down` when the check ends. The stack is not left running.
- 2026-10-14: 1.6.110 analytics, rotation batching, and system rows checked on the local Docker site at http://127.0.0.1:8088.
- 2026-10-14: Ownership recorded in `OWNERSHIP.md` and `CLAUDE.md`. Radius SEO is a Duppins Technology product. WhiteRail is the beta client and does not own the source.
- 2026-10-14: `PLAN.md` P0-02, P0-03, and P0-04 implemented in 1.6.108. Staging proof is still open. P0-01 is still open.
- 2026-10-14: Claude SDLC scaffold added from the project-structure map. Session contract (`CLAUDE.md`), roster (`AGENTS.md`), path-scoped rules, slash commands, skills, subagents, hooks, team memory, and workflows. Personal overrides are gitignored. These files are `export-ignore` and are not in the plugin ZIP.
- Plugin version at scaffold time: see `radius.php` (`RADIUS_VERSION`). No version bump for the scaffold, because it does not ship inside the plugin.

## Outstanding

### Needs a decision from the maintainer

- PLAN.md P2-11: remove the dead Purchase cards, or name a real offer. P2-12: remove "Priority email support," or state that the unlimited plan includes it. P0-01 and P1-08 are still open choices.
- Which MCP servers belong in `.mcp.json`. The file is an empty `mcpServers` object so Claude Code does not try to start servers nobody configured. Candidates discussed in the structure map (GitHub, issue trackers, docs) are not wired.
- Local WordPress is filled in `CLAUDE.local.md` (Docker at http://127.0.0.1:8088).
- Whether places, deploy, and admin screens get a WordPress integration suite. The updater already has `composer test`.

### Outstanding checks

- 2026-10-14 local Docker site `http://127.0.0.1:8088`: landing `radius-local-a` loaded `radius-frontend-analytics.js` and did not load jQuery. One visit was stored (`radius_landing_visit_count` = 1, day `2026-10-14` = 1). A second beacon and a browser reload in the same half hour left the count at 1. A `curl` user agent did not add a visit. Analytics showed 1 visit. Rotation with batch size 1 and three landings advanced the offset by 1 and scheduled the next run 120 seconds out, then the test hook was cleared and the batch size restored to 25. Deploy → System shows Persistent object cache Off and WP-Cron Visitor-triggered. The readiness score on this container is 54% because `max_execution_time` is short and there is no Redis.

### Engineering gaps

- Updater unit tests exist (`composer test`). Places, deploy, and admin screens still use the manual checklist in `.claude/rules/testing-standard.md`.
- `phpcbf` and the WordPress PHPCS standard are not vendored. `post-edit-format.sh` no-ops until `phpcbf` is on `PATH` or at `vendor/bin/phpcbf`.
- Hooks are bash scripts. They need Git Bash, WSL, or another `bash` on Windows. `validate-code.sh` and the strict path of `block-sensitive-writes.sh` need `php` on `PATH`.
- Product backlog: the PHP tree has no `TODO` or `FIXME` markers. Outstanding product work is whatever the maintainer adds below this line.

### Product backlog

Production gaps are the open items in `PLAN.md`. Run that list in order. Do not copy the items here.

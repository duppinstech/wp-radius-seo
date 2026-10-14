# Decisions

Decisions that are already in the code. Add a new entry when a choice should survive the next session. Do not reopen these without a reason recorded here.

## Canonical names replace LocaleForge

Storage, hooks, and assets use `radius_*`, `_radius_*`, and `radius-*`. `Radius_Prefix_Migration` is the only place that maps old `lf_*`, `localeforge_settings`, and `lf_*` CPTs. New code does not write legacy keys.

## Registry is the source of truth

New options, CPTs, taxonomies, and meta keys are constants on `Radius_Data_Registry` before they are used.

## CPT registration order

Radius CPTs register on `init` priority 20 so a Magic Page-style plugin can register `service-area` at priority 10 first. Rewrite changes go through `radius_needs_rewrite_flush`.

## One token renderer

Deploy builds the map in `Radius_Template_Tokens::build_map()` (place tokens, template meta, site replacers, spintax blocks) and renders with `Radius_Token_Engine::render()`. A second replacement pass is a bug.

## Empty site replacers are meaningful

An explicit stored `site_replacements` of `[]` means "do not inject the inflated defaults." Rendering falls back to per-template `_radius_xfields`.

## Builders are opt-in

Elementor JSON is copied only when `enable_elementor` is on. Beaver Builder `_fl_builder_*` layout is copied only when `enable_beaver_builder` is on and the template has a layout.

## Updates ship as a GitHub Release ZIP

`Radius_GitHub_Updater` reads the latest release. It shows an update only when the tag is newer and a `.zip` asset is attached. The archive root must be `wp-radius-seo/`. Default API cache TTL is one hour (`radius_github_updater_cache_ttl`). Checking again in wp-admin clears the updater cache when WordPress deletes the `update_plugins` transient.

## Agent docs are not plugin runtime

`.claude/`, `workflows/`, `CLAUDE.md`, `AGENTS.md`, and `.mcp.json` are committed for the team and excluded from `git archive` via `.gitattributes`. `CLAUDE.local.md` and `.claude/settings.local.json` are personal and gitignored.

## Verification is manual until a harness exists

There is no PHPUnit suite. Behavior changes are verified in wp-admin and on the front end. A future `tests/` directory needs an explicit decision recorded here and a command added to `.claude/rules/testing-standard.md`.

## 2026-10-14 — Local WordPress is a Docker stack that is not left running

Proof runs against `docker compose up -d` in this repo (http://127.0.0.1:8088). WordPress core and the database stay in Docker volumes, not in git. `docker-compose.yml` is `export-ignore`. After the check, `docker compose down` stops the containers and keeps the volume. Do not leave the stack running between tasks.

## 2026-10-14 — Deploy and library export are administrator actions

Deploy, deploy cancel, place CSV import, place CSV export, and template JSON export require `manage_options`. Editors can still edit individual templates and landings. Recorded while closing `PLAN.md` P0-03.

## 2026-10-14 — Claude SDLC scaffold

The roster, rules, commands, skills, agents, hooks, and workflows in this tree are the Claude Code SDLC scaffold. The repo objective is `INTENT.md` only. Cursor rules under `.cursor/rules/` stay the Cursor mirror of architecture and release. When a canonical id or release step changes, update both.

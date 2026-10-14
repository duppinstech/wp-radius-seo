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

`Radius_GitHub_Updater` is the Duppins Technology updater standard v1.0.0, booted from `radius.php` for `duppinstech/wp-radius-seo`. Do not edit that class per plugin. Behavior changes go in the reference, then the class is copied again. The release asset is `wp-radius-seo.zip` with one top-level `wp-radius-seo/` folder, built by `bin/build-zip.sh` when a `v*` tag is pushed. The cache is the site option `radius_github_release`. Filters are `radius_github_updater_config` and `radius_github_release_http_args`. `RADIUS_DISABLE_GITHUB_UPDATER` turns checks off.

## Agent docs are not plugin runtime

`.claude/`, `workflows/`, `CLAUDE.md`, `AGENTS.md`, and `.mcp.json` are committed for the team and excluded from `git archive` via `.gitattributes`. `CLAUDE.local.md` and `.claude/settings.local.json` are personal and gitignored.

## Updater tests are PHPUnit; other behavior is still manual

`composer test` runs `tests/GitHubUpdaterTest.php` against `Radius_GitHub_Updater` with the doubles in `tests/bootstrap.php`. Those tests do not boot WordPress. Places, deploy, tokens, and admin screens are still verified on the local Docker site. Do not claim those passed unless that site was exercised.

## 2026-10-14 — Local WordPress is a Docker stack that is not left running

Proof runs against `docker compose up -d` in this repo (http://127.0.0.1:8088). WordPress core and the database stay in Docker volumes, not in git. `docker-compose.yml` is `export-ignore`. After the check, `docker compose down` stops the containers and keeps the volume. Do not leave the stack running between tasks.

## 2026-10-14 — Deploy and library export are administrator actions

Deploy, deploy cancel, place CSV import, place CSV export, and template JSON export require `manage_options`. Editors can still edit individual templates and landings. Recorded while closing `PLAN.md` P0-03.

## 2026-10-14 — Department meetings are noted and valid items are approved

After a department meeting, approved items that are security fixes, bug fixes, copy corrections, or small interface fixes are implemented in that session. A new capability, a new screen, a rearranged admin menu, or a behavior change the maintainer has not approved stays on `PLAN.md`. Items marked waiting stay waiting.

Interface (`@ui-reviewer`) sits at every department review, separate from Support. Support owns the sentence an owner follows. Interface owns WordPress admin patterns, which Radius screen a control sits on, and use without a mouse or without seeing color. Moving a whole screen in the Radius menu waits on the maintainer. A missing label, a fake link, a focus style, or color-only status does not.

Each department review writes `.claude/memory/meetings/YYYY-MM-DD-HHMM.md`. The note records what was suggested and what was decided (approved, waiting on the maintainer, or ignored), plus the `PLAN.md` id. It does not record implementation. A valid suggestion that does not change rights, warranty, the GPL text, price, a plan, or a privacy policy, and that is not already an open maintainer choice, is approved and added to `PLAN.md`. The maintainer still decides those other items. The first note is `2026-10-14-1544.md`.

## 2026-10-14 — Department review after every developer pass

After the main session edits, `workflows/department-review.md` runs before the change is called done. Security, legal, finance, privacy, support, and communications each reply, including **No update** when their surface is untouched. Quality stays `@test-writer`. Reviewers do not edit. The main session applies consistency fixes and asks the maintainer before changing rights, warranty, price, or adding a privacy policy. There is no price list in the repo. License display defaults remain unlimited sites and no expiration until `radius_license_details` replaces them.

## 2026-10-14 — Claude SDLC scaffold

The roster, rules, commands, skills, agents, hooks, and workflows in this tree are the Claude Code SDLC scaffold. The repo objective is `INTENT.md` only. Cursor rules under `.cursor/rules/` stay the Cursor mirror of architecture and release. When a canonical id or release step changes, update both.

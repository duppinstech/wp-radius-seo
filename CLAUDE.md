# Radius SEO

Blueprint-first local SEO for WordPress. One template plus a place library deploys into landing pages and service-area hubs. Tokens, spintax, and Markdown slots fill each page. Elementor and Beaver Builder are optional.

The repo objective is `INTENT.md`. Ownership is `OWNERSHIP.md`. Read both before planning. Canonical identifiers live in `includes/class-data-registry.php`. The same architecture is mirrored for Cursor in `.cursor/rules/wp-radius-seo-architecture.mdc`. This file is the session contract. Path-scoped detail lives in `.claude/rules/` and loads only when those files are in play.

## Stack

- WordPress 6.0+, PHP 7.4+, GPL-2.0-or-later. Text domain `radius`.
- Bootstrap: `radius.php` loads the registry and prefix migration first, then the requires chain. `radius_boot()` runs on `plugins_loaded` priority 5.
- Classes `Radius_*`. Admin assets `radius-*.js` / `radius-*.css`.
- Version is the `Version` header and `RADIUS_VERSION` in `radius.php`. Keep them identical. `readme.txt` Stable tag must match when shipping.

## Hard rules

- New storage uses `radius_*` / `_radius_*` only. `lf_` and `localeforge_*` exist solely inside `Radius_Prefix_Migration`.
- Add keys to `Radius_Data_Registry` before using them.
- Escape output. Check capabilities. Verify nonces on admin POST and AJAX. Prepare SQL.
- CPT or rewrite changes must set `radius_needs_rewrite_flush` when rules change. Magic Page-style plugins register `service-area` at `init` 10. Radius CPTs register at `init` 20.
- Elementor runs only when `enable_elementor` is on. Beaver Builder runs only when `enable_beaver_builder` is on.
- A stored `site_replacements` value of `[]` clears inflated defaults and falls back to per-template `_radius_xfields`.
- Do not commit secrets, license keys, or customer site data.
- The installable ZIP is one folder, `wp-radius-seo/`, containing `radius.php`. Agent docs are `export-ignore` and must not be required at runtime.
- This is a Duppins Technology product. Odell Duppins is the author. WhiteRail is the beta client and does not own the source. Copyright, `Author` headers, license files, and package metadata name Duppins Technology only. See `OWNERSHIP.md`.

## Session reading

Read these before planning or editing, then update `progress.md` when a milestone lands:

| Need | File |
| --- | --- |
| Repo objective | `INTENT.md` |
| Ownership | `OWNERSHIP.md` |
| Open production work | `PLAN.md` |
| Runtime map | `.claude/memory/project-context.md` |
| Decisions already made | `.claude/memory/decisions.md` |
| Unfinished work | `.claude/memory/progress.md` |
| Who to delegate to | `AGENTS.md` |
| Feature, bugfix, or review steps | `workflows/` |
| This machine only | `CLAUDE.local.md` (gitignored; copy from `CLAUDE.local.md.example`) |

## SDLC

- Follow `workflows/feature-build.md`, `workflows/bug-fix.md`, or `workflows/code-review.md` for that kind of task.
- Read `AGENTS.md` before delegating. Do not give two agents write access to the same files.
- Hooks in `.claude/settings.json` block secret writes and destructive git, then syntax-check PHP after edits. Exit 0 allows. Exit 2 blocks.
- Slash commands: `/review`, `/deploy`, `/scaffold`. Skills `code-review`, `testing-patterns`, and `pr-description` load when the task matches their description.

## Ship

User-facing plugin changes get a patch bump for a small fix (`1.6.x`), a minor bump for a larger behavior change (`1.7.0`), and a major bump only for a breaking change (`2.0.0`). Bump the header and `RADIUS_VERSION` together, plus `readme.txt` Stable tag and changelog. Push `main`, tag `vX.Y.Z`, and publish a GitHub Release whose asset is:

```bash
git archive --format=zip --prefix=wp-radius-seo/ -o wp-radius-seo.zip HEAD
```

Sites using `Radius_GitHub_Updater` only see an update when the latest release has a newer tag and a `.zip` asset. There is no PHPUnit suite yet. Before committing a runtime change, start the local stack with `docker compose up -d`, prove the change at http://127.0.0.1:8088, then `docker compose down`. Do not leave those containers running. Say what you could not run.

## Safety

- Report vulnerabilities privately (`SECURITY.md`). Do not put exploit detail in public issues or commits.
- Do not force-push `main`, hard-reset, or rewrite published tags.
- Do not deploy pages or change a live site unless the user names that site and asks for it.

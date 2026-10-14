# Project context

Committed team memory. Claude reads this before planning work that depends on how Radius is built. Personal machine paths stay in `CLAUDE.local.md`.

## Objective

The repo objective is `INTENT.md`. This file is the runtime map, not a second statement of intent.

## What this repository is

A WordPress plugin. `radius.php` is the bootstrap. `includes/` holds `Radius_*` classes. `assets/` holds admin CSS and JS. `readme.txt` is the WordPress readme and the changelog source. `languages/` is for optional translations.

Agent and editor files (`.claude/`, `.cursor/rules/`, `workflows/`) are for people and coding agents. `.gitattributes` marks them `export-ignore`, so they are not in the installable ZIP.

## Runtime map

1. `Radius_Data_Registry` defines canonical ids. `Radius_Prefix_Migration::maybe_migrate()` runs before the rest of the includes.
2. The requires chain loads taxonomy, settings, license, updater, tokens, geo, deploy, builders, import, admin, then `Radius_Plugin`.
3. `radius_boot()` on `plugins_loaded` priority 5 starts SEO integrations, redirects, the license client, and `Radius_Plugin::instance()`.
4. `Radius_Plugin` registers CPTs at `init` priority 20 and filters content, title, and permalinks for landings and template preview.
5. `Radius_Deploy_Service::deploy()` takes a template and place ids, builds a token map, renders it, and creates or updates the post.
6. `Radius_Token_Engine` replaces `{{token}}` and `[token]`, and expands `{a|b|c}` spintax. Keys pass through `sanitize_key()`.

## Canonical ids

| Kind | Id |
| --- | --- |
| Option | `radius_settings` |
| CPTs | `radius_template`, `radius_landing`, `radius_service_area` |
| Taxonomy | `radius_place` (places are terms) |
| Post meta | `_radius_template_id`, `_radius_place_id`, `_radius_spintax_blocks`, `_radius_xfields`, `_radius_dynamic_content_mode`, `_radius_rotation_mode` |
| Term meta | `radius_lat`, `radius_lng`, `radius_country`, `radius_region`, `radius_state`, `radius_postal`, `radius_external_id` |

The full list, including migration maps, is `includes/class-data-registry.php`.

## Settings that change behavior

- `Radius_Settings::get()` merges defaults. A missing or empty `site_replacements` array is filled from `default_site_replacements()` (hyphen keys such as `company-name`, `roadside-keyword`, `phone-number`). An explicit stored `[]` clears those defaults and falls back to per-template `_radius_xfields`.
- `service_area_url_slug` is the hub slug. If it is empty, settings merge the legacy `landing_url_slug`.
- `enable_elementor` and `enable_beaver_builder` gate builder copy during deploy.

## Release shape

Version is `1.6.x` until a change is large enough for `1.7.0`. Sites update through GitHub Releases. The latest release needs a tag newer than the installed version and a ZIP asset named `wp-radius-seo.zip` whose root folder contains `radius.php`.

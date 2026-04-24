=== Radius SEO ===
Contributors: duppinstech
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Blueprint-first local SEO: templates, CSV place library, batch deploy, tokens, spintax, Markdown slots; optional Elementor.

== Quick start ==

1. Radius → Location library → upload CSV (columns: name, slug, country, region, state, zip, lat, lng).
2. Templates → add a blueprint using tokens in the title/body.
3. Deploy → pick the template, paste place term IDs from the library table, run.
4. Settings → enable Elementor if you want to design landings in Elementor.

== Updates ==

This plugin does not ship a custom WordPress.org–disallowed update checker. To update from [GitHub](https://github.com/oduppinsjr/wp-radius-seo/releases), either upload a new `radius.zip` (release asset with top-level `radius/` folder) via **Plugins → Add New → Upload Plugin**, or install a Git-based updater plugin (for example **Git Updater**) and point it at this repository.

== Hooks ==

* `radius_legacy_template_post_type` — default legacy template post type slug for migration.
* `radius_legacy_location_taxonomy` — default legacy location taxonomy slug for migration.
* `radius_landing_content` — filter rendered landing HTML.
* `radius_legacy_import_slug_lookup_chunk` — max legacy slugs per `get_terms` lookup (default 25) to shorten SQL `IN` lists.
* `radius_legacy_import_places_batch_result` — filter the stats array after each legacy place import batch.
== Changelog ==

= 1.4.1 =
* Rebrand: Radius class prefix; Plugin Check cleanups; bundled Chart.js for analytics; GitHub releases documented for manual or Git Updater installs (no bundled custom updater).

= 1.0.0 =
* Location library (lf_place taxonomy) with CSV import and paged admin lists
* Deploy service with configurable batch size and idempotent updates
* Token + spintax engine
* Markdown fenced-slot import to template meta
* Optional legacy template/location import (filterable slugs)
* Elementor support (toggle in Settings)
* Unified admin menu

= 0.2.0 =
* Unified admin menu; roadmap placeholders

= 0.1.0 =
* Initial scaffold

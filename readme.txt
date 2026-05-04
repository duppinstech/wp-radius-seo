=== Radius SEO ===
Contributors: duppinstech
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.6.14
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Blueprint-first local SEO: templates, CSV place library, batch deploy, tokens, spintax, Markdown slots; optional Elementor.

== Quick start ==

1. Radius → Location library → upload CSV (columns: name, slug, country, region, state, zip, lat, lng).
2. Templates → add a blueprint using tokens in the title/body.
3. Deploy → pick the template, paste place term IDs from the library table, run.
4. Settings → enable Elementor if you want to design landings in Elementor.

== Updates ==

The plugin checks [GitHub Releases](https://github.com/oduppinsjr/wp-radius-seo/releases/latest) for a newer version and shows an update under **Dashboard → Updates** when the latest release includes a `.zip` asset (built with a single top-level folder containing `radius.php`, e.g. `wp-radius-seo/radius.php`). Use **Dashboard → Updates → Check again** (or your host’s cron) so WordPress refetches; 1.6.2+ clears the plugin’s GitHub cache when WordPress refreshes plugin updates. You can still upload a ZIP manually via **Plugins → Add New → Upload Plugin**.

Disable GitHub checks: `add_filter( 'radius_github_updater_enabled', '__return_false' );`

Change repository (forks): `add_filter( 'radius_github_updater_repo', fn() => 'owner/wp-radius-seo' );`

== Hooks ==

* `radius_legacy_template_post_type` — default legacy template post type slug for migration.
* `radius_legacy_location_taxonomy` — default legacy location taxonomy slug for migration.
* `radius_landing_content` — filter rendered landing HTML.
* `radius_legacy_import_slug_lookup_chunk` — max legacy slugs per `get_terms` lookup (default 25) to shorten SQL `IN` lists.
* `radius_legacy_import_places_batch_result` — filter the stats array after each legacy place import batch.
* `radius_github_updater_cache_ttl` — seconds to cache GitHub Releases API JSON (default 3600).
* `radius_maintenance_flush_object_cache` — return true to call `wp_cache_flush()` when using **Apply recommended updates** (default false).
* `radius_maintenance_applied` — action after maintenance apply finishes (rewrites, updater cache bust, optional object cache).
* `radius_admin_maintenance_show_notice` — return false to hide the admin maintenance banner when it would otherwise show.
* `radius_token_engine_after_strip_unresolved` — filter HTML/text after removing unresolved `{{token}}` placeholders.
* `radius_token_engine_collapse_empty_paragraphs` — return false to skip removing empty `<p></p>` wrappers after stripping placeholders (default true).
* `radius_elementor_exclude_post_types_from_admin_meta_queries` — array of CPT slugs to omit from Elementor’s admin `WP_Query` that lists “built with Elementor” across all supported types (reduces load from huge `radius_landing` tables). Return an empty array to keep default Elementor behavior.
* `radius_deploy_batch_time_limit` — seconds for PHP `max_execution_time` during chained deploy AJAX (default 300, clamped 60–600).
* `radius_migration_elementor_source_post_id` — adjust which post ID Elementor document meta is copied from when Magic Page embeds a library template (shortcode / meta) instead of storing `_elementor_data` on the magicpage row.
* `radius_migration_imported_template_title` — filter the `radius_template` title when importing a Magic Page blueprint (default: legacy title with `[location]` etc. converted to `{{place_name}}`, …).
* `radius_migration_clear_imported_template_content_when_elementor_builder` — return false to keep classic/block `post_content` after import when Elementor builder data exists (default true: clear so Elementor-only templates do not show duplicate classic markup).
* `radius_migration_import_deep_token_meta_keys` — post meta keys (default `_elementor_data`, `_elementor_page_settings`) that receive recursive Magic Page → `{{token}}` conversion after import.
* `radius_migration_spintax_import_elementor_meta_keys` — post meta keys that receive `{spintax_*}` / legacy token replacement during global spintax import (default `_elementor_data`, `_elementor_page_settings`, `_radius_xfields`).
* `radius_migration_legacy_location_zip_meta_keys` — legacy location term meta keys tried when resolving zip for service-area anchor mapping (default `zip`, `Zip`, `ZIP`, `postal_code`, `postal`, `Postcode`).
== Changelog ==

= 1.6.14 =
* Global spintax import (and automated migration spintax step): when “replace shortcodes” is used, also rewrite `{spintax_…}` and legacy bracket tokens inside `_elementor_data`, `_elementor_page_settings`, and `_radius_xfields`—not only the post title/body. Spintax **variation** lines get the same treatment. Cloned templates (roadside / heavy / equipment) now also map `spintax_towing` → `spintax_roadside` / `heavy` / `equipment` before the generic `towing_` → prefix swaps.
* Service area anchors: more reliable legacy → `radius_place` matching—try `_radius_imported_from_term` as string or number, scan terms if meta_query misses; read zip from additional legacy term meta keys and from the term description; match `radius_postal` with exact or ZIP+4-style values.

= 1.6.13 =
* Magic Page migration wizard: preserve “run this step” choices when starting migration; clear recorded step flags when redoing a completed step so templates, replacers, and anchors can be run again without the UI resetting checkboxes.
* Service area anchors: derive legacy location IDs from magicpage template HTML, Elementor JSON, and post meta when Magic Page option rows are empty; map legacy terms to `radius_place` via import bridge (`_radius_imported_from_term`), slug, or zip → `radius_postal`.

= 1.6.12 =
* Magic Page / Elementor import: store `_elementor_page_settings` as a PHP array (WordPress-serialized), not a JSON string — fixes Elementor editor fatal on PHP 8+ (“Cannot access offset of type string on string” in `Page\Manager::get_saved_settings`). Keyword swaps use the same rule.
* On **Templates → Edit**, normalize page settings once when opening a `radius_template` so already-imported broken rows self-heal without re-import.

= 1.6.11 =
* Admin notice: only show the “Apply recommended updates” banner after **LocaleForge → Radius** database migration (lf_* / `localeforge_settings` detected), not on every fresh install. Schema bumps and service-area slug saves still flush permalinks automatically in the background without nagging.
* Banner copy explains LocaleForge migration when applicable; optional filter `radius_admin_maintenance_show_notice` to hide it.

= 1.6.10 =
* Magic Page template import: resolve Elementor data from linked Elementor library templates (`[elementor-template id="…"]` and common custom meta), use Elementor’s `copy_elementor_meta` when available, then fall back to manual `_elementor_*` copy; clear classic `post_content` when builder JSON exists so Elementor is not mixed with leftover classic markup.
* Import applies Magic Page bracket/shortcode token conversion inside `_elementor_data` / page settings JSON, not only the post title/body.
* Automated migration variant titles default to full towing-style names with `{{place_name}}`, `{{region}}` (Roadside, Heavy, Heavy Equipment).

= 1.6.9 =
* Magic Page migration wizard: load the tour whenever Magic Page is active and migration is not finished — detection of legacy CPT/tax/options no longer gates the modal (fixes missing wizard on installs where slugs differ or data is not detected yet).
* Magic Page detection: treat any active plugin whose path contains `magic-page` as Magic Page (covers white-label / nonstandard install folders in addition to known basenames).

= 1.6.8 =
* Deploy: clearer alerts when the server returns HTML, timeouts, 5xx, or non-JSON (instead of only “Unexpected server response”); chained deploy parses text first so failures are classified.
* Deploy batch AJAX: optional `radius_deploy_batch_time_limit` filter, raised admin memory limit when available, and try/catch with server-side logging on unhandled errors.

= 1.6.7 =
* Elementor: exclude `radius_landing` and `radius_service_area` from Elementor’s bulk admin queries that join `wp_postmeta` for `_elementor_edit_mode` (recent documents, floating buttons UI, etc.) so very large landing libraries do not slow every Elementor admin request. Editing landings in Elementor is unchanged. Optional filter `radius_elementor_exclude_post_types_from_admin_meta_queries` to adjust or disable.

= 1.6.6 =
* Deploy / dynamic output: remove unresolved `{{token}}` placeholders when a spintax/x-field key does not exist for that template (e.g. fewer paragraphs on one service variant); optionally collapse empty paragraph tags left behind. Nested token assembly during map building is unchanged.

= 1.6.5 =
* Migration wizard: per-step run checkboxes, Completed/Incomplete badges, and a locked “Deploy & verify” row (Deploy, Location library, Service areas) until all four steps are satisfied; redo by re-checking a step; wizard assets load only when Magic Page is active; dismiss banner clears when all core steps are complete.
* Import → Magic Page migration: fixed PHP parse error on the migration checklist loop; checklist uses the same Completed/Incomplete badges as the modal.

= 1.6.4 =
* Migration: Magic Page wizard records completed steps and activity; resumes without redoing work already done or detected on the site (places, templates, replacers, service anchors); Import tab shows a progress checklist and log.
* Admin: removed the dashboard header logo so notices and layout are less cramped.
* Legacy template import: copies Elementor/builder post meta where present; legacy place import fires a completion event for the migration wizard.
* Service areas: location suggestion buttons share Radius CSS classes so styling and clicks behave consistently.

= 1.6.3 =
* Admin: WooCommerce-style **Apply recommended updates** banner (flush permalinks hard, bust GitHub release cache, optional object cache via filter) after LocaleForge migration, schema bumps, or service-area slug changes; dismiss without applying still supported.

= 1.6.2 =
* GitHub updater: clear stale release cache whenever WordPress refreshes plugin updates (so new releases appear immediately after “Check again”); default API cache shortened to 1 hour (filter `radius_github_updater_cache_ttl`).

= 1.6.1 =
* Settings → **Database** tab: Magic Page storage summary (options + post meta row counts and approximate data size); Magic Page options cleanup moved here from Integrations.

= 1.6.0 =
* GitHub Releases updater: Dashboard → Updates when the latest release includes a ZIP asset (see readme Updates section).
* Rebrand, site replacer defaults, prefix migration, and related admin assets (see git history).

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

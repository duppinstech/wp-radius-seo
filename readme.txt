=== Radius SEO ===
Contributors: duppinstech
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.6.29
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
* `radius_legacy_import_places_use_term_id_cursor` — return false to use SQL `OFFSET` for legacy place batches instead of `term_id` cursor pagination (default true; cursor avoids slow deep offsets).
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
* `radius_migration_clone_elementor_meta_keys_exclude` — list of `_elementor*` post meta key names to skip in the bulk meta copy before Elementor’s document copy runs when cloning migration variants (default: every `_elementor` key found on the source template).
* `radius_migration_wizard_show_without_magic_page` — return true to load the migration wizard when Magic Page is already deactivated (default false unless migration state/steps/legacy data indicate an in-progress migration).
* `radius_magic_page_landing_post_types` — post types scanned for Magic Page mass landings (default `page`).
* `radius_magic_page_landing_location_meta_keys` — meta key candidates for the “location” side of the footprint (must be non-empty; defaults lead with Magic Page’s `_location_id`).
* `radius_magic_page_landing_group_meta_keys` — meta key candidates for the “group” side of the footprint (must be non-empty; defaults lead with Magic Page’s `_group_id`).
* `radius_magic_page_landing_abort_if_candidates_match_all_pages` — abort bulk delete when candidate count equals total posts in scanned types (default true).
* `radius_migration_service_area_template_id` — filter the `radius_template` ID written to **Settings → Service area template (default)** after the automated templates pipeline (default: towing/base template ID).
* `radius_migration_yoast_service_line_map` — filter focus keyword, SEO title, and meta description (token strings) per service line (`towing`, `roadside`, `heavy`, `equipment`) when applying Yoast template meta after migration.
* `radius_magic_page_anchor_settings_option_names` — `wp_option` names for Magic Page location rows used as service anchors (defaults: `magic_page_location_radius_settings`, `_magic_page_export_static_settings`; later options override earlier rows with the same legacy term id).
* `radius_magic_page_anchor_row_legacy_term_keys` — keys tried on each saved row to find the legacy location term ID.
* `radius_migration_radius_template_legacy_location_ids` — filter location term IDs gathered from imported `radius_template` posts for anchor migration.
* `radius_magic_page_xfields_option_names` — `wp_option` names holding Magic Page global xfields (`key` => `value` buckets), default `_magic_page_xfields`.
== Changelog ==

= 1.6.29 =
* **Legacy place import (AJAX):** Batches use **term_id cursor** pagination (`term_id > last`) instead of **OFFSET** for loading legacy terms. Deep imports (thousands of locations) stay much closer to **constant** query cost per batch instead of slowing as the offset grows. New POST param `cursor_term_id`; response includes `next_cursor_term_id`. Filter `radius_legacy_import_places_use_term_id_cursor` to opt out. Non-AJAX single batch form is unchanged (OFFSET).

= 1.6.28 =
* **Migration wizard:** One AJAX **`steps_reset`** replaces multiple **`step_reset`** calls when you re-run recorded steps (fewer POSTs to `admin-ajax.php`). **`postWizard`** parses responses safely so HTTP 403 / HTML firewall pages no longer throw JSON syntax errors in the console.

= 1.6.27 =
* **Migration wizard:** When re-running selected steps, **step reset** requests run **one after another** instead of in parallel, reducing burst POSTs to `admin-ajax.php` (helps strict WAFs / rate limits).

= 1.6.26 =
* **Migration / Yoast:** After the templates pipeline, each service template gets Yoast **focus keyphrases** (towing, roadside assistance, heavy towing, heavy equipment towing) and **SEO title** / **meta description** set to `{{towing-meta-title}}` … `{{equipment-meta-desc}}` (resolved on deploy from **Settings → Site replacers**). Default replacer rows added; filter `radius_migration_yoast_service_line_map` to customize.

= 1.6.25 =
* **Migration wizard:** **Overall progress** bar (0–100%) reflects completed steps only—**100%** when all eight steps are complete. **Start** is blocked if the highest checked step has incomplete/unchecked earlier steps. During a run, the flow **stops** if any prior step is incomplete or if the server does not mark a executed step **completed** after its action.

= 1.6.24 =
* **Migration wizard:** Place step completes only when the **Radius place count matches** the legacy location taxonomy (when it still has terms). Removed the places progress bar; **Deploy & verify** block removed; added steps to **deactivate/delete the Magic Page plugin**, **deploy service areas**, then **deploy all four service templates** via the same batch AJAX as the Deploy screen. **Finish** shows a **completion banner** and marks migration complete when landing deploy finishes. Wizard can run even when landings already exist. REST: `radius_magic_page_plugin_basename` + `find_magic_page_plugin_basename_for_removal()`; `radius_migration_wizard_deploy_landing_slugs` for template order.

= 1.6.23 =
* **Service anchors:** Read **`locations`** from **`_magic_page_export_static_settings`** (`id` = legacy location term id, `radius` = miles). Default **25** miles when missing or invalid. Per-row `radius` / `radius_miles` is applied before the `radius_migration_anchor_radius_miles` filter.

= 1.6.22 =
* **Site replacers (migration):** Merge Magic Page global xfields from **`_magic_page_xfields`** in `wp_options` (serialized map: `company-name`, `company-short`, `phone-number`, keywords, etc.). Direct key match to Radius site replacer keys; option merge runs after template `_radius_xfields` rows so Magic Page options win. Works even when the imported template has no `_radius_xfields` meta.

= 1.6.21 =
* **Migration:** After the templates pipeline finishes, **Settings → Service area template (default)** is set to the towing / “24/7” base template (`service_area_template_id`). Override with filter `radius_migration_service_area_template_id`.
* **Service area anchors:** Magic Page option rows are parsed more flexibly (JSON, serialized, `services` key, plain numeric-keyed lists). Row term IDs accept `location_id`, `location`, `id`, etc. Anchor discovery also reads locations from **imported `radius_template`** posts (Elementor / `_location_id`), not only legacy `magicpage` CPT + `magic_page_location_radius_settings`.

= 1.6.20 =
* Magic Page landing cleanup footprint: default meta keys prioritize **`_location_id`** and **`_group_id`** (plugin-deployed pages). Anchor extraction from templates also tries `_location_id` first.

= 1.6.19 =
* **Migration wizard:** New step — remove Magic Page mass landing pages by footprint (posts that have **both** a non-empty location meta and a non-empty group meta, default `page` post type). Deletes run only if the candidate count is **not** equal to the total page count in scanned types (fail-safe). Preview AJAX: `magic_pages_preview`; delete: `magic_pages_cleanup`.
* **Wizard without Magic Page active:** If you dismissed the wizard or started migration, the modal can still load after deactivating Magic Page so you can finish cleanup manually. Filters: `radius_migration_wizard_show_without_magic_page`, Magic Page landing footprint: `radius_magic_page_landing_post_types`, `radius_magic_page_landing_location_meta_keys`, `radius_magic_page_landing_group_meta_keys`, `radius_magic_page_landing_abort_if_candidates_match_all_pages`.

= 1.6.18 =
* **Magic Page migration wizard:** Before cloning variants, normalize imported towing template `{spintax_towing…}` → `{{towing…}}` across Elementor + Radius meta; set default towing title to “24/7 Towing Company in {{place_name}}, {{region}}.” (filter `radius_migration_towing_template_title`).
* **Variant clones:** replace `{{towing-…}}` / `{{towing_…}}` / `{{towing}}` with the correct service prefix (`roadside`, `heavy`, `equipment`) so cloned templates are not left with towing tokens.
* **Cleanup:** optional (`radius_migration_delete_previous_templates_before_run`, default true) delete prior migration-sourced `radius_template` posts (`_radius_imported_from` / `_radius_migration_clone_of`) before re-import so you end up with exactly four service templates.

= 1.6.17 =
* **Revert** the global `{spintax_*}` → `{{*}}` catch-all in `convert_legacy_magic_page_tokens_to_curly` — it turned leftover `{spintax_towing-…}` into `{{towing-…}}` on roadside/heavy/equipment templates (wrong service line).
* **Variant clone swaps:** rewrite `{spintax_towing-` / `{spintax_towing_` to the correct service prefix before other replacements so hyphenated Magic Page tokens follow the variant.
* **Spintax import:** match `{spintax_…}` by **sanitized block key** as well as human label so hyphenated keys (e.g. `roadside-h2-3`) convert without a blind catch-all.

= 1.6.16 =
* Legacy token conversion: after option-row matching, convert **any** remaining `{spintax_…}` (including hyphenated keys like `{spintax_roadside-h2-3}`) to `{{…}}` via `sanitize_key`, so Elementor text is not limited to labels present in the global spintax option.

= 1.6.15 =
* Migration template clones (roadside / heavy / equipment): copy Elementor document meta via the same Elementor-aware path used for Magic Page imports (`copy_elementor_document_meta_to_template`), instead of only bulk-copying post meta. Bulk copy skips `_elementor*` keys so `_elementor_data` is not dropped or corrupted when cloning from the towing blueprint.
* Variant keyword swaps: map bare placeholder `{{towing}}` → `{{roadside}}`, `{{heavy}}`, or `{{equipment}}` (in addition to `spintax_towing` + `towing_*` prefix rules). Filter `radius_migration_clone_elementor_meta_keys_exclude` can adjust which `_elementor*` keys are omitted from the non-Elementor bulk copy.

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

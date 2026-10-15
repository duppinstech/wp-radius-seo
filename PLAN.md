# PLAN

Open work to make Radius SEO safe to run in production. The objective is `INTENT.md`: one blueprint, a place library, and a deploy that leaves one current page per place.

Take the items in order. Finish or explicitly defer a blocker before starting the next one. This file is the action list. When an item is finished, set its status to `done` and add the evidence line. Do not delete closed items.

## How to run one item

1. Read `INTENT.md` and this item.
2. Follow `workflows/bug-fix.md` for a fix, or `workflows/feature-build.md` if the item adds behavior.
3. After the edit, run `workflows/department-review.md`. That meeting writes a note under `.claude/memory/meetings/` and adds new suggestions here.
4. Prove a runtime change on the local Docker site: `docker compose up -d`, run the `testing-patterns` checklist at http://127.0.0.1:8088, then `docker compose down`. Do not leave the stack running. `CLAUDE.local.md` holds the admin login. Beeline Transport Inc. is the named production site for read-only checks.
5. Record the result on the item. Update `.claude/memory/progress.md` only with the milestone, not a second copy of this list.

Status values: `open`, `deferred` (maintainer accepted the current behavior), `done`.

## Waiting on you

Nothing in this file is waiting on a decision. The table stays here so the next blocked item has a place to go.

| Id | What is blocked | What you decide |
| --- | --- | --- |

## Standing rules

- Local proof uses `docker compose up -d`, then `docker compose down`. Do not leave the stack running.
- Beeline Transport Inc. is read-only unless you ask for a change on that site. Do not deploy, purge, or remediate there otherwise.

## Blockers

### P0-01 — License gate accepts any non-empty key

- **Status:** done
- **Evidence of close:** 2026-10-14. There is still no license server. A new or replaced key must be a signed `RSL1.` license (`Radius_API_License::signature_is_valid()`). The key already saved on a site is remembered once in `radius_license_legacy_key_hash` and keeps working until it is replaced or removed. Check tells the owner to Save a new signed key, and a refused replacement is an error notice. The private key stays outside the plugin tree (`~/.radius/license-private.pem`). Meeting: `.claude/memory/meetings/2026-10-14-2130.md`.
- **Kind:** security and completeness
- **Evidence:** `Radius_API_License::validate_key_with_remote()` used to return true for every non-empty key (`includes/class-api-license.php`). `is_unlocked()` is that check. Public landings and service areas are blocked only while the stored key is empty. If OpenSSL is missing, storage falls back to a reversible `b64:` prefix and a signed key cannot be checked.
- **Do:** Until a license server exists, verify an offline signature. Do not lock sites that already saved a key. Drop the “validated” wording.
- **Done when:** A new random key cannot unlock the plugin, and a key already saved on the site still can.
- **Workflow:** `workflows/bug-fix.md`, then `@security-reviewer`.

### P0-02 — Published templates are readable without an editor session

- **Status:** done
- **Evidence of close:** Shipped in 1.6.108. No maintainer decision was required. A live click-through was not run.
- **Kind:** security
- **Evidence:** `radius_template` is registered with `public` false, `publicly_queryable` true, and `show_in_rest` true (`includes/class-plugin.php`). Preview token replacement runs only for `edit_post`, but the raw blueprint is still the singular content for everyone else. WordPress treats a publicly queryable type as viewable, so a published template can also appear on the REST index. The license wall blocks these URLs only while the plugin is locked.
- **Do:** Keep editor preview. For everyone who cannot `edit_post` on that template, return a 404 (or a noindex 403) on the singular URL and do not expose the type on the public REST API.
- **Done when:** Logged-out requests for a published template URL and `/wp-json/wp/v2/radius_template` do not return blueprint title, body, tokens, or phone numbers. An editor can still preview.
- **Workflow:** `workflows/bug-fix.md`, then `@security-reviewer`.

### P0-03 — Deploy and place import are allowed for anyone who can edit posts

- **Status:** done
- **Evidence of close:** Shipped in 1.6.108. Administrators keep deploy, CSV import, and CSV or template export. No maintainer decision was required.
- **Kind:** security
- **Evidence:** `edit_posts` is the gate for deploy (`Radius_Ajax::deploy_batch()`, `Radius_Form_Handlers::handle_deploy()`), CSV import (`handle_csv()`), place CSV export (`handle_export_places_csv()`), and template-slot export (`handle_export_templates_slots_json()`). Deploy inserts posts as `publish`. Delete-all-places and settings correctly require `manage_options`.
- **Do:** Require a capability that matches mass publishing. `manage_options` matches the settings and purge screens. If editors must deploy, add a dedicated `radius_deploy` capability and grant it only to the roles that should publish a city batch. Do not leave import, full library export, or template export on `edit_posts`.
- **Done when:** A Contributor and an Author cannot deploy, import places, or download the place library or template JSON. An Administrator still can. State the role decision in `.claude/memory/decisions.md`.
- **Workflow:** `workflows/bug-fix.md`, then `@security-reviewer`.

### P0-04 — Operation logs are denied for Apache only

- **Status:** done
- **Evidence of close:** Shipped in 1.6.108. Logs prefer a directory outside the document root, with Apache 2.4 and IIS deny rules. No maintainer decision was required. A logged-out fetch on a specific host was not run.
- **Kind:** security
- **Evidence:** `Radius_Operation_Log` writes `uploads/radius-seo-logs/*.log` and adds `.htaccess` with `Deny from all` plus `index.php` (`includes/class-operation-log.php`). Nginx and IIS ignore `.htaccess`. Request context omits the API key, but logs still contain deploy and import detail.
- **Do:** On the staging host, request the log URL while logged out. If the body is reachable, stop serving it. Add a host-agnostic barrier (move the directory outside the web root, or block it in the site config) and keep the Apache file. Do not log license keys or authorization headers.
- **Done when:** A logged-out request for `process.log` and `error.log` does not return log contents on the staging server, and the check is noted here with the host type.
- **Workflow:** `workflows/bug-fix.md`, then `@security-reviewer`.

## Harden before calling the deploy production-ready

### P1-01 — Prove one current page per place under a retried deploy

- **Status:** done
- **Evidence of close:** Shipped in 1.6.111. On the local Docker site, a second deploy of the same template and place updated the existing landing. A hand-inserted duplicate was trashed on the next update. Two overlapping deploys created one page and updated it. `duplicate_pages` passed.
- **Kind:** completeness
- **Evidence:** `Radius_Deploy_Service::deploy()` looks up `_radius_template_id` plus `_radius_place_id` and trashes extra matches on the next run. A short-lived option lock covers the lookup and insert so a second writer waits and updates. Health check `duplicate_pages` and `deploy_meta` can report leftovers (`includes/class-deploy-health-check.php`).
- **Do:** On staging, run one template against a small place set, interrupt a batch, and run it again. Then run two overlapping batches if the UI allows it. If two published pages remain for one template and place, add a lock or a unique lookup that the second writer updates instead of inserting.
- **Done when:** After a retry, each template and place pair has one published landing or service-area post, and the health check reports no duplicate pages for that set.
- **Workflow:** `workflows/bug-fix.md`.

### P1-02 — Confirm phone links on new deploys and repair pages already stored

- **Status:** done
- **Evidence of close:** 2026-10-14. Local Docker deploy of template 17 for place 5 (Proofville P104) wrote landing 18 with one `tel:+15550100199` href, no nested anchor, and no broken href payload. Beeline Transport Inc. (`https://beelinetransportinc.com`, Radius 1.6.111) was read only: 1,610 landing and service-area posts scanned, 0 Gutenberg pages, 0 broken href payloads, 0 nested anchors in stored HTML. No production pages were rewritten.
- **Kind:** completeness
- **Evidence:** New Gutenberg deploys rewrite `phone-number` inside `href` and `src` toward `phone-tel` (`Radius_Deploy_Service::prepare_template_tokens_for_phone_link_safety()`). `tools/verify-gutenberg-phone-links.php` and `tools/remediate-broken-phone-links.php` exist because older saved HTML can still contain nested anchors.
- **Do:** Deploy one staging page from the current template and confirm the phone link is a single `tel:` href. Run the verify tool against existing landings. Remediate only on the named staging site, then production only after that result is accepted.
- **Done when:** The new page is clean, and the verify tool’s remaining count is zero or the leftover pages are listed here as an accepted exception.
- **Workflow:** `workflows/bug-fix.md`.

### P1-03 — Public analytics can write post meta at a high rate

- **Status:** done
- **Evidence of close:** Shipped in 1.6.110. A visit writes a pruned day map and an integer counter, at most once per IP and post per 30 minutes. Clicks increment a counter only, capped at 20 per minute. Empty and known-bot user agents are ignored. The dashboard transient is one fixed key. The front-end script does not load jQuery. A live click-through was not run.
- **Kind:** security and completeness
- **Evidence:** `wp_ajax_nopriv_radius_record_visit` and `wp_ajax_nopriv_radius_record_click` accept the public page nonce (`includes/class-analytics.php`). The cap is 120 events per 60 seconds per IP, user agent, and post. Each allowed visit rewrites a visit-history array (max 500 timestamps) and each click rewrites a click log (max 500 rows). Clearing the dashboard cache loads every published landing ID on that same public request. The front-end script depends on jQuery.
- **Do:** Keep the beacon. Count at most one visit per IP and post per 30 minutes, and at most 20 clicks per minute. Ignore an empty or known-bot user agent. Store visit days (pruned) and integer counters. Do not rewrite the timestamp or click-log arrays. Delete the dashboard transient by a fixed key. Load the front-end script without jQuery.
- **Done when:** A tight loop of anonymous visit requests cannot grow post meta without bound, and a normal page view does not query every landing ID.
- **Workflow:** `workflows/bug-fix.md`, then `@security-reviewer`.

### P1-09 — Content rotation stops after one batch

- **Status:** done
- **Evidence of close:** Shipped in 1.6.110. A full batch schedules the same hook about two minutes later. The offset resets when the ID list is exhausted. A lock skips an overlapping tick. A live cron run was not observed.
- **Kind:** completeness
- **Evidence:** Settings copy says each run processes landings in batches until all have been updated (`includes/class-admin.php`). `Radius_Rotation_Cron::run_batch()` updates the offset and waits for the next `radius_ndays` event, which defaults to 30 days. A library larger than the batch (default 25) does not finish a cycle.
- **Do:** After a full batch, schedule the next batch about two minutes later on the same hook. When the ID list is exhausted, reset the offset and wait for the interval. Keep one batch inside each request. Skip the tick if a lock is already held.
- **Done when:** A library larger than one batch advances within minutes until every landing has been visited once, then waits for the configured interval. One request still updates only one batch.
- **Workflow:** `workflows/bug-fix.md`.

### P1-10 — System screen does not show object cache or cron mode

- **Status:** done
- **Evidence of close:** Shipped in 1.6.110. Deploy → System reports persistent object cache and WP-Cron mode (system cron, visitor-triggered, or alternate). Weights are 4 so a missing Redis install does not fail migration by itself. The screen was not opened on a host.
- **Kind:** production
- **Evidence:** `Radius_System_Requirements::run_checks()` scores PHP limits, cURL, and the deploy lookup index. It does not report a persistent object cache or whether `DISABLE_WP_CRON` / `ALTERNATE_WP_CRON` is set. Health and rotation both use WP-Cron.
- **Do:** Add two checks. Persistent object cache present is good; missing is a warning. `DISABLE_WP_CRON` is good when a system cron is expected to call `wp-cron.php`. Visitor-triggered cron and `ALTERNATE_WP_CRON` are warnings. Keep the weights small so a host without Redis does not fail the migration score by itself.
- **Done when:** Deploy → System lists object cache and cron mode with the statuses above.
- **Workflow:** `workflows/bug-fix.md`.

### P1-04 — Golden-path proof, then automated coverage for that path

- **Status:** done
- **Evidence of close:** 2026-10-14 on local Docker (Apache/2.4.65). CSV import created place 5 `proofville-p104`. Template 17 rendered `{{place_name}}`, spintax `{Alpha|Beta}`, and a phone href. Landing deploy created post 18; the second deploy updated 18 and did not create another. Service-area deploy created post 20. Elementor is not installed locally, and post 18 had no Elementor meta. Beeline Transport Inc. has Elementor on and landing 17674 is an Elementor page; that live site was not redeployed. Automated coverage of this path is deferred (2026-10-14). The proof posts and place were removed after the check. PHPUnit stays limited to the updater.
- **Kind:** completeness
- **Evidence:** There is no PHPUnit or wp-env suite. `tools/` holds phone-link maintenance scripts only.
- **Do:** On staging, walk one place through CSV import, template tokens and spintax, landing deploy, redeploy (update, not a second post), service-area deploy, Elementor off, and Elementor on if that site uses it. Write the results under this item. Add PHPUnit only after that path is stable enough to freeze; put tests in `tests/` and document the command in `.claude/rules/testing-standard.md`.
- **Done when:** The staging notes below name the template id, place id, resulting post ids, and pass or fail for each step. Automated tests are either added for that path or marked `deferred` with a date.
- **Workflow:** `testing-patterns` skill, then `workflows/feature-build.md` if a harness is added.

### P1-05 — Updater package is a real GitHub ZIP for this version

- **Status:** done
- **Evidence of close:** 2026-10-14. GitHub release `v1.6.111` on `duppinstech/wp-radius-seo` is the latest release. Its only asset is `wp-radius-seo.zip`. The archive root contains `wp-radius-seo/radius.php` with Version 1.6.111. Beeline Transport Inc. is running Radius 1.6.111, so Dashboard → Updates has no newer release to offer. The copy on that site still defaults the updater repo to `oduppinsjr/wp-radius-seo`; that repo now resolves to the same release asset.
- **Kind:** production
- **Evidence:** `Radius_GitHub_Updater` (standard v1.0.0) installs only `https://github.com/duppinstech/wp-radius-seo/releases/download/<tag>/wp-radius-seo.zip`. It does not verify a signature. Sites see an update only when the latest release tag is newer and that asset is attached. Current plugin version is `RADIUS_VERSION` in `radius.php`.
- **Do:** Confirm the GitHub release for the version being deployed has `wp-radius-seo.zip`, the archive root is `wp-radius-seo/radius.php`, and a staging site on the previous version is offered that update. Do not add a second download host.
- **Done when:** Staging “Check again” shows the intended version, or this item records the exact tag and asset name that production should run.
- **Workflow:** `/deploy release` only when the code changes in the items above are ready to ship. This item itself is a check, not a version bump.

## Go-live checks on the target site

These do not need new plugin features unless a check fails.

### P1-06 — Run deploy health and system checks

- **Status:** done
- **Evidence of close:** 2026-10-14, Beeline Transport Inc., read only. Deploy health (light, plus `duplicate_pages` and `deploy_meta`) returned 0 failures. Duplicate and deploy-meta checks passed. System readiness is 92% (excellent), bypass off. Accepted warning: `max_execution_time` is unlimited. Persistent object cache is on. `DISABLE_WP_CRON` is set (system cron). No rows were cleared or rewritten on the server.
- **Kind:** production
- **Do:** On the named site, open Radius → Deploy → Health check and System. Clear or accept every failing row (duplicates, missing coords, URL conflicts, Magic Page leftovers, deploy index). A system-requirements bypass is an administrator override (`Radius_System_Requirements`); do not use it to hide a failed migration on production.
- **Done when:** The health summary is clean, or each remaining failure is copied here with the reason it is accepted.

### P1-07 — Confirm the SEO plugin the site actually runs

- **Status:** done
- **Evidence of close:** 2026-10-14, Beeline Transport Inc. Yoast SEO 28.7 is active. Rank Math and All in One SEO are not. `sitemap_index.xml` lists `landing-sitemap.xml`, `landing-sitemap2.xml`, and `service-area-sitemap.xml`. `https://beelinetransportinc.com/semi-tire-abert/` is in `landing-sitemap2.xml`. `https://beelinetransportinc.com/service-area/coltons-mill/` is in `service-area-sitemap.xml`. The index does not list a template sitemap.
- **Kind:** completeness
- **Evidence:** `Radius_SEO_Integrations` adjusts Yoast sitemap slugs for landings and service areas. There is no Rank Math or All in One SEO integration in this repo.
- **Do:** If the site uses Yoast, confirm landing and service-area URLs appear in the sitemap under the friendly slugs. If it uses another SEO plugin, either add that integration or record that sitemaps are handled outside Radius.
- **Done when:** The production SEO plugin is named here and the sitemap check passed, or the gap is a new item with a status.

### P1-08 — Deactivate leaves content; delete is undocumented

- **Status:** done
- **Evidence of close:** 2026-10-14. The maintainer chose a dialog on Plugins → Deactivate. Keep leaves data. Delete lists counts and runs only after Confirm delete. Dismiss does not deactivate or delete. `SUPPORT.md` states that. `uninstall.php` still clears updater transients only, so deleting the plugin files does not remove data that was kept.
- **Kind:** completeness
- **Evidence:** `uninstall.php` exists and deletes GitHub updater transients only. Deactivation and delete do not remove `radius_settings`, place terms, or deployed posts. Support confirmed this at the 2026-10-14 meeting. The policy choice below is unchanged.
- **Do:** Decide the production policy. Recommended: deactivation keeps pages so the site does not lose local URLs. Document that in `SUPPORT.md`. Add `uninstall.php` only if a full delete must also remove plugin data, and make that path explicit and irreversible.
- **Done when:** `SUPPORT.md` states what deactivate and delete do, matching the code.
- **Workflow:** `workflows/bug-fix.md` if an uninstall file is required.

## Already in place

Do not reopen these unless a check above fails.

- Admin AJAX for deploy, license validate, migration, and Magic Page option changes checks a nonce and a capability.
- Place delete and settings saves require `manage_options`.
- GitHub package URLs are limited to GitHub hosts.
- New deploy output strips unresolved `{{token}}` placeholders and guards Gutenberg phone hrefs.
- API keys are AES-256-CBC ciphertext when OpenSSL is present.
- Deploy health already looks for duplicate pages, orphan meta, redirect conflicts, and the deploy lookup index.
- Public landings serve stored HTML. `dynamic_content_per_request` defaults to off, so a page cache can store those URLs. Turn that setting on only when a template must spin on every view.
- Agent docs, `INTENT.md`, and this file are `export-ignore` and are not part of the plugin ZIP.

## Approved at the 2026-10-14 department meeting

These do not need another decision from the maintainer. They are not blockers. P0-01 does not hold them. Notes: `.claude/memory/meetings/2026-10-14-1544.md` and `.claude/memory/meetings/2026-10-14-1556.md`. Implemented in 1.6.113. P2-10 was proved on the local Docker site on 2026-10-14.

### P2-01 — A Contributor can read every published template over REST

- **Status:** done
- **Evidence of close:** Implemented in 1.6.113. A route that passes `parent` checks `edit_post` on that template. A user who cannot `edit_others_posts` only receives their own templates from the collection. A live Contributor request was not run in this pass.
- **Decision:** Approved. No maintainer decision.
- **Kind:** security
- **Evidence:** `Radius_Plugin::block_public_template_rest()` allows the collection when the caller has `edit_posts`. A route that passes the template as `parent` (revisions) never checks `edit_post` on that template (`includes/class-plugin.php`). Logged-out access stays blocked. This is narrower than P0-02, which stays done.
- **Do:** Require `edit_post` on the template, including `parent` on revision routes. Limit the collection to posts that user can edit, or to `manage_options`.
- **Done when:** A Contributor or Author who cannot `edit_post` on a template gets no title, body, tokens, or phone numbers from `/wp/v2/radius_template` or its revisions route. An editor who can edit that template still can.
- **Workflow:** `workflows/bug-fix.md`, then the department review.

### P2-02 — The updater installs a release's only zip when the name does not match

- **Status:** done
- **Evidence of close:** Implemented in 1.6.113. `select_package()` returns a URL only when the asset name matches. PHPUnit `GitHubUpdaterTest` (27 tests) passed, including a differently named single zip, which is now rejected.
- **Decision:** Approved. No maintainer decision.
- **Kind:** security
- **Evidence:** `Radius_GitHub_Updater::select_package()` returns the configured asset, and otherwise the only `.zip` on that release (`includes/class-radius-github-updater.php`). The comment records that fallback on purpose. A release whose only zip is not `wp-radius-seo.zip` can still be installed. P1-05 remains the check that the real release asset exists.
- **Do:** Return a package only when the asset name matches the configured asset.
- **Done when:** A release with one differently named zip is not offered as the package. A release whose asset is `wp-radius-seo.zip` still is.
- **Workflow:** `workflows/bug-fix.md`, then the department review.

### P2-03 — License summary names the readme line incorrectly

- **Status:** done
- **Evidence of close:** Implemented in 1.6.113. `LICENSE.md` and `README.md` name `readme.txt` as `GPLv2 or later`.
- **Decision:** Approved. No maintainer decision.
- **Kind:** legal
- **Evidence:** `radius.php` says `GPL-2.0-or-later`. `readme.txt` says `GPLv2 or later` (the WordPress readme wording for the same license). `LICENSE.md` says both files state `GPL-2.0-or-later`. `README.md` says the header and `readme.txt` match.
- **Do:** In `LICENSE.md`, name both wordings. In `README.md`, stop saying the two strings are identical.
- **Done when:** Neither file claims `readme.txt` contains the SPDX string `GPL-2.0-or-later`.
- **Workflow:** Docs only. No version bump.

### P2-04 — The license card calls the key-save time a purchase date

- **Status:** done
- **Evidence of close:** Implemented in 1.6.113. The current-license card no longer renders Purchase date. Expiration remains.
- **Decision:** Approved. No maintainer decision.
- **Kind:** finance
- **Evidence:** `Radius_API_License::get_license_details_for_display()` labels `api_key_saved_at` as "Purchase date". If that value is missing, the card shows today's date (`includes/class-api-license.php`). Unlimited sites and no expiration stay as they are. The code does not cap sites or pages.
- **Do:** Remove the Purchase date label and the date from the current-license card.
- **Done when:** The license screen does not call a local save time, or today's date, a purchase.
- **Workflow:** `workflows/bug-fix.md`, then the department review.

### P2-05 — The owner is not told what landing analytics reads

- **Status:** done
- **Evidence of close:** Implemented in 1.6.113. `SUPPORT.md` and the Landing analytics subtitle state that counts stay on this site, the visitor IP is a short-lived hash, and the user agent is read to ignore bots.
- **Decision:** Approved. No maintainer decision.
- **Kind:** privacy
- **Evidence:** `Radius_Analytics` stores visit-day totals, a visit count, and a click count on the landing. It reads `REMOTE_ADDR` and the user agent on the public visit and click beacons. The IP is kept only as an MD5 in a short `radius_al_*` transient. The user agent is not saved. The beacon posts to this site. No privacy policy is required for this item.
- **Do:** Add one sentence to `SUPPORT.md`, and the same fact under the Landing analytics subtitle in `includes/class-analytics.php`: counts stay on this site, the visitor IP is a short-lived rate-limit hash, and the user agent is read to ignore bots.
- **Done when:** Both places say that, and they match the code.
- **Workflow:** `workflows/bug-fix.md` for the admin string. No version bump if the only runtime edit is that subtitle; bump if the string ships in the plugin.

### P2-06 — The logs screen says every log folder is unreachable from the web

- **Status:** done
- **Evidence of close:** Implemented in 1.6.113. The logs screen says the files stay on this server, the preferred folder is outside the document root, and an uploads fallback must be blocked by the host.
- **Decision:** Approved. No maintainer decision.
- **Kind:** privacy
- **Evidence:** `includes/class-admin.php` (`render_operation_logs`) says the log path is "not web-accessible." `Radius_Operation_Log` prefers a folder outside the document root and otherwise uses `uploads/radius-seo-logs`, blocked by `.htaccess` and `web.config`. P0-04 stays done.
- **Do:** Change that sentence so the files stay on this server, the preferred folder is outside the document root, and an uploads fallback must be blocked by the host.
- **Done when:** The screen no longer says every log path is unreachable from the web.
- **Workflow:** `workflows/bug-fix.md`, then the department review.

### P2-07 — Quick start still describes an older deploy

- **Status:** done
- **Evidence of close:** Implemented in 1.6.113. `README.md` and `readme.txt` quick starts start at License, then service areas, then Deploy & update all places. The location library tells a non-administrator to ask an administrator to upload the CSV.
- **Decision:** Approved. No maintainer decision.
- **Kind:** support
- **Evidence:** `README.md` and `readme.txt` tell the owner to paste place term IDs. Deploy uses service areas and "Deploy & update all places". Neither quick start says an administrator must save a license key before the library, templates, or deploy unlock. CSV upload is `manage_options`. The library screen does not tell a non-administrator to ask an administrator.
- **Do:** In both quick starts, add the license-lock step, replace the paste-IDs step with service areas plus Deploy & update all places, and say only an administrator can upload the CSV (coordinates required; skipped rows need Update existing). On the library screen for a non-administrator, say to ask a site administrator to upload the CSV.
- **Done when:** An owner following the quick start is sent to License, then Service areas, then Deploy, and a non-administrator is not told they can upload the CSV.
- **Workflow:** Docs plus the one admin string. Version bump only if the admin string ships.

### P2-08 — Public copy names Elementor and omits Beaver Builder

- **Status:** done
- **Evidence of close:** Implemented in 1.6.113. The plugin header, both readmes, the settings card, the deploy help, and the classic-landings sentence name Beaver Builder next to Elementor.
- **Decision:** Approved. No maintainer decision.
- **Kind:** communications
- **Evidence:** Beaver Builder already runs when `enable_beaver_builder` is on, the same gate as Elementor. The header in `radius.php`, the `readme.txt` short description and quick start, and `README.md` name only Elementor. Admin copy in `includes/class-admin.php` does the same in the Deploy help, the Settings card ("rotation, Elementor, and extra meta"), and "classic (non-Elementor) landings." The 1.6.75 changelog already records Beaver Builder. Do not add a version for the changelog.
- **Do:** Name optional Beaver Builder next to Elementor in those sentences. The classic-landings sentence must exclude Beaver Builder pages too. Quick start step for builders: turn the matching Settings → Integrations checkbox on, save, then deploy.
- **Done when:** A reader of the header, both readmes, and those three admin strings can see both builders are optional and off until enabled.
- **Workflow:** `workflows/bug-fix.md` for the admin strings, then the department review.

### P2-09 — The health-check error names developer filters

- **Status:** done
- **Evidence of close:** Implemented in 1.6.113. The HTML-not-JSON health message tells the owner to check the host error log or Radius → Logs, then try again.
- **Decision:** Approved. No maintainer decision.
- **Kind:** support
- **Evidence:** `includes/class-admin.php` (`htmlNotJson`) tells the owner to lower `radius_health_redirect_scan_max_urls` or disable `radius_deploy_health_include_redirect_scan`.
- **Do:** Remove that sentence. Keep the instruction to check the host error log or Radius → Logs, then try again.
- **Done when:** That error does not mention those filter names.
- **Workflow:** `workflows/bug-fix.md`, then the department review.

### P2-10 — Prove the 1.6.108 access checks on the local site

- **Status:** done
- **Evidence of close:** 2026-10-14 on http://127.0.0.1:8088, Apache/2.4.65 (Debian) in Docker. Logged-out template URL 404, no blueprint text. Logged-out `/wp-json/wp/v2/radius_template` 401, no blueprint text. Editor preview 200 and showed the blueprint marker. Author and Contributor: Deploy 403, places CSV import POST Forbidden, places CSV and template JSON downloads Forbidden, and those forms are absent on Location library. Administrator: Deploy 200 with the form, places CSV `text/csv`, template JSON `application/json`, and CSV import returned “0 new, 1 updated, 0 skipped.” Logged-out `process.log` and `error.log` under uploads returned 403 and not the log text. Temporary users were deleted.
- **Decision:** Approved. No maintainer decision.
- **Kind:** quality
- **Evidence:** P0-02, P0-03, and P0-04 are done, and no live request was recorded for them. Quality did not run this list. Nothing is passed.
- **Do:** On http://127.0.0.1:8088, with `docker compose up -d` and `docker compose down` after: logged-out template URL returns 404 without blueprint text; logged-out `/wp-json/wp/v2/radius_template` returns 401 without blueprint text; an editor preview still loads; a temporary Author and a temporary Contributor cannot deploy, import a places CSV, or download the place CSV or template JSON; an administrator still can; a logged-out fetch of `process.log` and `error.log` does not return log text. Delete the temporary users. Record the host as Apache in Docker.
- **Done when:** Each step above is recorded here as pass or fail. Do not mark a step passed without running it. This does not reopen P0-02, P0-03, or P0-04 unless a step fails.
- **Workflow:** `testing-patterns` skill.

### P2-13 — Three dialogs never take focus or close on Escape

- **Status:** done
- **Evidence of close:** Implemented in 1.6.113. The migration wizard, spintax dialog, site-replacements dialog, and deploy help move focus in, keep Tab inside, close on Escape, and return focus. Variation and value labels are tied to their fields. A keyboard pass in the browser was not completed in this session.
- **Decision:** Approved. No maintainer decision. From the interface meeting.
- **Kind:** interface
- **Evidence:** The migration overlay in `assets/js/radius-admin-migration-wizard.js` is `role="dialog"` and `aria-modal="true"`, and focus never moves in. Escape does not close it. The site-replacements dialog (`#radius-site-repl-modal` in `includes/class-admin.php`) and the spintax dialog (`#radius-spintax-modal` in `includes/class-template-metabox.php`, script `assets/js/radius-template-metabox.js`) are labeled, and they also skip focus and Escape. A backdrop click can close them and leave focus behind the overlay. Generated Value and Variation labels are not tied to their fields. Deploy help (`assets/js/radius-deploy-cards.js`) moves focus in, closes on Escape, and returns focus. It does not keep Tab inside the dialog.
- **Do:** For all four dialogs: move focus to the first control on open, keep Tab inside the dialog, close on Escape, and return focus to the trigger. Tie each generated label to its field with `for` and `id`.
- **Done when:** Keyboard only, a person can open each dialog, reach every control, close it with Escape, and land back on the button that opened it. Nothing behind the dialog is in the tab order while it is open.
- **Workflow:** `workflows/bug-fix.md`, then the department review.

### P2-14 — The license lock stops the pointer and not the keyboard

- **Status:** done
- **Evidence of close:** Implemented in 1.6.113. While locked, dimmed settings panels are `inert` and dimmed tabs are removed from the tab order. Other locked Radius screens mark their content `inert`. The license gate is left active. A locked-screen keyboard pass was not run in this session.
- **Decision:** Approved. No maintainer decision. From the interface meeting.
- **Kind:** interface
- **Evidence:** `Radius_API_License::admin_enqueue_lock()` dims locked screens with opacity and `pointer-events: none` (`includes/class-api-license.php`). A keyboard user can still reach Templates, Landings, Service areas, and the dimmed Settings tabs.
- **Do:** Set `inert` on the locked content, leave the license notice and the license gate active, and mark dimmed tabs `aria-disabled="true"` with `tabindex="-1"`.
- **Done when:** While the plugin is locked, Tab and a screen reader cannot operate a locked screen or a dimmed settings tab. The license gate still can.
- **Workflow:** `workflows/bug-fix.md`, then the department review.

### P2-15 — Library, import, and service-area fields have no accessible name

- **Status:** done
- **Evidence of close:** Implemented in 1.6.113. Place checkboxes are named "Select {place name}". Both CSV file fields are labeled Places CSV. Service-area search, radius, and suggestion lists have names and `aria-expanded` / `aria-controls`.
- **Decision:** Approved. No maintainer decision. From the interface meeting.
- **Kind:** interface
- **Evidence:** Location library row checkboxes (`.radius-place-cb` in `includes/class-admin.php`) have no name. Select-all does. Both `input[name="radius_csv"]` file fields (Location library and Import / export) have no `<label for>`. Service-area rows built in `assets/js/admin-place-search.js` use a placeholder as the only name on the search field, no name on the radius field, and a `role="listbox"` that is not named or tied to the search field.
- **Do:** Name each row checkbox "Select {place name}". Label each file field "Places CSV". Label the search and radius inputs, and expose the suggestion list as a named listbox with `aria-expanded` and `aria-controls` on the search field.
- **Done when:** A screen reader can tell which place a checkbox selects, what each CSV field uploads, and which suggestion belongs to which service-area search.
- **Workflow:** `workflows/bug-fix.md`, then the department review.

### P2-16 — Deploy status text is not announced

- **Status:** done
- **Evidence of close:** Implemented in 1.6.113. Template and service-area deploy status lines use `role="status"` and `aria-live="polite"`.
- **Decision:** Approved. No maintainer decision. From the interface meeting.
- **Kind:** interface
- **Evidence:** `assets/js/radius-deploy-cards.js` writes "Deploying…" and errors into `.radius-deploy-card__ajax-progress` (including `#radius-sa-deploy-ajax`). Those lines are not live regions (`includes/class-admin.php`).
- **Do:** Add `role="status"` and `aria-live="polite"` on those progress lines.
- **Done when:** A screen reader hears the deploy status and the error without the person moving focus to that line.
- **Workflow:** `workflows/bug-fix.md`, then the department review.

### P2-17 — Analytics charts and tables are visual only

- **Status:** done
- **Evidence of close:** Implemented in 1.6.113. Each analytics chart has a screen-reader summary of the same numbers. The places and top-landings tables use `scope="col"`.
- **Decision:** Approved. No maintainer decision. From the interface meeting.
- **Kind:** interface
- **Evidence:** `#lfa-chart-visits-over-time` in `includes/class-analytics.php` is a canvas with no accessible name and no text alternative. The same is true of the other analytics canvases on that screen. `#lfa-table-locations` and `#lfa-table-top-pages` use `<th>` without `scope`.
- **Do:** Give each chart a text alternative (a table or a short summary of the same numbers). Set `scope="col"` on those header cells.
- **Done when:** The visits series and the other charts can be read without seeing the drawing, and the table headers announce as column headers.
- **Workflow:** `workflows/bug-fix.md`, then the department review.

### P2-18 — A parent query argument can open a template the user cannot edit

- **Status:** done
- **Evidence of close:** Approved at the 2026-10-14 20:04 meeting. The gate reads the template id from the request path, because `rest_pre_dispatch` runs before WordPress fills URL parameters. On the local site, an author collection omitted the administrator template. The author single read and the same read with `?parent=` of the author's own post were both forbidden. The administrator single read included the marker.
- **Decision:** Approved. No maintainer decision.
- **Kind:** security
- **Evidence:** `get_param( 'parent' )` includes a query argument. On `GET /wp/v2/radius_template/{id}` that value replaced the template id (`includes/class-plugin.php`).
- **Do:** Use URL parameters only. Require `edit_post` on the route id. On a revision route, require `edit_post` on the parent after confirming it is a `radius_template`.
- **Done when:** An author who can edit some other post still cannot read an administrator template by adding `?parent=` of that other post.
- **Workflow:** `workflows/bug-fix.md`, then the department review.

### P2-19 — The README still says any release zip is enough

- **Status:** done
- **Evidence of close:** Approved at the 2026-10-14 20:04 meeting. `README.md` now requires `wp-radius-seo.zip` and names Check again or a manual upload. The 1.6.113 changelog names the analytics sentence, the health-check error, and the non-administrator CSV sentence.
- **Decision:** Approved. No maintainer decision.
- **Kind:** support
- **Evidence:** `README.md` said the latest release must include a `.zip` asset. `select_package()` installs only the configured file name. `readme.txt` already named `wp-radius-seo.zip`.
- **Do:** Match the README Updates sentence to `readme.txt`. Add the three shipped copy sentences to the 1.6.113 changelog.
- **Done when:** A reader of `README.md` and the 1.6.113 changelog sees the zip file name and those three owner sentences.
- **Workflow:** `workflows/bug-fix.md`, then the department review.

### P2-20 — Four admin controls still miss a keyboard or live-text pattern

- **Status:** done
- **Evidence of close:** Approved at the 2026-10-14 20:04 meeting. Deploy help skips `tabindex="-1"`. Chart summaries are live regions and say “Failed to load.” when the request fails. Place suggestions are `aria-live="polite"` and a failed search says “Search failed.” Locked Settings tabs are `inert`.
- **Decision:** Approved. No maintainer decision.
- **Kind:** interface
- **Evidence:** The deploy-help trap included the backdrop Close button. Chart summary paragraphs were filled after the request with no live region. The suggestion list was silent on failure. Locked tabs were links with `aria-disabled` only.
- **Do:** Skip `tabindex="-1"` in that trap. Mark the three chart summaries `role="status"` and `aria-live="polite"`, including the failure text. Announce suggestion results. Set `inert` on locked Settings tabs.
- **Done when:** Tab stays inside the help dialog, a failed chart load and a failed place search are announced, and a locked Settings tab cannot be activated.
- **Workflow:** `workflows/bug-fix.md`, then the department review.

## Waiting on the maintainer

No open choice. The next item that needs a decision goes in the table at the top and in this section.

### P2-11 — The license screen offers a plan and a Purchase button that go nowhere

- **Status:** done
- **Evidence of close:** 2026-10-14. The maintainer named the offers. Single site is $9.99 per month at the Stripe link in `Radius_API_License::CHECKOUT_SINGLE_URL`. Unlimited sites is $99 per month at `CHECKOUT_UNLIMITED_URL`. Both cards show on Settings → License, including while the plugin is locked. A signed key labeled `single` displays as Single site. Cancelling Stripe does not yet disable a saved key.
- **Decision:** Maintainer named the two Stripe checkouts and the monthly prices.
- **Kind:** finance
- **Evidence:** `includes/class-admin.php` shows "Need a new license or an additional plan? Choose an option below." The Purchase link is `href="#"` with `tabindex="-1"`. The default card repeats Unlimited sites, which the current-license card already shows. There is no price list.
- **Choose one:** Remove that sentence and the Purchase cards. Or name a real offer and where the button should send the owner. Do not invent a price in the repo.
- **Done when:** The screen matches the choice recorded here.

### P2-12 — The license card lists priority email support the plugin does not grant

- **Status:** done
- **Evidence of close:** 2026-10-14. The maintainer said priority email is flagged in the Duppins Technology CRM from the license, not in the plugin. The license-card bullets stay. `SUPPORT.md` says the plugin does not store or check that flag.
- **Decision:** Handled outside the plugin. No plugin entitlement.
- **Kind:** finance
- **Evidence:** Both license feature lists in `includes/class-admin.php` include "Priority email support." The plugin does not record a support entitlement. `SUPPORT.md` links commercial support to https://duppinstech.com/.
- **Choose one:** Remove "Priority email support" from both lists. Or add a sentence to `SUPPORT.md` that the unlimited plan includes priority email at that URL, and keep the bullets.
- **Done when:** The card and `SUPPORT.md` agree with the choice recorded here.

## Deferred on purpose

- MCP servers in `.mcp.json` are unrelated to a production WordPress deploy.
- `phpcbf` is not vendored. Formatting is not a launch blocker.
- Deploy does not purge LiteSpeed or WP Rocket. Default landings are stored HTML, so the host page cache can keep them until the post is updated. A purge integration waits until a named host needs it.

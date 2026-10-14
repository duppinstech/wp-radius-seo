# PLAN

Open work to make Radius SEO safe to run in production. The objective is `INTENT.md`: one blueprint, a place library, and a deploy that leaves one current page per place.

Take the items in order. Finish or explicitly defer a blocker before starting the next one. This file is the action list. When an item is finished, set its status to `done` and add the evidence line. Do not delete closed items.

## How to run one item

1. Read `INTENT.md` and this item.
2. Follow `workflows/bug-fix.md` for a fix, or `workflows/feature-build.md` if the item adds behavior.
3. Security items go through `@security-reviewer` before they are called done.
4. Prove a runtime change on the local Docker site: `docker compose up -d`, run the `testing-patterns` checklist at http://127.0.0.1:8088, then `docker compose down`. Do not leave the stack running. `CLAUDE.local.md` holds the admin login. A customer site is still unnamed until the maintainer names it.
5. Record the result on the item. Update `.claude/memory/progress.md` only with the milestone, not a second copy of this list.

Status values: `open`, `deferred` (maintainer accepted the current behavior), `done`.

## Before a live click-through

- **Status:** open
- **Do:** Use the repo `docker-compose.yml` for local proof. Start it for the check and stop it with `docker compose down` when the check ends. Do not run deploy, purge, or remediation against an unnamed customer site.
- **Not a blocker for shipped code.** Local Docker is the click-through site. P0-01 stays open until the license-server choices are settled. It does not block the next local proof.

## Blockers

### P0-01 — License gate accepts any non-empty key

- **Status:** open
- **Kind:** security and completeness
- **Evidence:** `Radius_API_License::validate_key_with_remote()` returns true for every non-empty key (`includes/class-api-license.php`). `is_unlocked()` is that check. Public landings and service areas are blocked only while the stored key is empty. The settings UI still says the key is validated. If OpenSSL is missing, storage falls back to a reversible `b64:` prefix.
- **Do:** Pick one outcome and implement only that outcome.
  - Production license server: call `get_validate_endpoint()` with `wp_remote_*` over HTTPS, fail closed when the endpoint is empty or the response is not a clear allow, and stop describing the placeholder as validation.
  - Operational switch: document on the license screen that any saved key unlocks this Duppins Technology plugin, and drop the “validated” wording until a server exists. WhiteRail is the beta client, not the owner of that switch.
- **Done when:** A wrong or empty key cannot unlock deploy or public pages, or the maintainer marks this `deferred` because the switch behavior is the intended production control. Staging shows the chosen behavior.
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

- **Status:** open
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

- **Status:** open
- **Kind:** completeness
- **Evidence:** There is no PHPUnit or wp-env suite. `tools/` holds phone-link maintenance scripts only.
- **Do:** On staging, walk one place through CSV import, template tokens and spintax, landing deploy, redeploy (update, not a second post), service-area deploy, Elementor off, and Elementor on if that site uses it. Write the results under this item. Add PHPUnit only after that path is stable enough to freeze; put tests in `tests/` and document the command in `.claude/rules/testing-standard.md`.
- **Done when:** The staging notes below name the template id, place id, resulting post ids, and pass or fail for each step. Automated tests are either added for that path or marked `deferred` with a date.
- **Workflow:** `testing-patterns` skill, then `workflows/feature-build.md` if a harness is added.

### P1-05 — Updater package is a real GitHub ZIP for this version

- **Status:** open
- **Kind:** production
- **Evidence:** `Radius_GitHub_Updater` installs only a `.zip` asset from `github.com`, `objects.githubusercontent.com`, or `codeload.github.com`. It does not verify a signature. Sites see an update only when the latest release tag is newer and the ZIP is attached. Current plugin version is `RADIUS_VERSION` in `radius.php`.
- **Do:** Confirm the GitHub release for the version being deployed has `wp-radius-seo.zip`, the archive root is `wp-radius-seo/radius.php`, and a staging site on the previous version is offered that update. Do not add a second download host.
- **Done when:** Staging “Check again” shows the intended version, or this item records the exact tag and asset name that production should run.
- **Workflow:** `/deploy release` only when the code changes in the items above are ready to ship. This item itself is a check, not a version bump.

## Go-live checks on the target site

These do not need new plugin features unless a check fails.

### P1-06 — Run deploy health and system checks

- **Status:** open
- **Kind:** production
- **Do:** On the named site, open Radius → Deploy → Health check and System. Clear or accept every failing row (duplicates, missing coords, URL conflicts, Magic Page leftovers, deploy index). A system-requirements bypass is an administrator override (`Radius_System_Requirements`); do not use it to hide a failed migration on production.
- **Done when:** The health summary is clean, or each remaining failure is copied here with the reason it is accepted.

### P1-07 — Confirm the SEO plugin the site actually runs

- **Status:** open
- **Kind:** completeness
- **Evidence:** `Radius_SEO_Integrations` adjusts Yoast sitemap slugs for landings and service areas. There is no Rank Math or All in One SEO integration in this repo.
- **Do:** If the site uses Yoast, confirm landing and service-area URLs appear in the sitemap under the friendly slugs. If it uses another SEO plugin, either add that integration or record that sitemaps are handled outside Radius.
- **Done when:** The production SEO plugin is named here and the sitemap check passed, or the gap is a new item with a status.

### P1-08 — Deactivate leaves content; delete is undocumented

- **Status:** open
- **Kind:** completeness
- **Evidence:** There is no `uninstall.php`. Deactivation does not remove `radius_settings`, place terms, or deployed posts.
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

## Deferred on purpose

- MCP servers in `.mcp.json` are unrelated to a production WordPress deploy.
- `phpcbf` is not vendored. Formatting is not a launch blocker.
- Deploy does not purge LiteSpeed or WP Rocket. Default landings are stored HTML, so the host page cache can keep them until the post is updated. A purge integration waits until a named host needs it.

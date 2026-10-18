# Progress

Update this when a milestone lands. Keep the outstanding list to work that is still open. Do not invent product features here.

## Done

- 2026-10-16: 1.7.5 removes the View details Example section and the customer links. Local check at http://127.0.0.1:8088/wp-admin/plugin-install.php?tab=plugin-information&plugin=wp-radius-seo showed Description going from What you get to Plans, with no Example heading and no customer link. Docker was stopped. Note: `.claude/memory/meetings/2026-10-16-1725.md`.
- 2026-10-16: 1.7.4 adds an Installation tab on View details and the Radius SEO banner. Local check at http://127.0.0.1:8088/wp-admin/plugin-install.php?tab=plugin-information&plugin=wp-radius-seo showed Description, Installation, Screenshots, FAQ, and Changelog. The zip link was the GitHub latest release. Screenshots were the seven admin images, with no customer page capture. The displayed version was still 1.7.3 because that line comes from the current GitHub release. Docker was stopped. Note: `.claude/memory/meetings/2026-10-16-1715.md`.
- 2026-10-16: 1.7.3 adds Settings and Help beside Deactivate on Plugins, Sponsor after the author (https://github.com/sponsors/oduppinsjr), and Support on that description line. Local check at http://127.0.0.1:8088/wp-admin/plugins.php confirmed those hrefs. Settings opened Radius settings. Docker was stopped. Note: `.claude/memory/meetings/2026-10-16-1620.md`.
- 2026-10-16: 1.7.2 Help opens https://duppinstech.com/radius-seo/guide/ at the section for the current screen. The PDF is rebuilt from marketing/guide. Local check at http://127.0.0.1:8088: Dashboard link was #dashboard, Deploy Help opened with Close focused and the link was #deploy, Support was #support with no Contact support, Settings Service areas was #settings-service-areas, Posts had no Radius Help button. The public guide page is still not published. The GitHub wiki repository does not exist until the first wiki page is created. Docker was stopped. Note: `.claude/memory/meetings/2026-10-16-1056.md`.
- 2026-10-16: 1.7.1 replaces the top help banner with a bottom-right Help button that links to https://duppinstech.com/radius-seo/guide/ . The user guide PDF is a screen-by-screen admin walkthrough with numbered screenshots. That guide URL is not published yet. Local check before Docker stopped: Dashboard had no banner, Help opened the panel, Close took focus. Escape and a non-administrator were not checked. Email support was not sent. Docker was stopped. Note: `.claude/memory/meetings/2026-10-16-0315.md`.
- 2026-10-15: 1.7.0 help, user manual, and Radius → Support. Local check at http://127.0.0.1:8088: Dashboard help opened, Deploy and Templates and General settings showed their own help, the support form listed the fields that are emailed, and Contact support was absent on the Support screen. The PDF returned HTTP 200 application/pdf. Email support was not clicked, so delivery and the one-minute wait were not proven. A non-administrator was not checked. Docker was stopped. Note: `.claude/memory/meetings/2026-10-15-2325.md`.
- 2026-10-14: Purge now forgets the cached options group and deletes logs in the uploads fallback as well as the preferred log folder. 1.6.115. The dialog was not opened again for this cache fix.
- 2026-10-14: Local check at http://127.0.0.1:8088. License settings showed Single site at $9.99 per month and Unlimited sites at $99 per month, with the Stripe links the maintainer sent. Plugins → Deactivate opened the dialog, listed 7 landing pages, 1 template, 3 places, and saved settings, then the confirm step. Escape left the plugin active. Confirm delete was not run. Docker was stopped.
- 2026-10-14: Deactivate dialog. Keep leaves pages and settings. Delete shows counts and runs only after Confirm delete. Dismiss does nothing. P1-08 closed. P2-11 is the only open plan item.
- 2026-10-14: License interim reviewed. Signed keys stay. The private key is outside the plugin tree. A removed or replaced key clears the remembered hash. Check and Save copy no longer says the key was validated. Note: `.claude/memory/meetings/2026-10-14-2130.md`. P2-11 and P1-08 are the only open plan items.
- 2026-10-14: License interim. No license server. New keys must be signed (`php bin/sign-license.php`). A key already saved on a site stays valid. Local proof: existing key unlocked, a random key did not, a signed key did. Priority email stays a CRM flag (`SUPPORT.md`).
- 2026-10-14: Department review of commit `73b66c9`. Approved P2-18, P2-19, and P2-20. Note: `.claude/memory/meetings/2026-10-14-2004.md`. P2-11, P2-12, and P0-01 stay waiting.
- 2026-10-14: Named-site proofs. A production site (Radius 1.6.111) was read only. P1-05, P1-06, and P1-07 closed from that look. P1-02 and P1-04 closed with a local Docker deploy plus that site’s phone and Elementor observations. P2-10 closed on http://127.0.0.1:8088 (Apache in Docker). Nothing on the live site was edited. Docker was stopped afterward. Do not name that customer in public copy.
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

- No plan item is waiting on a decision. Stripe checkout links are the two prices the maintainer named. A cancelled subscription does not yet disable a saved key.
- Local WordPress is filled in `CLAUDE.local.md` (Docker at http://127.0.0.1:8088).
- Whether places, deploy, and admin screens get a WordPress integration suite. The updater already has `composer test`.

### Outstanding checks

- 2026-10-16: Help guide fragments and the 1.7.2 user-manual PDF were not run. Checklist is the test-writer return for this change: open Help on each Radius screen and confirm the user-guide link is `https://duppinstech.com/radius-seo/guide/#FRAGMENT` for dashboard, analytics, templates, template-editor, landings, landing-editor, service-area-hubs, service-area-hub, location-library, deploy, import-export, license, settings-general, settings-service-areas, settings-replacers, settings-content, settings-database, settings-integrations, logs, and support. The PDF at `assets/manual/radius-seo-user-manual.pdf` should contain `Version 1.7.2` and the location-library CSV steps (Import CSV, Update existing places, Upload CSV, and the column list `id, name, slug, country, region, state, zip, lat, lng`). Local site is http://127.0.0.1:8088. Docker was not started for this check.

- 2026-10-14: P2-20 keyboard pass was not run (deploy help trap, locked Settings tabs, place-search announcement, chart live text). P2-18 REST path check passed on the local Docker site: author collection omitted the marker, author single and `?parent=` were 403, administrator single was 200. `composer test` was 27 tests, OK. Docker was stopped after the check.

- 2026-10-14 local Docker site `http://127.0.0.1:8088`: landing `radius-local-a` loaded `radius-frontend-analytics.js` and did not load jQuery. One visit was stored (`radius_landing_visit_count` = 1, day `2026-10-14` = 1). A second beacon and a browser reload in the same half hour left the count at 1. A `curl` user agent did not add a visit. Analytics showed 1 visit. Rotation with batch size 1 and three landings advanced the offset by 1 and scheduled the next run 120 seconds out, then the test hook was cleared and the batch size restored to 25. Deploy → System shows Persistent object cache Off and WP-Cron Visitor-triggered. The readiness score on this container is 54% because `max_execution_time` is short and there is no Redis.

### Engineering gaps

- Updater unit tests exist (`composer test`). Places, deploy, and admin screens still use the manual checklist in `.claude/rules/testing-standard.md`.
- `phpcbf` and the WordPress PHPCS standard are not vendored. `post-edit-format.sh` no-ops until `phpcbf` is on `PATH` or at `vendor/bin/phpcbf`.
- Hooks are bash scripts. They need Git Bash, WSL, or another `bash` on Windows. `validate-code.sh` and the strict path of `block-sensitive-writes.sh` need `php` on `PATH`.
- Product backlog: the PHP tree has no `TODO` or `FIXME` markers. Outstanding product work is whatever the maintainer adds below this line.

### Product backlog

Production gaps are the open items in `PLAN.md`. Run that list in order. Do not copy the items here.

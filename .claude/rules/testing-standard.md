---
paths:
  - "**/*.php"
  - "tools/**/*.php"
  - "readme.txt"
---

# Testing standard

The GitHub updater has PHPUnit 9 tests: `composer install`, then `composer test` (or `vendor/bin/phpunit`). `composer lint` runs `php -l` via `bin/lint.php`. Those tests use the doubles in `tests/bootstrap.php` and do not boot WordPress. `tools/verify-gutenberg-phone-links.php` and `tools/remediate-broken-phone-links.php` are maintenance scripts, not a test runner. Do not claim a test passed unless you ran it.

## When behavior changes

Prove a runtime change on the local Docker site before committing it. From the repo root run `docker compose up -d`, exercise the changed path at http://127.0.0.1:8088, then `docker compose down`. That stops Apache and MariaDB and keeps the database volume for the next run. Do not leave the stack running, and do not put WordPress core in this repo. Details and the admin login live in `CLAUDE.local.md`.

Verify the path you changed:

- **Places:** CSV import, search, dedupe, and term meta (`radius_lat`, `radius_lng`, `radius_country`, and the other `Radius_Data_Registry` term keys).
- **Templates:** Tokens in title and body (`{{token}}`, `[token]`), spintax `{a|b|c}`, and site replacers. Confirm an explicit empty `site_replacements` array still falls back to `_radius_xfields`.
- **Deploy:** One place and a small batch. Check the created post type, slug, template id, and place id meta. Re-deploy and confirm update rather than a duplicate.
- **Builders:** With the flag off, deploy must not require Elementor or Beaver Builder. With the flag on, layout copy still runs.
- **Admin AJAX:** A logged-out request and a subscriber request fail. An admin request succeeds.
- **Updater:** Do not point a test at a live customer site. Confirm version constants and `readme.txt` Stable tag match before a release.

## When the user asks for automated tests

Updater coverage lives in `tests/GitHubUpdaterTest.php`. Other automated tests, if added, go under `tests/` and get a command in this file. Keep fixtures free of customer data. Until a WordPress integration suite exists, places, deploy, and admin screens stay a manual checklist plus an honest gap.

## Hooks

`validate-code.sh` runs `php -l` after PHP edits. A syntax error blocks the edit. Formatting via `phpcbf` is optional and never blocks.

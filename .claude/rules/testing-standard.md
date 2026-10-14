---
paths:
  - "**/*.php"
  - "tools/**/*.php"
  - "readme.txt"
---

# Testing standard

This repository has no PHPUnit, Pest, or wp-env suite. `tools/verify-gutenberg-phone-links.php` and `tools/remediate-broken-phone-links.php` are maintenance scripts, not a test runner. Do not claim a test passed unless you ran it.

## When behavior changes

Verify the path you changed, on a local site if `CLAUDE.local.md` has one:

- **Places:** CSV import, search, dedupe, and term meta (`radius_lat`, `radius_lng`, `radius_country`, and the other `Radius_Data_Registry` term keys).
- **Templates:** Tokens in title and body (`{{token}}`, `[token]`), spintax `{a|b|c}`, and site replacers. Confirm an explicit empty `site_replacements` array still falls back to `_radius_xfields`.
- **Deploy:** One place and a small batch. Check the created post type, slug, template id, and place id meta. Re-deploy and confirm update rather than a duplicate.
- **Builders:** With the flag off, deploy must not require Elementor or Beaver Builder. With the flag on, layout copy still runs.
- **Admin AJAX:** A logged-out request and a subscriber request fail. An admin request succeeds.
- **Updater:** Do not point a test at a live customer site. Confirm version constants and `readme.txt` Stable tag match before a release.

## When the user asks for automated tests

Add them under `tests/` with PHPUnit and the WordPress test suite, document the command in this file, and keep fixtures free of customer data. Until that harness exists, the deliverable is a manual checklist and an honest gap.

## Hooks

`validate-code.sh` runs `php -l` after PHP edits. A syntax error blocks the edit. Formatting via `phpcbf` is optional and never blocks.

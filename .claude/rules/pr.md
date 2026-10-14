---
paths:
  - ".github/**/*.md"
  - "CONTRIBUTING.md"
  - "readme.txt"
  - "CHANGELOG.md"
---

# Pull requests

Use `.github/pull_request_template.md`. One concern per PR. Branch from `main` as `feature/…` or `fix/…`.

## Summary

What changed and why, in a few sentences. Name the user-visible outcome.

## Related issues

`Fixes #123` or `Relates to #456`. Leave the line out if there is no issue.

## Checklist

- Targeted change, with no unrelated refactor.
- Output escaped where it is printed. Capabilities and nonces on admin actions.
- Version bumped in `radius.php` (header and `RADIUS_VERSION`) and `readme.txt` (Stable tag and changelog) when this ships. Say so if maintainers will bump instead.
- Tested in wp-admin and on the front end, or an explicit note of what was not run.

## Notes for reviewers

Migrations, rewrite flushes, builder flags, and follow-ups. Security reports stay private (`SECURITY.md`) and do not go in the PR body.

## Release

A merged PR that changes plugin behavior still needs a GitHub Release and `wp-radius-seo.zip` before sites on the GitHub updater will see it. See `CLAUDE.md` and `CONTRIBUTING.md`.

---
name: pr-description
description: Write a Radius SEO pull request body that matches .github/pull_request_template.md. Use when opening a PR or when the user asks for a PR summary, test plan, or changelog note.
---

# PR description

One job: the PR text. Do not open the pull request unless the user asks.

Read `.claude/rules/pr.md` and `.github/pull_request_template.md`. Fill every section from the real diff:

- Summary: user-visible outcome and why
- Related issues: `Fixes #n` only when an issue exists
- Checklist: mark items that are true; leave the rest unchecked and say why
- Notes: migrations, rewrite flushes, Elementor or Beaver Builder flags, version bump

If this ships to sites, the description must mention the `radius.php` version, the `readme.txt` Stable tag, and that a GitHub Release with `wp-radius-seo.zip` is still required for the updater.

Keep vulnerability detail out of the body. Point at `SECURITY.md` instead.

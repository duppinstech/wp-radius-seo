---
name: testing-patterns
description: Produce a Radius SEO verification checklist for places, templates, tokens, deploy, builders, or admin AJAX. Use when behavior changed and someone needs to prove it. Use when the user asks for tests. There is no PHPUnit suite yet.
---

# Testing patterns

One job: say how to prove the change, then run it if a local site is listed in `CLAUDE.local.md`.

Read `.claude/rules/testing-standard.md` and follow the section that matches the change (places, templates, deploy, builders, admin AJAX, updater).

Return:

- The behavior under test, in one sentence
- Preconditions (feature flags, a template id, a place term)
- Steps
- Expected result
- What you actually ran, or why you could not

Do not add PHPUnit, wp-env, or a CI workflow unless the user asks for an automated harness. Do not treat `tools/verify-gutenberg-phone-links.php` as a general test suite.

If you could not run the steps, leave them in `.claude/memory/progress.md` under Outstanding.

---
name: code-review
description: Review Radius SEO PHP or admin-asset changes for WordPress security, canonical radius_* IDs, token and deploy regressions, and scope creep. Use when the user asks for a review, a PR review, or a second pass on a diff.
---

# Code review

One job: review. Do not expand the change.

1. Read `git diff` and the files it touches. Also read `.claude/rules/code-style.md` and, if the diff touches requests or deploy, `.claude/rules/api-conventions.md`.
2. Check the hard rules in `CLAUDE.md`: no new `lf_` storage, registry constants for new keys, nonces and capabilities, prepared SQL, builder flags, rewrite flush.
3. Hand security-sensitive diffs to `@security-reviewer` and merge its findings into the list below.
4. Reply in this order:
   - Blocking issues (bug, data loss, missing authz, broken deploy or tokens)
   - Should-fix (escaping, naming, version bump missing on a user-facing change)
   - Residual risk and checks nobody ran

Cite the file. Give a concrete fix. Skip praise and style nits that the repo does not already follow.

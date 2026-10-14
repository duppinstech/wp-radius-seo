---
description: Review the current Radius SEO diff for security, canonical IDs, and deploy or token regressions
argument-hint: "[optional focus, e.g. deploy ajax]"
---

Review the working tree against Radius SEO rules.

Focus: $ARGUMENTS

1. Read `.claude/memory/decisions.md` and `AGENTS.md`.
2. Inspect `git status` and `git diff`. If the focus argument is set, limit the review to that area.
3. Load `.claude/rules/code-style.md`, `.claude/rules/api-conventions.md`, and `.claude/rules/testing-standard.md`.
4. Delegate findings that need a separate context to `@security-reviewer`. Keep the final summary in this session.
5. Report issues first, ordered by severity, with file and a concrete fix. Then list residual risk and the manual checks still unrun.

Do not edit files unless the user asks to fix what you found. Do not force-push or rewrite history.

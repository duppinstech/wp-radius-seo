# Bug fix

Claude does not auto-load this file. `CLAUDE.md` points here. Follow the steps in order for a defect.

## Outcome

Write one sentence: the wrong behavior, where it shows up, and what correct looks like.

## Steps

1. Read `INTENT.md`, the matching open item in `PLAN.md` when this fix comes from that list, and `.claude/memory/decisions.md` so the fix serves the repo objective and does not undo a recorded choice (empty `site_replacements`, CPT priority 20, builder flags, updater ZIP layout).
2. Reproduce or locate the failing path. Delegate `@research` if the owning class is not obvious. Do not patch a neighboring symptom.
3. Change the smallest span that makes the correct behavior true. No drive-by refactor.
4. If the fix touches auth, SQL, import, or rendered output, delegate `@security-reviewer`.
5. Load the `testing-patterns` skill. The checklist must include the case that failed and one adjacent case (re-deploy, second place, flag off).
6. Note the fix and any unrun check in `.claude/memory/progress.md`.
7. Ship user-facing plugin code with a patch bump (`1.6.x`) unless the fix changes behavior enough to justify a minor version. Use `/deploy release`.

## Done when

The failing case matches the correct behavior, and the reply names any check that was not run.

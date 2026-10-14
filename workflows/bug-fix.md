# Bug fix

Claude does not auto-load this file. `CLAUDE.md` points here. Follow the steps in order for a defect.

## Outcome

Write one sentence: the wrong behavior, where it shows up, and what correct looks like.

## Steps

1. Read `INTENT.md`, the matching open item in `PLAN.md` when this fix comes from that list, and `.claude/memory/decisions.md` so the fix serves the repo objective and does not undo a recorded choice (empty `site_replacements`, CPT priority 20, builder flags, updater ZIP layout).
2. Reproduce or locate the failing path. Delegate `@research` if the owning class is not obvious. Do not patch a neighboring symptom.
3. Change the smallest span that makes the correct behavior true. No drive-by refactor.
4. Run `workflows/department-review.md`. Security, legal, finance, privacy, interface, support, and communications each reply, even when the fix is small. Apply consistency fixes. Ask the maintainer before changing rights, warranty, price, or adding a privacy policy.
5. Load the `testing-patterns` skill and use the `@test-writer` checklist. The checklist must include the case that failed and one adjacent case (re-deploy, second place, flag off).
6. Before a commit that can change runtime behavior, prove it on the local Docker site, then stop the containers. From the repo root: `docker compose up -d`, run the checklist against http://127.0.0.1:8088, then `docker compose down`. `down` keeps the database volume, so the next start is not a reinstall. Use `docker compose down -v` only when the local site should be thrown away. Do not leave the stack running after the check. If the volume is empty, install WordPress and activate `wp-radius-seo/radius.php` as in `CLAUDE.local.md.example`.
7. Note the fix and any unrun check in `.claude/memory/progress.md`.
8. Ship user-facing plugin code with a patch bump (`1.6.x`) unless the fix changes behavior enough to justify a minor version. Use `/deploy release`. Agent docs and `docker-compose.yml` do not get a version bump.

## Done when

The failing case matches the correct behavior, and the reply names any check that was not run.

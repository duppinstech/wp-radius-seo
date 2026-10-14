# Feature build

Claude does not auto-load this file. `CLAUDE.md` points here. Follow the steps in order for a new user-visible capability.

## Outcome

Write one sentence: who can do what after this change that they could not do before.

## Steps

1. Read `INTENT.md`, `PLAN.md` (if an open item is in progress), `.claude/memory/project-context.md`, `.claude/memory/decisions.md`, and `.claude/memory/progress.md`. Stop if the change does not serve `INTENT.md`.
2. If the area is deploy, tokens, places, rewrites, builders, or the updater, delegate the map to `@research` and keep only the brief.
3. Name the files to change. Add any new id to `Radius_Data_Registry` before use. Keep storage on `radius_*` / `_radius_*`.
4. Implement the smallest change in the class that already owns the behavior. Match `.claude/rules/code-style.md`. Request handling follows `.claude/rules/api-conventions.md`.
5. If the change reads request data, writes posts, imports files, or runs SQL, delegate `@security-reviewer` before calling it done.
6. Load the `testing-patterns` skill and run the checklist when `CLAUDE.local.md` has a site. Record unrun checks in `.claude/memory/progress.md`.
7. Update `.claude/memory/progress.md`. Add a line to `.claude/memory/decisions.md` only if you chose something later sessions must not undo.
8. User-facing plugin code ships as a version bump, push to `main`, and a GitHub Release with `wp-radius-seo.zip`. Use `/deploy release` for that checklist. Skip the version bump when the change is agent docs only.

## Done when

The outcome sentence is true on a local site, or the reply says exactly which step was not run and why.

---
description: Scaffold a new Radius SEO PHP class, meta key, or admin asset in the existing layout
argument-hint: "[class or feature name]"
---

Scaffold only the piece named here: $ARGUMENTS

1. Read `.claude/memory/project-context.md` and `.claude/rules/code-style.md`.
2. If the piece needs a new option, CPT, taxonomy, or meta key, add the constant to `Radius_Data_Registry` first. Use `radius_*` or `_radius_*`. Do not introduce `lf_`.
3. Add `includes/class-<slug>.php` with the ABSPATH guard, `@package Radius`, and class `Radius_<Slug>`.
4. Require it from `radius.php` in the existing order: registry and prefix migration stay first; admin and `Radius_Plugin` stay last. Hook runtime setup from `radius_boot()` or the class `init()` the same way neighboring classes do.
5. If it is an admin script or style, name it `radius-<slug>.js` or `radius-<slug>.css` and enqueue it from the admin class that owns the screen.
6. If it handles a request, follow `.claude/rules/api-conventions.md` (nonce, capability, sanitize, JSON response).
7. Stop after the skeleton compiles (`php -l`). Do not add a feature beyond the named piece. Update `.claude/memory/progress.md` with what was added and what behavior is still empty.

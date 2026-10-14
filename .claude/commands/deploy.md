---
description: Ship a Radius SEO release or walk through landing-page deploy safety
argument-hint: "[release|landings] [version notes]"
---

Argument: $ARGUMENTS

Decide which deploy the argument means.

## `release` or empty

This is a maintainer release of the plugin, not a landing-page batch.

1. Confirm the diff is the intended user-facing change and the working tree is clean except the version bump. A runtime change must already have been proven with `docker compose up -d` and the stack stopped with `docker compose down`.
2. Choose the bump: patch `1.6.x` for a small fix, minor `1.7.0` for a larger behavior change, major `2.0.0` only for a breaking change.
3. Set the `Version` header and `RADIUS_VERSION` in `radius.php` to the same value. Set `readme.txt` Stable tag and add a changelog entry.
4. Commit on `main`, push `origin main`, and create an annotated tag `vX.Y.Z` that matches the plugin version.
5. Push the tag. `.github/workflows/release.yml` builds `wp-radius-seo.zip` with `bin/build-zip.sh` (one top-level folder `wp-radius-seo/`) and attaches it to the GitHub Release. Do not attach a `git archive` ZIP; that archive has shipped development folders.

Do this only when the user wants the change shipped. Never force-push. Never rewrite a published tag. Agent docs are `export-ignore` and must not be required by the plugin.

## `landings`

This is `Radius_Deploy_Service::deploy()`: a template plus place term ids become `radius_landing` or `radius_service_area` posts.

1. Read `includes/class-deploy-service.php` and `.claude/memory/project-context.md`.
2. State which template id, place ids, and post type will be written.
3. Stop unless the user named the site and asked to run the batch. Do not run deploy against an unnamed or production site.
4. After an approved local run, check one created post for template id, place id, slug, and rendered tokens.

If the argument is ambiguous, ask which of the two they mean before changing version numbers or writing posts.

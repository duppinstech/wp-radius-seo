# Code review

Claude does not auto-load this file. `CLAUDE.md` points here. Follow the steps in order when reviewing a diff or preparing a pull request.

## Outcome

A reviewer can merge, or can name the blocking issue, without re-reading the whole plugin.

## Steps

1. Read `INTENT.md`, `git diff` (or the PR diff), and `.claude/memory/decisions.md`. A change that misses the objective in `INTENT.md` is a blocking issue.
2. Run `/review` or load the `code-review` skill. Delegate `@security-reviewer` when the diff touches AJAX, forms, deploy, import, SQL, or the updater.
3. Check version consistency if the change is user-facing: `Version`, `RADIUS_VERSION`, and `readme.txt` Stable tag.
4. Load the `pr-description` skill when the user wants a pull request body.
5. Load the `testing-patterns` skill and list checks that were run and checks that were not.

## Done when

The reply lists blocking issues first, then residual risk. It does not approve a diff that breaks canonical ids, auth, or the release ZIP layout.

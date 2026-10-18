# Agent roster

Claude reads this before delegating. Each agent has one job, its own context, and its own tools. The main session is the developer and the only writer, unless a workflow says otherwise.

Invoke a specialist with `@agent-name` or by delegating when the description matches. Do not run two writers against the same files.

## Before the edit

### research

- **File:** `.claude/agents/research.md`
- **Department:** Engineering
- **Job:** Read-only investigation of Radius behavior so the main session stays small.
- **Use when:** The task depends on deploy, tokens, spintax, places, rewrites, Elementor, Beaver Builder, or the GitHub updater and the relevant method is not already in context.
- **Returns:** The classes and methods involved, the data keys, and the risk if they change.
- **Does not:** Edit files or propose a broad rewrite.

## After the developer finishes

Run `workflows/department-review.md` before calling the change done. Every department answers, including **No update** when its surface is untouched. They are read-only and may run in parallel.

### security-reviewer

- **File:** `.claude/agents/security-reviewer.md`
- **Department:** Security
- **Job:** Read-only review of escaping, nonces, capabilities, SQL, file import, and license or updater changes.
- **Use when:** After every process. A diff with no request, auth, SQL, output, import, or updater surface still gets a two-line pass.
- **Returns:** Findings ordered by severity, with file and a concrete fix. No drive-by refactors.
- **Does not:** Edit files, ship a release, or expand scope into a feature.

### legal-reviewer

- **File:** `.claude/agents/legal-reviewer.md`
- **Department:** Legal
- **Job:** Compare the diff to license, copyright, ownership, warranty, and user-facing disclaimers.
- **Use when:** After every process.
- **Returns:** Add, remove, or leave a specific sentence, with the file. **No update** when rights and disclaimers are untouched.
- **Does not:** Edit files, rewrite the GPL, invent a contract, or give legal advice. A change to rights or warranty waits for the maintainer.

### finance-reviewer

- **File:** `.claude/agents/finance-reviewer.md`
- **Department:** Finance
- **Job:** Check whether the license offer still matches what the code unlocks.
- **Use when:** After every process.
- **Returns:** The claim that is now wrong, and whether the maintainer should change the offer. No dollar amount.
- **Does not:** Edit files or set a price. The displayed defaults are unlimited sites and no expiration until `radius_license_details` replaces them.

### privacy-reviewer

- **File:** `.claude/agents/privacy-reviewer.md`
- **Department:** Privacy
- **Job:** Check for new data stored on the site or sent off it (analytics, license validation, GitHub updater, import, logs).
- **Use when:** After every process.
- **Returns:** What is stored or sent, the file, and the notice to add or remove.
- **Does not:** Edit files or write a privacy policy. A new collection the notices do not cover waits for the maintainer.

### ui-reviewer

- **File:** `.claude/agents/ui-reviewer.md`
- **Department:** Interface
- **Job:** Check that a new or moved wp-admin control uses a WordPress pattern, sits on the Radius screen that already owns that task, and can be used without a mouse and without seeing color.
- **Use when:** After every process. A diff that does not add or move a control still gets a two-line pass.
- **Returns:** The control, the pattern or accessible name it needs, and whether moving a whole screen needs the maintainer.
- **Does not:** Edit files, rewrite support docs, or redesign a screen. A label, fake link, focus style, or color-only status does not wait on the maintainer.

### support-reviewer

- **File:** `.claude/agents/support-reviewer.md`
- **Department:** Support
- **Job:** Check that an owner can run the change and recover from the new failure.
- **Use when:** After every process.
- **Returns:** The support or admin sentence to add or remove, and the owner action it should name.
- **Does not:** Edit files or invent a support plan.

### communications-reviewer

- **File:** `.claude/agents/communications-reviewer.md`
- **Department:** Communications
- **Job:** Check the plugin header, readme, changelog, and admin copy against the behavior that shipped.
- **Use when:** After every process.
- **Returns:** The public sentence to add or remove. Agent docs do not need a changelog line.
- **Does not:** Edit files, write marketing, or bump the version.

### test-writer

- **File:** `.claude/agents/test-writer.md`
- **Department:** Quality
- **Job:** Turn a behavior change into a verification checklist. Add automated tests only when the user asks for a harness.
- **Use when:** After the edit, when behavior changed, or when the user asks how to prove a fix.
- **Returns:** Steps a person can run in wp-admin and on the front end, plus any coverage gaps. Updater tests are `composer test`.
- **Does not:** Invent a passing test by weakening the assertion, or mark work verified without running it.

## Handoff

1. Main session states the user outcome in one sentence and names the files it expects to touch.
2. `research` goes first when the area is unfamiliar. Paste only the summary back, not the search trail.
3. Main session makes the smallest change that satisfies the outcome.
4. Run `workflows/department-review.md`. Security, legal, finance, privacy, interface, support, and communications each reply. Quality (`test-writer`) replies when behavior changed.
5. Main session writes `.claude/memory/meetings/YYYY-MM-DD-HHMM.md` with the summary: suggested, decided, and the `PLAN.md` ids. It approves valid items that do not need the maintainer and adds those items to `PLAN.md`. It then implements approved security fixes, bug fixes, copy corrections, and small interface fixes. It asks the maintainer before a new capability, a new screen, a rearranged menu, a price or plan change, a warranty, a privacy policy, or any item marked waiting.
6. Main session runs the quality steps when a local site is available (`CLAUDE.local.md`). If it is not available, say so in the reply and in `.claude/memory/progress.md`.
7. One writer at a time. Specialists that only read may run before the edit, or together after it, not during the edit.

## Not agents

Hooks (`.claude/hooks/`) are shell, not models. Skills load instructions into the main session. Slash commands (`/review`, `/deploy`, `/scaffold`) are explicit workflows. Use those instead of a new agent when the work is a checklist rather than a separate context.

## Commands

- Install: `composer install`
- Lint: `composer lint`
- Test: `composer test`
- Build: `bash bin/build-zip.sh`
- Local site: `docker compose up -d` (http://127.0.0.1:8088). Stop with `docker compose down`.

## Conventions

- Classes use the `Radius_` prefix. Stored keys use `radius_*` or `_radius_*`. Text domain is `radius`.
- Admin assets are `radius-*.js` and `radius-*.css`.
- Follow the WordPress PHP coding standards where practical: escape output, check capabilities, verify nonces, and prepare SQL.
- PHP indentation is tabs.

## Do not touch

- Do not change plugin behavior while editing docs, CI, or repo hygiene.
- Do not read or commit `.env`, `CLAUDE.local.md`, `.claude/settings.local.json`, or `keys/license-private.pem`.
- Do not store new data under `lf_` or `localeforge_*`.
- Do not force-push `main`, hard-reset, or rewrite published tags.

# Agent roster

Claude reads this before delegating. Each agent has one job, its own context, and its own tools. The main session keeps the plan and the final edit unless a workflow says otherwise.

Invoke a specialist with `@agent-name` or by delegating when the description matches. Do not run two writers against the same files.

## security-reviewer

- **File:** `.claude/agents/security-reviewer.md`
- **Job:** Read-only review of escaping, nonces, capabilities, SQL, file import, and license or updater changes.
- **Use when:** A change touches `includes/class-ajax.php`, `includes/class-form-handlers.php`, deploy, import, redirects, or anything that reads request data.
- **Returns:** Findings ordered by severity, with file and a concrete fix. No drive-by refactors.
- **Does not:** Edit files, ship a release, or expand scope into a feature.

## test-writer

- **File:** `.claude/agents/test-writer.md`
- **Job:** Turn a behavior change into a verification checklist. Add automated tests only when the user asks for a harness.
- **Use when:** A workflow reaches the verify step, or the user asks how to prove a fix.
- **Returns:** Steps a person can run in wp-admin and on the front end, plus the gaps (no PHPUnit suite today).
- **Does not:** Invent a passing test by weakening the assertion, or mark work verified without running it.

## research

- **File:** `.claude/agents/research.md`
- **Job:** Read-only investigation of Radius behavior so the main session stays small.
- **Use when:** The task depends on deploy, tokens, spintax, places, rewrites, Elementor, Beaver Builder, or the GitHub updater and the relevant method is not already in context.
- **Returns:** The classes and methods involved, the data keys, and the risk if they change.
- **Does not:** Edit files or propose a broad rewrite.

## Handoff

1. Main session states the user outcome in one sentence and names the files it expects to touch.
2. `research` goes first when the area is unfamiliar. Paste only the summary back, not the search trail.
3. Main session makes the smallest change that satisfies the outcome.
4. `security-reviewer` runs before the change is called done if the handoff table above matches.
5. `test-writer` runs after the edit and returns steps. The main session runs those steps when a local site is available (`CLAUDE.local.md`). If it is not available, say so in the reply and in `.claude/memory/progress.md`.
6. One writer at a time. Specialists that only read may run before the edit, not during it.

## Not agents

Hooks (`.claude/hooks/`) are shell, not models. Skills load instructions into the main session. Slash commands (`/review`, `/deploy`, `/scaffold`) are explicit workflows. Use those instead of a new agent when the work is a checklist rather than a separate context.

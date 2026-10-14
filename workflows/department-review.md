# Department review

Claude does not auto-load this file. `CLAUDE.md` and the feature, bug-fix, and code-review workflows point here. Run this after the developer pass, before the change is called done.

## Outcome

Each department says **No update**, or names the file and the change. Interface may name a control and the WordPress pattern it needs. The product's notices, offer, docs, and screens match the change.

## Who runs

The main session is the only writer. These agents are read-only. They may run in parallel. Paste each reply back. Do not give two writers the same file.

| Department | Agent | Question |
| --- | --- | --- |
| Security | `@security-reviewer` | Can a caller escape, forge, or leak through this diff? |
| Legal | `@legal-reviewer` | Do license, copyright, ownership, or disclaimers need a sentence added or removed? |
| Finance | `@finance-reviewer` | Does the license offer still match what the code unlocks? |
| Privacy | `@privacy-reviewer` | Is anything new stored or sent off the site? |
| Interface | `@ui-reviewer` | Does this control follow WordPress UI patterns, sit in a logical place, and work without a mouse or without seeing color? |
| Support | `@support-reviewer` | Can the site owner run this and recover when it fails? |
| Communications | `@communications-reviewer` | Do the header, readme, and changelog still match the product? |
| Quality | `@test-writer` | What must a person prove, and what was not run? |

Security runs on every process. The others run on every process too. A department whose surface is untouched still answers in two lines.

`@research` is not part of this pass. It runs before the edit, when the owning class is unfamiliar.

## After the replies

1. Check a suggestion against the code before accepting it. A suggestion that does not match the code is ignored.
2. Approve a valid suggestion when it does not need the maintainer. The maintainer decides rights, a warranty, a rewrite of `LICENSE`, a price or plan, a privacy policy, and any choice already waiting on them (including P0-01).
3. Add each approved item and each waiting item to `PLAN.md`. Do not open a second item for work already listed there. Approved items do not need another decision. Waiting items name the choice.
4. Write a meeting note at `.claude/memory/meetings/YYYY-MM-DD-HHMM.md`. Use the local time. Summarize only. For each department: what was suggested. Then what was decided: approved, waiting on the maintainer, or ignored, and the `PLAN.md` id. Do not record what was implemented.
5. Implement every approved item that is open and does not wait on the maintainer, in the same session, when it is a security fix, a bug fix, a doc or copy correction, or a small interface fix (a label, a dialog keyboard path, a live region, a text alternative). Do not wait for a separate approval.
6. Leave the item open and say so when the change is a new capability, a new screen, a rearranged admin menu, or a behavior change the maintainer has not already approved. Anything marked waiting stays waiting.
7. Record maintainer decisions that are still open in `.claude/memory/progress.md`.
8. Quality steps run on the local site when the change can affect runtime. Say which steps were not run.

## Done when

Every department has replied, the meeting note exists, new suggestions are on `PLAN.md`, approved security, bug, copy, and small interface items are in progress or done, and any legal, price, privacy, or major UI decision is named as waiting on the maintainer.

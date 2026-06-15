---
name: drupal-code-quality-remediation
description: Apply safe fixes to code-quality / linting findings on the CUSTOM code of a Drupal 10 or 11 site, then re-verify. Consumes the audit from drupal-code-quality-audit (its diagnostic agent), dry-runs the fixes, gets confirmation, and auto-applies ONLY high-confidence mechanical fixes (phpcbf, eslint --fix, stylelint --fix, markdownlint --fix, editorconfig) — flagging everything else (PHPStan type errors, logic, security, deprecations) for human review. Use for "fix the lint errors", "apply the safe coding-standards fixes", "clean up the linting violations", "remediate the static-analysis report". Writes only to the working tree — it never commits and never pushes.
metadata:
  version: "2026.06.05"
---

# Drupal Linting Remediation

Applies fixes for code-quality / linting findings on a Drupal project's **custom code**, then re-verifies the result. It is the back half of the linting workflow: `drupal-code-quality-audit` decides *what's wrong and what's safe to fix*; this skill *does the fixing* — conservatively, with a dry-run and your confirmation, and **never** committing or pushing.

You are the **remediation orchestrator**. You consume an enriched findings envelope (each finding's `fix` block already filled in by the diagnostic agent), dry-run the safe fixes, apply only what's confirmed, and hand the result back verified.

## Guardrails (MUST — read before doing anything)

1. **Never commit. Never push.** Apply changes to the **working tree only**. Do not run `git add`, `git commit`, `git push`, `git stash`, or any history-altering command. When done, the changes sit uncommitted for the human to review (`git diff`) and commit themselves. Say so explicitly. If the user asks you to commit, that's a separate, explicit action — and even then, never push.
2. **Dry-run first, always.** Produce diffs for every change *before* writing, and get explicit user confirmation. No silent edits.
3. **Auto-apply only `fix.autofixable === true && fix.confidence === "high"`.** Everything else — PHPStan type errors, logic violations, security/CVE findings, deprecations, anything `manual`/`low`/`medium` — is **flagged for human review, never auto-applied**.
4. **Custom code only.** Only ever write to custom modules/themes/profiles, repo Markdown, and `.github/workflows/**`. **Never** modify Drupal core, contrib, `vendor/`, or `node_modules/` — even if a finding points there. Treat those paths as write-forbidden.
   - **Honor a single target.** If the caller is working on one custom module/theme (named directly, or because the audit envelope was scoped to it), fix **only** within that target and scope any `--fix` pass to its directory — don't fan out across all custom code. If scope is ambiguous, **ask once** ("just this module/theme, or all custom code?") before applying.
5. **Fix the code, don't silence the check.** Do not make findings disappear by adding to baselines, ignore files, or inline suppressions, or by weakening rule configs, unless the user explicitly asks for a suppression and understands the trade-off.
6. **Behavior-preserving only.** Mechanical fixes must not change runtime behavior. If a "fix" could alter behavior, it isn't high-confidence — flag it.

## Prerequisites

- An **enriched findings envelope** from `drupal-code-quality-audit` (its `drupal-code-quality-diagnostic` agent fills the `fix` blocks). If you don't have one, run the audit first — don't guess fixability yourself.
- A **writable working tree** that is the target site (utest runs in the same tree).
- A clean-ish git state is recommended so the user can review your changes as a clear diff — but you neither require nor create commits.

## When To Use

- Applying safe fixes after a linting audit.
- "Fix the lint errors / coding-standards violations / static-analysis report."

For *seeing* the issues without changing code, use `drupal-code-quality-audit`. For accessibility, use the `drupal-accessibility-*` skills. This skill is **linting only** (see scope note below).

## Pipeline

```text
[enriched envelope from drupal-code-quality-audit / drupal-code-quality-diagnostic]
        ↓
  [partition]   → auto-fix set (autofixable && confidence:high)  +  human-review set
        ↓
  [dry-run]     → diffs for the auto-fix set
        ↓
  [confirm]     → user approves
        ↓
  [apply]       → working-tree edits via fix strategy (custom code only)
        ↓
  [re-verify]   → re-run drupal-code-quality-audit → drupal-code-quality-diagnostic → before/after
        ↓
  [report]      → fixed count, remaining review items, "changes are uncommitted — review & commit yourself"
```

## Read these first

- `reference/finding-format.md` — the envelope + `fix` block this skill consumes. **Required.**
- `reference/severity-levels.md` — severity + gating. **Required.**

## Step 1 — Get the enriched audit

Obtain the enriched envelope from `drupal-code-quality-audit`. If you were handed raw findings (no `fix` blocks), stop and run the audit/diagnostic first — fixability decisions belong to the read-only diagnostic agent, not here.

## Step 2 — Partition

Trust the `fix` blocks. Split:

- **Auto-fix set:** `fix.autofixable === true && fix.confidence === "high"`.
- **Human-review set:** everything else. Carry each finding's `fix.suggestion` forward.

If the auto-fix set is empty, skip to Step 6 and just present the review set — there's nothing safe to apply.

## Step 3 — Dry-run (show diffs before writing)

For the auto-fix set, produce diffs **without committing to keep them**:

- Prefer the tool's native dry-run where it exists (e.g. `eslint --fix-dry-run`).
- Otherwise, apply the fixer to a scratch copy, or apply to the working tree and immediately capture `git diff` as the preview — then revert with `git restore` / `git checkout --` if the user declines. (Reverting the working tree is fine; it's not history.)

Group the preview by file and by strategy (`phpcbf`, `eslint-fix`, `stylelint-fix`, `markdownlint-fix`, `editorconfig-fix`) so the user sees exactly what would change.

## Step 4 — Confirm

Show the dry-run summary and **get explicit confirmation** before any real write. Make it easy to approve a subset (e.g. "apply phpcbf + markdownlint, hold eslint"). Default to *not* writing until told yes.

## Step 5 — Apply (working tree only)

Apply the confirmed fixes via their strategy, scoped to custom code:

- `phpcbf` for PHPCS formatting/array/whitespace sniffs (only those the diagnostic marked high-confidence).
- `eslint --fix`, `stylelint --fix`, `markdownlint --fix`, editorconfig fixes for their engines.

Stay inside the in-scope paths. **Do not commit or push** (Guardrail 1).

## Step 6 — Re-verify

Re-run `drupal-code-quality-audit` (drush adapter) and the `drupal-code-quality-diagnostic` agent on the updated tree. Report **before → after**: findings fixed, findings remaining, and confirm no new findings were introduced (a fix that creates a new violation gets reverted). Bound this loop — if a rule keeps reappearing after 2–3 passes in the same area, stop and escalate it to human review rather than looping.

## Step 7 — Report and stop at the working tree

Present:

- **Fixed:** count by engine/strategy.
- **Needs human review:** the review set, grouped, each with its `suggestion` and why it wasn't safe to automate (escalate when a fix needs a product/UX or behavior decision, multiple valid approaches exist, or it risks regression).
- **Offer to re-run:** explicitly ask whether to re-run `utest:lint` (Step 6) to confirm the findings are resolved and none were introduced. Show before → after if they say yes.
- **Verification caveat:** a clean lint re-run confirms *coding-standards/static-analysis* are satisfied — it does **not** prove behavior is unchanged. The auto-fixed set is behavior-preserving by design, but recommend the user run the project's own test suite (e.g. `utest:phpunit`) and exercise the affected functionality before relying on the changes.
- **Final, explicit line:** the changes are **uncommitted in your working tree** — review them with `git diff`, then commit and push yourself when you're satisfied. This skill does not commit or push.

## Scope note — linting only

This skill remediates the **linting** lane (`utest:lint`: PHPCS, PHPStan, ESLint, cspell, composer, markdownlint, actionlint, deprecations, references). It does **not** handle accessibility (`drupal-accessibility-remediation`) or PHPUnit Functional/Regression results (a different problem — failing tests are behavior/bug fixes, not mechanical remediation). The shared envelope's `suite` field keeps these lanes separate; act only on `suite: "linting"`.

## Portability note

The fix workflow is plain structured markdown. The verification step dispatches to the `drupal-code-quality-diagnostic` registered subagent on Claude; on a runtime without registered subagents (e.g. Copilot), follow that agent's instructions inline. Keep the logic tool-agnostic.

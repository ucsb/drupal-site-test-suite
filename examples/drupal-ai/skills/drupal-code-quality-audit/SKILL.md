---
name: drupal-code-quality-audit
description: 'Evaluate and summarize code-quality / static-analysis findings on the CUSTOM code of a Drupal 10 or 11 site; PHPCS, PHPStan, ESLint, cspell, composer audit/validate, markdownlint, actionlint, deprecations, and related engines. Read-only: it gets findings (from a `drush utest:lint` run, an existing JSON/HTML report, or by evaluating custom code directly when no test suite exists), normalizes them, then groups, dedups, and prioritizes into an audit/triage report. Use for "audit the lint results", "what coding-standards issues does my custom module have", "summarize the static-analysis report", "evaluate my site''s custom code for quality issues", "triage the findings". Stops before changing files; hand off to drupal-code-quality-remediation to fix.'
metadata:
  version: "2026.07.06"
---

# Drupal Linting Audit

Evaluates the **custom code** of a Drupal project for code-quality and static-analysis issues and produces a grouped, prioritized **audit**: what's wrong, how much, how bad, and what it would take to fix. It is **read-only**: it never edits source. Fixing is the job of the sibling skill `drupal-code-quality-remediation`, which consumes this skill's output.

You are the **router**. Your job: (1) resolve where the findings come from, (2) pick the input adapter, (3) hand the normalized envelope to the diagnostic agent, then (4) present the audit and stop.

## Prerequisites

- A Drupal 10/11 checkout (any version; make no version-pinned assumptions), **or** an existing findings report (JSON/HTML).
- For a fresh run on a site that has the test suite: a working `drush` (resolved by the ordered probe in `adapters/adapter-drush.md`).
- The shared contract loaded (see below) before interpreting any findings.

## When To Use

- Auditing/summarizing linting or static-analysis results on a Drupal site.
- A site **without** the test suite needs its custom code evaluated for issues.
- Triaging an existing CI/CD JSON report or a hosted HTML report.
- Producing a read-only quality report; no code changes wanted (yet).

For applying fixes, use **drupal-code-quality-remediation**. For accessibility, use the `drupal-accessibility-*` skills.

## The pipeline

```text
[input: drush utest:lint | site folder | JSON | HTML URL]
        ↓
  [input adapter]      → normalized findings envelope (reference/finding-format.md)
        ↓
  [diagnostic agent]   → grouped, deduped, prioritized audit (read-only)
        ↓
  [present audit] ──── hand off to drupal-code-quality-remediation to fix
```

## Read these first

Load the shared contract before doing anything; every step depends on it:

- `reference/finding-format.md`: the normalized finding schema + envelope. Aligned to the project's own emitted contract. **Required.**
- `reference/severity-levels.md`: severity (4 levels) + impact taxonomy + per-engine mapping. **Required.**

On a site that ships the test suite, its own `tests/README.md` (and `tests/README_cheatsheet.md`) are the authoritative reference for the exact `utest:*` commands, setup, scope model, and report locations; defer to them for suite-specific detail.

## Scope: custom code only (hard guardrail)

The audit covers **only custom code**: we don't manage Drupal core or contrib:

- **In scope:** custom modules, themes, profiles, custom drush commands, repo Markdown, `.github/workflows/**`.
- **How scope is resolved (canonical → fallback):** `composer.json` `extra.installer-paths` first, the `type:drupal-custom-*` mappings define the custom paths (and `type:drupal-module`/`-theme`/`-library`/`-core` mark contrib/core/libraries as out of scope); then `tests/code-quality/config/custom-paths.json` overrides/extras; then `*.info.yml` autodiscovery as a safety net (catches custom code committed outside the installer paths, e.g. a root-level subtheme at `web/themes/<theme>/`).
- **Never:** Drupal core, contrib, `vendor/`, `node_modules/`.

`utest:*` reports are already custom-scoped. When running tools directly (folder adapter), honor these boundaries; scanning contrib produces thousands of irrelevant findings and is the fastest way to make an audit useless. Drop any finding whose only locations fall outside custom scope.

**Full custom code vs. a single target.** Default scope is *all* custom code. If the caller names a single target (a specific custom module or theme, by machine name or path) narrow to just that: drush adapter → `utest:lint --modules=<name>` / `--themes=<name>` (comma-separated for several); folder adapter → run the tools only in that directory; report findings for that target only. If the request is ambiguous about how wide to go, **ask once** ("this module/theme, or all custom code?") rather than silently scanning everything, a too-broad run is the common surprise.

## Step 1: Resolve the input source

Pick the adapter by what the user gives you. If ambiguous, ask once; don't guess between running a fresh scan and parsing an existing report; their side effects and runtimes differ sharply.

| If the user… | Adapter | Read | Status |
| --- | --- | --- | --- |
| points at a Drupal checkout with the test suite + working `drush`, wants a fresh run | drush | `adapters/adapter-drush.md` | **built** |
| points at a site folder with **no** test suite (evaluate custom code directly) | folder | `adapters/adapter-folder.md` | **built** |
| gives a CI/CD JSON report (or path to one), incl. raw tool JSON / SARIF (e.g. gitleaks) | json | `adapters/adapter-json.md` | **built** |
| gives a URL to a hosted HTML report | html | `adapters/adapter-html.md` | **built** |

Default when a live checkout with the suite is present: **drush**: it's the project's source of truth and guarantees the same engines/config as CI. The unified report lives at `$BASE_URL/sites/default/files/test-reports/index.html`; per-lane reports sit under that `test-reports/` directory.

## Step 2: Get normalized findings

The adapter produces a **findings envelope** (schema in `reference/finding-format.md`). Before moving on, sanity-check `coverage`:

- Engines **skipped** (tool not installed, no live site) → surface that now. A near-empty report because tools didn't run is **not** a clean bill of health.
- `findings` empty **and** nothing skipped → report the clean pass and stop.

## Step 3: Diagnose (the audit)

Dispatch to the **`drupal-code-quality-diagnostic`** agent, a registered, **read-only** subagent (in the org `agents/` directory; tool-scoped so it physically cannot write). It groups by engine / file / severity, dedups by `id`, enriches each finding's `fix` block (autofixable? strategy? confidence?) using its built-in fix heuristics (and per-engine `tools/` docs when the skill ships them), and emits the prioritized audit; split into an auto-fixable set and a human-review set, gated on `critical` + `serious`. (On a runtime **without** registered subagents, e.g. GitHub Copilot; follow this skill's `diagnostic.md` instead; it's the same logic, read-only by discipline, and ships inside the skill so it's always present.)

## Step 4: Present and hand off

Present the audit: totals by severity and impact, the top issues, what's safely auto-fixable vs. needs review, and which engines were skipped. Then **stop**: this skill changes nothing. To act on it, invoke **drupal-code-quality-remediation** with this envelope/audit as input.

## Portability note

Keep the logic here tool-agnostic. The routing table, pipeline, and finding schema are plain structured markdown with no runtime-specific syntax, so porting to another agent runtime (e.g. GitHub Copilot) is a wrapping exercise, not a rewrite. Don't bake runtime-specific assumptions into the adapters or agents.

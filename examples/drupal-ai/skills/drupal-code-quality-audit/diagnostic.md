# Linting Diagnostic (read-only audit engine): portable in-skill copy

> **Portability note.** This is the **runtime-agnostic mirror** of the registered
> `drupal-code-quality-diagnostic` agent. On Claude Code the orchestrator dispatches to
> that agent (which gets read-only tools enforced). On a runtime **without**
> registered subagents (e.g. GitHub Copilot), follow **this** doc inline instead;
> the logic is identical. **Keep this file and `agents/drupal-code-quality-diagnostic.agent.md`
> in sync.** Here, "read-only" is a **discipline**, not a tool restriction: you must
> not write, fix, or run `phpcbf`/`--fix`: only read and report.

Turns a normalized findings envelope into a **grouped, deduped, prioritized audit**: the deliverable of `drupal-code-quality-audit`. It decides *what matters, how much, and what it would take to fix*, but it **never changes a file and never runs a fix**.

## Contract

- **Input:** one findings envelope from an input adapter (schema: `reference/finding-format.md`), with `suite: "linting"`.
- **Output:** an **audit report** for the user, plus an **enriched envelope** (each finding's `fix` block filled in) the remediation step can consume.
- **Read-only. Always.** No edits, no `phpcbf`, no `--fix`, no writes. If tempted to "just fix this one," stop; put it in the auto-fix set and hand off.
- **Deterministic.** Same envelope → same audit.

## Step 1: Validate and take coverage honestly

1. **Version gate.** If `schema_version` major ≠ `1`, stop and report the version seen.
2. **Read `coverage` first.** Engines in `coverage.engines_skipped` mean *unknown*, not *clean*. A small finding count because tools didn't run is **not** a passing grade; surface skipped engines at the top of the audit.
3. If `findings` is empty **and** nothing was skipped, report the clean pass and stop.

## Step 2: Dedup and group

- **Dedup by `id`** (`engine:rule:path:line`). Merge duplicates; sum `occurrences`/`locations`.
- **Group** by `severity` (gating), `engine` (fix strategy), `impact_category` (user axis), file (`locations[].path`), and `rule_id` (so one noisy rule firing 200× reads as one thing to fix).

## Step 3: Enrich each finding's `fix` block (the core value-add)

The adapter left every `fix` field `null`. Fill them; this is what separates an audit from a raw dump. For each finding decide `fix.autofixable`, `fix.strategy`, `fix.confidence` (`high` only when mechanical *and* can't change behavior), and `fix.suggestion` for the human-review ones. **Be conservative**: unsure → `autofixable: false`. Severity is irrelevant here.

**Prefer authoritative fixability when the adapter captured it.** The folder and raw-JSON adapters run tools that report fixability directly (phpcs JSON `fixable`, ESLint fixable rules) and pass it through as a `tags` signal (`autofix:available` / `autofix:none`). When present, use it verbatim and skip the heuristic. When the per-engine `tools/` docs exist in the skill, consult the one matching the finding's `engine` for rule-level detail. The table below is the **fallback** for inputs that don't carry it (notably the utest `test-suite-findings.json`).

| Engine | Autofix | Default confidence | Notes |
| --- | --- | --- | --- |
| phpcs | `phpcbf` | `high` for whitespace / array / formatting / ordering sniffs; `manual` for `*.Commenting.*` and logic sniffs | The sniff name tells you; `phpcbf` fixes only a subset. |
| eslint | `eslint-fix` | `high` for fixable rules; `manual` for logic / a11y rules | Only ESLint-"fixable" rules. |
| stylelint | `stylelint-fix` | `high` for style; `manual` for a11y-plugin rules | Lazy-loaded; absent from `tool` when not installed → skipped, not clean. |
| htmlhint | - | `manual` | Markup issues; fix in custom Twig by hand; no safe autofix. Lazy-loaded. |
| twigcs | - | `manual` | Twig coding-standard; spacing/format judgment in templates; no autofix. |
| markdownlint | `markdownlint-fix` | `high` for most style rules | |
| editorconfig | `editorconfig-fix` | `high` | Trailing whitespace, final newline, indentation; purely mechanical. |
| cspell | `cspell-add-word` or typo fix | `medium` | Real domain word (add to dictionary) vs. typo (fix) needs a human call. |
| yaml-lint | - | parse → `manual`; style → `medium` | Structurally broken YAML can't be auto-fixed safely. |
| composer (validate) | `composer-lock-refresh` | `medium` | Lock drift changes the lock file; review. Schema errors → `manual`. |
| composer (audit) | - | `low` / `manual` | Dependency bump is a potential behavior change; review. `impact_category: security`. |
| gitleaks | - | `manual` | Secret → **remove AND rotate** the credential; never autofix, never just delete the line. `critical`, `security`. |
| phpstan | - | `manual` | Type / undefined-behavior errors; never autofix. |
| deprecations | - | `manual` | Port off the deprecated API per its change record. |
| references | - | `low` / `manual` | Fix the broken asset path or missing `attach_library` by hand. |
| actionlint / shellcheck | - | `manual` | Workflow / shell logic; judgment required. |

## Step 4: Prioritize and split

- **Gate set:** `severity` in {`critical`, `serious`}; what blocks merge (see `reference/severity-levels.md`). Lead with these.
- **Auto-fix set:** `fix.autofixable === true && fix.confidence === "high"`: the only findings remediation may apply without a human decision.
- **Human-review set:** everything else; each carries a `fix.suggestion`.

## Step 5: Emit the audit

**(a) Human-readable:** coverage banner (engines run vs. skipped, **plus the audited scope**: a single target module/theme vs. all custom code; a narrow scope must be stated so a small result isn't read as a full clean pass) → totals by severity & impact + gating verdict → top priorities (gate set) → auto-fixable summary by strategy → needs-human-review with suggestions.

**(b) Enriched envelope:** input unchanged except each `fix` block populated; preserve every `id`.

## Guardrails

- **Read-only**, always (here by discipline). You diagnose; never write.
- **Custom code only.** Never propose changes to core/contrib/`vendor/`/`node_modules/`.
- **Don't re-map severity.** Trust the envelope; you set *fixability*, not severity.
- **Don't auto-apply anything**: that belongs to remediation, behind user confirmation.
- **Honesty over tidiness.** Admit skipped engines.

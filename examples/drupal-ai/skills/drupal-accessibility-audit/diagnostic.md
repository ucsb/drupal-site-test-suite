# Accessibility Diagnostic (read-only audit engine) — portable in-skill copy

> **Portability note.** This is the **runtime-agnostic mirror** of the registered
> `drupal-accessibility-diagnostic` agent. On Claude Code the orchestrator dispatches
> to that agent (read-only tools enforced). On a runtime **without** registered
> subagents (e.g. GitHub Copilot), follow **this** doc inline — identical logic.
> **Keep this file and `agents/drupal-accessibility-diagnostic.agent.md` in sync.**
> Here, "read-only" is a **discipline**, not a tool restriction: read and report only.

Turns an aggregated accessibility findings envelope into a **grouped, prioritized, profile-aware audit** — the deliverable of `drupal-accessibility-audit`. It decides *what's wrong, who it affects, whether it's legally required, whether it lives in our custom code, and what fixing it would take* — but it **never changes a file**.

## Contract

- **Input:** one aggregated envelope (schema: `reference/finding-format.md`) with `suite: "accessibility"`, merging the a11y lanes that ran.
- **Output:** an **audit report** plus an **enriched envelope** (each finding's `fix` + `attribution`) for `drupal-accessibility-remediation`.
- **Read-only. Always.**
- **Never claim full conformance.** Automated tools find a fraction of WCAG. Report what was found and checked, not "accessible."

## Step 1 — Validate and take coverage honestly (a11y is full of false-clean traps)

1. **Version gate** on `schema_version` major (`1`).
2. **Read `coverage.lanes` before findings:**
   - **Alfa** reports only `critical`/`serious` by default — 0 Alfa findings doesn't mean moderate/minor are clean.
   - **pa11y** carries no `profile` and defaults `impact_category` to `uncategorized` — refine impact yourself (Step 4).
   - Lanes needing an API key (axe-watcher) or a running site may be skipped — surface them.
   - Low `pages_tested` (capped `--max-pages` / thin sitemap) = partial coverage. Report it.
3. If every lane is `status: pass` and nothing was skipped/capped, report the clean-within-coverage result — and still state the boundary.

## Step 2 — Dedup and group

- **Dedup by `id`** within a lane.
- **Cross-engine grouping (don't blind-merge):** axe, Alfa, pa11y often flag the same WCAG issue with different `rule_id`s. Group findings sharing `(normalized url, selector)` and/or the same WCAG SC — but keep each engine's `rule_id`/`description`.
- Group by `impact_category` (disability axis), WCAG SC, page (URL), `selector`, engine.

## Step 3 — Classify legal vs aspirational (profile-aware — drives priority)

Use the project's exact profile tag sets (`tests/accessibility/config/a11y-profiles.js`):

- **`standard`** = `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa` — **WCAG 2.0/2.1 Level A + AA. The bar current law requires.**
- **`comprehensive`** = standard **plus** `wcag2aaa`, `wcag21aaa` (AAA), `wcag22a`, `wcag22aa` (WCAG 2.2 A/AA), and `best-practice`.

Classify each finding by its WCAG tags — from `wcag_criteria` if present, else parse `rule_id` (pa11y encodes it: `WCAG2AA.…2_4_1` → SC 2.4.1, level AA), else the lane's `profile.tags`:

- **Legally required** — tags in the **standard** set (2.0/2.1 A/AA). Priority; these gate.
- **Recommended (emerging)** — **WCAG 2.2 A/AA** (`wcag22a`/`wcag22aa`, e.g. target-size 2.5.8). Not in today's legal bar but where law is moving — surface as strongly recommended.
- **Aspirational** — AAA + `best-practice`. Report, clearly marked beyond current legal requirement.

**Gating verdict** = block on **legally-required** findings at `critical` + `serious`. Recommended/aspirational never gate.

## Step 4 — Attribute to custom code, and enrich `fix`

Findings are **page-located** (`kind: page`, URL + `selector`), so two judgments:

**(a) Custom vs upstream.** Fix only **our custom code** (custom theme/module Twig, CSS, JS, content, config). A violation rooted in **core/contrib** markup or a core route (`/core/*`, much of `/admin/*`) is out of our control — flag `attribution: upstream`, don't queue it. Use `selector`/`snippet` (custom classes vs core) and the route to judge; when unsure, `attribution: unknown` → human review.

**(b) Fixability.** A11y is mostly **manual** — the fix is a judgment-laden source change. Defaults:

| Finding type | Likely source | Strategy / confidence |
| --- | --- | --- |
| meta-viewport (`user-scalable=no` / `maximum-scale<2`) | custom theme `html.html.twig` viewport meta | `manual` / `medium` — near-mechanical, verify it's a custom template |
| missing/empty alt (axe `image-alt`) | custom Twig / field content | `manual` — needs *meaningful* alt text |
| region / landmark (axe `region`) | custom theme template structure | `manual` |
| color-contrast | custom theme CSS tokens | `manual` — design decision |
| link/anchor name, broken target (pa11y) | custom Twig / menu / content | `manual` |
| heading-order | custom template / content | `manual` |
| reflow (SC 1.4.10) | custom theme CSS layout | `manual` |

Set `fix.suggestion` with the WCAG-correct change. For the *how*, remediation consults the WCAG knowledge in the `a11y` agent — you only identify the issue, where it likely lives, and the corrective intent.

## Step 5 — Emit the audit

**(a) Human-readable:** coverage banner (lanes, `pages_tested`, profile per lane, **the audited scope** — findings filtered to a single target module/theme vs. all custom code, noting pages are always crawled site-wide — and every Step-1 caveat) → compliance summary (legally-required vs recommended vs aspirational, by severity & disability; gating verdict) → top priorities (legally-required, with custom/upstream attribution) → out-of-our-control (upstream) → needs-human-review.

**(b) Enriched envelope:** input unchanged except each finding has `fix` + `attribution`; preserve every `id`.

## Guardrails

- **Read-only** (here by discipline).
- **Custom code only.** Flag core/contrib-rooted findings `upstream`; never queue them.
- **Never claim full WCAG conformance.** State coverage honestly.
- **Don't re-map severity.** You set fixability, attribution, and legal/aspirational classification.
- **Profile honesty.** Say which profile ran and that legal compliance is judged on the **standard** subset.
- **Don't auto-apply anything** — that belongs to remediation.

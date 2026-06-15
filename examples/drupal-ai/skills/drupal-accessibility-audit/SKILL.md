---
name: drupal-accessibility-audit
description: 'Evaluate and summarize accessibility (WCAG) findings on a Drupal 10 or 11 site — aggregating the a11y test lanes (axe-core, Siteimprove Alfa, pa11y, reflow at 320px, meta-viewport). Read-only: it gets findings (from `drush utest:a11y:*` runs or existing JSON/HTML reports), normalizes and merges them, classifies each as legally-required (standard profile) vs aspirational (comprehensive AAA/best-practice), attributes it to custom code vs upstream core/contrib, and produces a prioritized, profile-aware accessibility audit. Use for "audit accessibility", "what WCAG issues does the site have", "summarize the axe/pa11y/Alfa report", "check accessibility compliance". Stops before changing files — hand off to drupal-accessibility-remediation to fix. Never claims full conformance.'
metadata:
  version: "2026.06.05"
---

# Drupal Accessibility Audit

Evaluates a Drupal site for accessibility (WCAG) issues and produces a grouped, prioritized, **profile-aware** audit. It is **read-only**: it never edits source and never claims the site is "accessible" — automated tools find only a fraction of WCAG criteria. Fixing is the sibling skill `drupal-accessibility-remediation`; full assurance still needs human testing.

You are the **router**: (1) resolve where the a11y findings come from, (2) pick the adapter, (3) hand the aggregated envelope to the diagnostic agent, then (4) present the audit and stop.

## Prerequisites

- A Drupal 10/11 site (any version — no version-pinned assumptions), **reachable over HTTP** with a `BASE_URL`, because a11y engines test *rendered pages* (unlike linting, which is static). A sitemap helps the crawl find pages.
- For a fresh run: a working `drush` (ordered probe in `adapters/adapter-drush.md`).
- The shared contract loaded (below) before interpreting findings.

## When To Use

- Auditing/summarizing accessibility or WCAG results on a Drupal site.
- Triaging an existing axe / Alfa / pa11y / reflow / meta-viewport report.
- Producing a read-only compliance picture — no code changes yet.

For applying fixes, use **drupal-accessibility-remediation**. For code-quality/linting, use the `drupal-code-quality-*` skills.

## The pipeline

```text
[input: drush utest:a11y:* lanes | existing JSON | HTML report]
        ↓
  [input adapter]   → ONE aggregated findings envelope (reference/finding-format.md)
        ↓
  [diagnostic agent: drupal-accessibility-diagnostic]
        → grouped, profile-classified (legal vs aspirational), custom-vs-upstream, prioritized audit
        ↓
  [present audit] ── hand off to drupal-accessibility-remediation to fix
```

## Read these first

- `reference/finding-format.md` — the normalized finding + envelope. **Required.**
- `reference/severity-levels.md` — severity + impact taxonomy + per-engine mapping. **Required.**

On a site that ships the test suite, its own `tests/README.md` (and `tests/README_cheatsheet.md` + `tests/accessibility/config/README.md`) are the authoritative reference for the exact `utest:a11y:*` commands, profiles, and report locations — defer to them for suite-specific detail.

## Profiles — legal vs aspirational (read before you report)

The a11y lanes run under a **profile**: `standard` (WCAG 2.0/2.1 Level A + AA) is the **WCAG 2.1 AA baseline recognized under Section 504 of the Rehabilitation Act** — the common legal/compliance bar; `comprehensive` (the CI default) adds WCAG **AAA + WCAG 2.2 A/AA + best-practice** on top. So the audit must say *which profile ran* and split results:

- **Legally required** = WCAG **A/AA** failures → the priority; gating reads these.
- **Aspirational** = AAA / best-practice (only present under `comprehensive`) → reported, but clearly marked as beyond current legal requirement.

For a pure legal-compliance read, run/request the **standard** profile (`--a11y-profile=standard`). The diagnostic classifies legal vs aspirational regardless.

## Scope — custom code only, with an a11y twist

We audit the whole rendered page, but we can only **fix** what's in **our custom code** (custom theme/module Twig, CSS, JS, content, config):

- **Fixable:** violations rooted in custom themes/modules.
- **Out of our control (flag, don't fix):** violations rooted in **Drupal core or contrib** markup, or on core-rendered routes (`/core/*`, much of `/admin/*`). The diagnostic attributes each finding `custom` vs `upstream`.
- **Never:** edit core, contrib, `vendor/`, `node_modules/`.

**Full custom code vs. a single target.** Default scope is *all* custom code. If the caller names a single target — a specific custom module or theme — the scan still crawls whole rendered pages (there's no per-component scan flag), so run it normally, then **filter / source-map the findings to that target's** Twig/CSS/config and report only those (note that pages were crawled site-wide). If the request is ambiguous about how wide to go, **ask once** ("this theme/module, or all custom code?") rather than presenting findings for everything.

## Step 1 — Resolve the input source

| If the user… | Adapter | Read | Status |
| --- | --- | --- | --- |
| has a reachable site + working `drush`, wants a fresh run | drush | `adapters/adapter-drush.md` | **built** |
| gives existing a11y JSON report(s) | json | `adapters/adapter-json.md` | **built** |
| gives a hosted HTML report URL | html | `adapters/adapter-html.md` | **built** |
| reachable site but **no** test suite (run a11y engines directly) | folder | `adapters/adapter-folder.md` | **built** |

Default with a reachable site: **drush** — it runs the same engines/config as CI. The unified report lives at `$BASE_URL/sites/default/files/test-reports/index.html`; per-lane reports sit under that `test-reports/` directory.

## Step 2 — Get normalized findings

The adapter produces **one aggregated envelope** merging every a11y lane that ran (axe, Alfa, pa11y, reflow, meta-viewport each emit their own file). Before moving on, sanity-check `coverage` — a11y "clean" is easy to fake:

- **Alfa** checks only `critical`/`serious` by default; **pa11y** has no profile; lanes can be **capped** (`--max-pages`) or **skipped** (missing API key, unreachable). Surface all of this. A clean result only covers the pages and severities actually checked.

## Step 3 — Diagnose

Dispatch to the **`drupal-accessibility-diagnostic`** agent — a registered, **read-only** subagent (tool-scoped, can't write). It dedups, groups by disability/WCAG/page, classifies **legal vs aspirational**, attributes **custom vs upstream**, enriches each `fix` block, and emits the prioritized audit. (On a runtime **without** registered subagents — e.g. GitHub Copilot — follow this skill's `diagnostic.md` instead; same logic, read-only by discipline, and it ships inside the skill so it's always present.)

## Step 4 — Present and hand off

Present: coverage + profile caveats, legal-vs-aspirational totals by disability and severity, top required issues with custom/upstream attribution, and what's out of our control. Then **stop** — this skill changes nothing.

To fix, invoke **drupal-accessibility-remediation** with this envelope. Always remind the user: a passing automated re-run is **not** proof of accessibility — **human testing** (screen reader, keyboard-only, 200% zoom) is still required.

## Portability note

The routing, pipeline, and schema are plain structured markdown. The diagnose step dispatches to the `drupal-accessibility-diagnostic` subagent on Claude; on a runtime without registered subagents (e.g. Copilot), follow that agent's instructions inline. Keep the logic tool-agnostic.

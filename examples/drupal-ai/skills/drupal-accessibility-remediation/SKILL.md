---
name: drupal-accessibility-remediation
description: Apply fixes for accessibility (WCAG) findings on the CUSTOM code of a Drupal 10 or 11 site, then re-verify. Consumes the audit from drupal-accessibility-audit, maps each page/selector finding back to the custom Twig template / theme CSS / content / config that produced it, proposes WCAG-correct fixes (reusing the a11y agent's WCAG 2.2 AA knowledge), dry-runs them, and applies only confirmed changes — prioritizing legally-required (WCAG A/AA) issues. Most a11y fixes need human judgment (alt text, ARIA, contrast) so it confirms before writing. Writes only to the working tree — never commits, never pushes. Never claims full conformance; human testing still required. Use for "fix the accessibility issues", "remediate the WCAG/axe/pa11y violations", "fix the a11y findings".
metadata:
  version: "2026.06.05"
---

# Drupal Accessibility Remediation

Applies fixes for accessibility findings on a Drupal project's **custom code**, then re-verifies. It is the back half of the a11y workflow: `drupal-accessibility-audit` decides *what's wrong, who it affects, whether it's legally required, and whether it lives in our custom code*; this skill *does the fixing* — conservatively, mapping each finding to its source, with a dry-run and your confirmation, and **never** committing or pushing.

Accessibility remediation is fundamentally different from linting remediation: findings are **page-located** (a URL + CSS selector), not a file:line, and most fixes require **judgment** (the right alt text, the correct ARIA, an accessible color) — there is rarely a mechanical autofix. So this skill is **guided**, not batch-automated.

## Guardrails (MUST — read before doing anything)

1. **Never commit. Never push.** Working tree only. No `git add` / `commit` / `push` / `stash`. When done, changes sit uncommitted for the human to review (`git diff`) and commit themselves. Say so.
2. **Custom code only.** Only edit custom theme/module Twig, CSS, JS, content, and config. **Never** core, contrib, `vendor/`, `node_modules/`. **Skip findings the diagnostic marked `attribution: upstream`** — those are core/contrib markup we can't fix; list them as out of our control.
   - **Honor a single target.** If the caller is working on one custom module/theme (named directly, or because the audit was scoped to it), fix **only** findings that source-map into that target — don't fan out across all custom code. If scope is ambiguous, **ask once** ("just this theme/module, or all custom code?") before applying.
3. **Almost nothing is "auto-apply safe" in a11y.** Auto-apply only the rare mechanical, behavior-preserving fix at `confidence: high` (e.g. removing `user-scalable=no` from a custom theme's viewport meta). Everything else — alt text, ARIA, contrast, landmarks, focus, reflow — is a **judgment fix**: propose it, show the diff, and get explicit confirmation before writing.
4. **Dry-run first, always.** Show the proposed source change before writing it.
5. **Prefer native HTML over ARIA; don't invent colors; follow the component library.** Defer to the WCAG patterns in the **`a11y`** agent (WCAG 2.2 AA). Use design tokens for color, native elements before ARIA, and existing component patterns rather than recreating them.
6. **Preserve meaning and behavior.** A fix must not change what content says or how it works. Alt text must describe the actual image; a relabel must keep the visible label.
7. **Never claim the result is "accessible" or "fully conformant."** Automated re-runs verify only what the engines check (~30–40% of WCAG). Always end with the human-testing requirement.

## Prerequisites

- An **enriched a11y envelope** from `drupal-accessibility-audit` (its `drupal-accessibility-diagnostic` agent fills `fix` + `attribution`). If you don't have one, run the audit first.
- A **writable working tree** that is the target site.
- A **reachable site + `BASE_URL`** for re-verification (a11y tests need rendered pages), and the ability to run `drush cr`.

## When To Use

- Applying fixes after an accessibility audit.
- "Fix the accessibility / WCAG / axe / pa11y issues."

For *seeing* the issues, use `drupal-accessibility-audit`. For code-quality, use `drupal-code-quality-remediation`.

## Pipeline

```text
[enriched envelope from drupal-accessibility-audit / drupal-accessibility-diagnostic]
        ↓
  [scope filter]   → custom-attributed, in-scope findings (drop/flag upstream)
        ↓
  [prioritize]     → legally-required (A/AA) first, then recommended (WCAG 2.2), then aspirational
        ↓
  [source-map]     → each URL+selector → custom Twig template / theme CSS / content / config
        ↓
  [propose fix]    → WCAG-correct change (consult the a11y agent) → dry-run diff
        ↓
  [confirm]        → per fix (judgment-heavy) → apply (working tree only)
        ↓
  [re-verify]      → drush cr (+rebuild theme assets) → re-run a11y lanes → before/after
        ↓
  [report]         → fixed · remaining · upstream · HUMAN TESTING REQUIRED · uncommitted
```

## Read these first

- `reference/finding-format.md` — the envelope + `fix`/`attribution` this skill consumes. **Required.**
- `reference/severity-levels.md` — severity + impact taxonomy. **Required.**
- The **`a11y`** agent — the WCAG 2.2 AA correction patterns (semantics, keyboard/focus, contrast, forced-colors, reflow, labels, forms, graphics). This skill identifies *where* and *what*; the `a11y` agent supplies *how to write it correctly*. (On a runtime without registered subagents, follow its instructions inline.)

## Step 1 — Get the enriched audit and filter to scope

Obtain the enriched envelope. Drop (but list) `attribution: upstream` findings — core/contrib markup we can't fix. Keep `attribution: custom`; treat `attribution: unknown` as human-review (don't guess-edit). Order what remains by tier (legally-required → recommended → aspirational) then severity.

## Step 2 — Source-map each finding (the core a11y work)

A finding gives you a **URL** and a **CSS selector** (+ often an HTML `snippet`). Trace it to the custom source that renders it:

- **Which template?** Map the route/page to its theme layer: `html.html.twig` (document/`<head>`/viewport), `page.html.twig` / region templates (landmarks, skip links), `node--*.html.twig` / `field--*.html.twig` / paragraph/block/views templates (content markup). Use Drupal's theme-hook-suggestion order; `drush` and the selector's classes/IDs tell you which custom theme/module owns the markup.
- **Markup vs CSS vs content?** Structure/ARIA/labels → Twig template. Contrast/focus-visible/reflow → custom theme **CSS** (design tokens). Missing/poor alt text or link text → often **content** (a field value the editor set) rather than a template — in that case the fix is a content edit or a template fallback, and you flag it for the content owner.
- **Confirm before editing.** Grep the custom theme/module for the selector's classes/IDs and the snippet to pin the exact file. If you can't confidently locate custom source (it may be core/contrib output), move it to human-review rather than editing.

## Step 3 — Propose the WCAG-correct fix

For each in-scope finding, derive the corrective change from the **`a11y`** agent's rules — native HTML first, ARIA only when needed, contrast via tokens, visible focus, etc. Classify:

- **Mechanical-safe** (rare): e.g. remove `user-scalable=no` / `maximum-scale<2` from a custom viewport meta → `confidence: high`.
- **Guided** (most): alt text, ARIA roles/names, landmark structure, heading order, contrast values, focus management, reflow CSS → needs human confirmation of wording/semantics/design.

Write a concrete `fix.suggestion` (the exact Twig/CSS/content change) for each.

## Step 4 — Dry-run and confirm

Show the proposed diff per finding. Because a11y fixes are judgment-heavy, **confirm before writing** — per fix, or batched by type (e.g. "apply all the viewport-meta fixes; hold the alt-text ones for wording"). Default to not writing until told yes. Make clear which choices are content/UX decisions the user owns.

## Step 5 — Apply (working tree only)

Apply confirmed fixes to the custom source. Stay inside custom code. **Do not commit or push** (Guardrail 1).

## Step 6 — Re-verify (a11y needs a cache rebuild first)

Twig/CSS edits won't show up until Drupal re-renders, so before re-running:

```bash
<DRUSH> cr            # rebuild caches so template/render changes take effect
```

Rebuild theme assets too if the theme has a build step (e.g. compiled CSS/JS). **Then** re-run the relevant a11y lanes (or dispatch to `drupal-accessibility-diagnostic`) and report **before → after**: findings resolved, remaining, and confirm no new violations were introduced. Bound the loop — if a finding resists fixing after 2–3 attempts, escalate to human review.

## Step 7 — Report, require human testing, stop at the working tree

Present:

- **Fixed:** by WCAG SC / type.
- **Remaining (needs human review):** each with its `suggestion` and why it needs a person (wording, semantics, design, or unclear source).
- **Out of our control:** the `upstream` (core/contrib) findings.
- **Offer to re-run** the lanes to confirm (after `drush cr`).
- **HUMAN TESTING REQUIRED (always):** a passing automated re-run is **not** proof of accessibility — automated tools check a fraction of WCAG. Require manual testing: **screen reader** (VoiceOver/NVDA), **keyboard-only** navigation (tab order, focus visibility, no traps), and **200% zoom / 320px reflow**. Do not state the site is "accessible" or "WCAG compliant."
- **Final line:** changes are **uncommitted in your working tree** — review with `git diff`, then commit and push yourself. This skill does not commit or push.

## Scope note — accessibility only

Remediates the a11y lanes (`suite: "accessibility"`). Not linting (`drupal-code-quality-remediation`) and not PHPUnit. Act only on `suite: "accessibility"`.

## Portability note

The workflow is plain structured markdown. Re-verify dispatches to `drupal-accessibility-diagnostic`, and fix patterns come from the `a11y` agent — both registered subagents on Claude; on a runtime without them, follow their instructions inline. Keep the logic tool-agnostic.

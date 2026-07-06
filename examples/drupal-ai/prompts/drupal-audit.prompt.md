---
name: drupal-audit
description: Run a read-only audit (code-quality and/or accessibility) on a Drupal 10/11 site and produce a prioritized findings report. Makes no code changes.
argument-hint: "Optional: a target (module/theme name or path), a domain (linting | accessibility | both), and/or an input (report path/URL). Defaults: full custom code, both, fresh run."
---

Run a **read-only audit** of this Drupal 10/11 site and present a prioritized report. Do **not** change any files; auditing only. Arguments: `{{args}}`.

## 1. Scope the target (ask first; don't default to everything)

Before auditing, decide **what to evaluate**. If `{{args}}` already names a target (a module/theme machine name or a path), use it. Otherwise **ask the user** which scope they want; do **not** silently scan all custom code:

1. A specific **custom module** (e.g. `my_module` / `web/modules/custom/my_module`).
2. A specific **custom theme / subtheme** (e.g. `my_theme` / `web/themes/custom/my_theme`).
3. The **full custom code** (all custom modules, themes, and profiles).

Pass the chosen scope down to the audit skill:

- **drush adapter** → `utest:lint --modules=<name,name>` or `--themes=<name,name>`; omit the flag only for full custom code.
- **folder adapter** → restrict the tool runs to the target directory; use the full resolved custom scope only for option 3.
- **accessibility** → the scan crawls rendered pages, so there's no per-module flag; run it, then **filter / source-map findings to the chosen target's** Twig/CSS/config and note that the pages themselves are site-wide.

## 2. Decide domain and input

- **Domain**: from `{{args}}`: `linting` (code-quality), `accessibility`, or `both` (default if unspecified).
- **Input source**: let each audit skill's router pick by what's available:
  - a live checkout with the `utest` suite + working `drush` → the **drush** adapter (preferred);
  - a checkout with **no** suite → the **folder** adapter (runs the tools directly on custom code);
  - a `{{args}}` that is a JSON/SARIF report or a hosted HTML report URL → the **json** / **html** adapter.
- Accessibility needs a reachable site (`BASE_URL`); linting does not.

## 3. Run the audit(s)

- Code-quality → invoke **drupal-code-quality-audit**.
- Accessibility → invoke **drupal-accessibility-audit**.

Each routes input → normalized envelope → its read-only diagnostic agent → a grouped, prioritized audit.

## 4. Present and stop

Report, then stop (no changes):

- **Coverage first**: engines run vs. skipped (with reasons), pages tested / files scanned. A near-empty result because tools didn't run is **not** a clean pass.
- **Linting:** totals by severity, merge-gating verdict (block on critical + serious), auto-fixable vs. human-review counts, top issues.
- **Accessibility:** split **legally-required (WCAG A/AA)** vs. **recommended (WCAG 2.2)** vs. **aspirational (AAA/best-practice)**; group by disability; mark **custom vs. upstream (core/contrib)**; never claim full conformance.

To fix anything, use **/drupal-remediate** (or the matching remediation skill). This prompt never writes, commits, or pushes.

## Skills Required

- drupal-code-quality-audit
- drupal-accessibility-audit

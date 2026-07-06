---
name: drupal-remediate
description: Audit a Drupal 10/11 site's custom code, then apply only safe fixes; dry-run first, confirm, working tree only, never commit or push. Re-verifies, and requires human testing for accessibility.
argument-hint: "Optional: a target (module/theme name or path) and/or a domain (linting | accessibility | both)."
---

Audit this Drupal 10/11 site and then **remediate** its custom code, conservatively. Arguments: `{{args}}`.

## 0. Scope the target (ask first; don't default to everything)

Before auditing, decide **what to evaluate and fix**. If `{{args}}` already names a target (a module/theme machine name or a path), use it. Otherwise **ask the user** which scope they want; do **not** silently run across all custom code:

1. A specific **custom module** (e.g. `my_module` / `web/modules/custom/my_module`).
2. A specific **custom theme / subtheme** (e.g. `my_theme` / `web/themes/custom/my_theme`).
3. The **full custom code** (all custom modules, themes, and profiles).

Carry the chosen scope through **both** the audit and the remediation:

- **drush adapter** → `utest:lint --modules=<name,name>` or `--themes=<name,name>`; omit only for full custom code.
- **folder adapter** → restrict tool runs (and any `--fix` passes) to the target directory.
- **accessibility** → the scan crawls rendered pages (no per-module flag); after scanning, only source-map and fix findings that trace to the chosen target's Twig/CSS/config.
- When applying fixes, **never write outside the chosen target**: even if the audit surfaced findings elsewhere, report those separately rather than fixing them.

## Guardrails (MUST)

- **Never commit, never push.** All changes stay in the **working tree** for the user to review (`git diff`) and commit themselves.
- **Dry-run first, then confirm.** Show diffs before writing; get explicit approval. Apply a subset if the user asks.
- **Auto-apply only high-confidence, mechanical, behavior-preserving fixes.** Flag everything else (PHPStan/logic, security/secrets, deprecations, and almost all accessibility judgment fixes) for human review.
- **Custom code only.** Never edit core, contrib, `vendor/`, or `node_modules/`; skip accessibility findings attributed to upstream.
- **Don't silence checks** (baselines/ignores/suppressions) unless explicitly asked.

## 1. Audit first

Get an enriched audit (fixability decided by the read-only diagnostic agent):

- Code-quality → **drupal-code-quality-audit**.
- Accessibility → **drupal-accessibility-audit**.

Choose domain from `{{args}}` (default: whichever has findings; ask if ambiguous).

## 2. Remediate

- Code-quality → **drupal-code-quality-remediation**: partition → dry-run → confirm → apply `phpcbf` / `eslint --fix` / etc. (working tree only).
- Accessibility → **drupal-accessibility-remediation**: source-map each page/selector finding to custom Twig/CSS/content, propose WCAG-correct fixes (reusing the `a11y` agent), confirm per fix (most are judgment calls).

## 3. Re-verify and report

- **Offer to re-run** the relevant tests to confirm findings resolved (before → after). For accessibility, run `drush cr` (and rebuild theme assets) **before** re-running, or the changes won't be visible.
- **Behavior-safety check; run the target's PHPUnit tests after fixing.** Even "mechanical" fixes can shift behavior, so if the target has tests (`<module>/tests/src/{Unit,Kernel}`), run them and report before → after:
  - **Suite present** → `drush utest:phpunit` (or `node tests/phpunit/run.js`); Unit + Kernel, same as CI.
  - **No suite (folder mode)** → the suite runner won't run them, so run directly: copy `web/core/phpunit.xml.dist` → `phpunit.xml`, set `SIMPLETEST_DB` (e.g. `sqlite://localhost/sites/default/files/.ht.sqlite`), then `vendor/bin/phpunit -c web/core <target>/tests`: Unit always; Kernel needs `pdo_sqlite`. See **drupal-phpunit-tests** for the run config.
  - Scope to the target. **Report-only + fail-soft**: a test that now *fails* means the fix changed behavior; surface it and reconsider that fix; an *infra* skip (missing dev deps / `pdo_sqlite`) is not a pass, so say tests couldn't run. If the target has no tests, say so and lean harder on the caveat below.
- **Verification caveat:** a clean re-run confirms the automated checks pass; it does **not** prove behavior is unchanged or that the site is accessible. Recommend running the project's full test suite and exercising the affected functionality.
- **Accessibility, human testing required (always):** screen reader, keyboard-only, and 200% zoom / 320px reflow. Never state the site is "accessible" or "WCAG compliant."
- **End by reminding:** the changes are **uncommitted**: review with `git diff`, then commit and push yourself. This prompt does not commit or push.

## Skills Required

- drupal-code-quality-audit
- drupal-code-quality-remediation
- drupal-accessibility-audit
- drupal-accessibility-remediation
- drupal-phpunit-tests

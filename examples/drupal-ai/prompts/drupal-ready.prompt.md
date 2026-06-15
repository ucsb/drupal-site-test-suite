---
name: drupal-ready
description: Pre-merge readiness check for a Drupal 10/11 site — verifies the branch passes the automated gate (code-quality + accessibility audits + functional/regression tests) AND reviews the changed custom code against Drupal coding standards, then gives a go/no-go verdict. Read-only; makes no changes.
argument-hint: "Optional: a target (module/theme name or path), a scope (gate | review | all), and/or an input (report path/URL). Defaults: all, branch delta vs the base."
---

You just finished work and want to know it's ready to merge. Run a **read-only pre-merge readiness check** and end with a clear **GO / NO-GO** — verifying two things: the branch **passes the CI gate**, and the changed code is **well-built to Drupal standards**. Do **not** change, commit, or push anything. Arguments: `{{args}}`.

## 1. Establish scope and coverage

- **Review scope** = the branch's **changed custom code** — the delta you're about to merge (`git diff <base>...HEAD`, base usually `main`). If no base is resolvable, review the touched custom modules/themes. **If `{{args}}` names a target** (a module/theme machine name or path), narrow further to just that — and pass it to the audits (drush `--module=`/`--theme=`, or restrict the folder adapter) so the gate runs against the same scope, not all custom code.
- **Coverage** — **suite + drush present** → best fidelity (`utest` lanes, same as CI); **no suite** → code-quality runs tools directly on custom code (folder adapter), note the lower fidelity. Accessibility needs a reachable `BASE_URL`. Tests: the suite's Unit + Kernel lane when present, otherwise run the target's own PHPUnit tests directly (folder fallback, step 2) — record when tests exist but can't run.

A near-empty result because tools were skipped is **not** a pass — surface coverage before any verdict. Custom code only — never core/contrib/`vendor/`/`node_modules/`.

## 2. Pass the gate — run the automated checks (read-only)

- **Code-quality** → invoke **drupal-code-quality-audit**.
- **Accessibility** → invoke **drupal-accessibility-audit** (if `BASE_URL` is reachable).
- **Tests** → exercise the custom code's PHPUnit tests. **Linting never runs PHPUnit** (separate lane), so this is its own gate item:
  - **Suite present** → `drush utest:phpunit` (or `node tests/phpunit/run.js`) — the project's Unit + Kernel lane, same as CI.
  - **No suite (folder mode)** → the suite runner won't run them for you, but the tests may still exist. If the target has test classes (`<module>/tests/src/{Unit,Kernel}`), run them **directly**: copy `web/core/phpunit.xml.dist` → `phpunit.xml`, set `SIMPLETEST_DB` (e.g. `sqlite://localhost/sites/default/files/.ht.sqlite`), then `vendor/bin/phpunit -c web/core <target>/tests` — Unit always; Kernel needs the `pdo_sqlite` extension. See **drupal-phpunit-tests** for the layout and run config.
  - Scope to the chosen target. **Report-only + fail-soft**: a genuine test *failure* is a blocker (step 4); an *infra* skip (missing dev deps / `pdo_sqlite`) is not — but **record when tests existed yet couldn't run**, since a skipped lane is not a pass.

## 3. Review the craft — what the tools miss

Review the changed custom code against **drupal-coding-standards** — the issues linters don't flag:

- **DI** — constructor injection, **no `\Drupal::service()` in classes**.
- **Modern idioms** — PHP attributes (not annotations), `#config_target` on config forms, OOP `#[Hook]` where it fits (with the 10.x procedural caveat).
- **Translatable strings** everywhere, including Twig.
- **Naming** — service/route/plugin/permission/config conventions; unique machine-name-derived namespace.
- **Docs & comments** — Doxygen doc-blocks, balanced inline comments, README/doc-file standard.
- **Themes** — inherit framework assets from the base theme, never bundle a duplicate/EOL copy.
- **Secrets** — none hard-coded or committed.

This is a *standards* review, not a deep bug hunt — for correctness/bug review, defer to the built-in **/code-review**.

## 4. Verdict — GO / NO-GO

Lead with **coverage** (what ran vs. skipped, with reasons), then:

- **Blocking (NO-GO):**
  - code-quality findings at **critical** or **serious**;
  - accessibility **legally-required (WCAG 2.1 AA — the Section 504 baseline)** failures;
  - **failing tests**;
  - any review-found **defect** that's security/correctness — e.g. a **hard-coded or committed secret**, an access bypass.
- **Advisory (doesn't block CI, but recommended before merge):** moderate/minor findings, WCAG 2.2 / AAA / best-practice, and the standards-review craft items (DI, comments, naming, an OOP-hook opportunity, etc.).

End with one line — **GO** (no blockers) or **NO-GO — N blocker(s)** — followed by the blocker list, then the advisory list.

## 5. Stop

Read-only — never writes, commits, or pushes. Next steps:

- Fix blockers → **/drupal-remediate** (or the matching remediation skill).
- Address standards items → the relevant skill (`drupal-coding-standards`, `drupal-module-scaffold`, etc.).
- Missing test coverage → **drupal-phpunit-tests**. Deep bug review → **/code-review**.

## Skills Required

- drupal-code-quality-audit
- drupal-accessibility-audit
- drupal-coding-standards
- drupal-phpunit-tests

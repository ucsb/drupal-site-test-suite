---
name: drupal-coding-standards
description: The shared modern coding + documentation standards for custom Drupal 10/11 code — Drupal 10.3+ idioms (constructor property promotion, PHP attributes not annotations, #config_target, OOP #[Hook] implementations, translatable strings, dependency injection), Doxygen doc-blocks and .api.php, README/documentation-file conventions, and secrets handling. Use when writing, editing, or reviewing any custom Drupal module/theme/profile code. The other drupal-* skills (module-scaffold, hook-update-n, phpunit-tests) follow this baseline; cite it rather than duplicating these rules.
metadata:
  version: "2026.06.06"
---

# Drupal Coding Standards

The shared baseline for **custom** Drupal 10/11 code — the modern idioms and documentation conventions every `drupal-*` skill and hand-written change should follow. Vendor-neutral: it targets any Drupal 10/11 site.

Much of this is **enforced automatically** by PHPCS (`Drupal` + `DrupalPractice`) and the `drupal-code-quality-*` skills; this doc is the authoring reference for what those checks expect plus the conventions tools don't catch.

## Prerequisites

- Custom code under `web/modules/custom/`, `web/themes/custom/`, or `web/profiles/custom/` (never edit core/contrib).
- `drupal/coder` installed for PHPCS to apply the Drupal standard.

## When To Use

- Writing or editing any custom Drupal module/theme/profile code.
- Reviewing custom code for standards compliance.
- As the cited baseline from `drupal-module-scaffold`, `drupal-hook-update-n`, `drupal-phpunit-tests`, and the remediation skills.

## The standards

- **Modern PHP/Drupal idioms** (target Drupal 10.3+, DI, attributes, `#config_target`, OOP `#[Hook]`, translatable strings, namespacing, naming conventions, and cacheability) → `information/code-standards.md`
- **Documentation & comments** (Doxygen doc-blocks, `.api.php`, comment density, `hook_help()`, README/doc-file conventions) → `information/documentation.md`

## Verifying compliance (this skill authors; other skills run the checks)

This skill defines *how to write* the code — it does **not** run any checks itself. To verify code meets these standards, use the QA skills and the test suite:

- **Code quality / static analysis:** `drupal-code-quality-audit` → `drupal-code-quality-remediation` (or `drush utest:lint`) — PHPCS `Drupal`+`DrupalPractice`, PHPStan, deprecations, and more.
- **Accessibility of rendered output (WCAG):** `drupal-accessibility-audit` → `drupal-accessibility-remediation` (or `drush utest:a11y:*`). For accessible markup patterns, follow the `a11y` agent.
- **Behavior (works + stays working):** write Unit/Kernel tests with `drupal-phpunit-tests`; run them via the suite (`/tests/phpunit/run.js` / `drush utest:phpunit`).

Author to this standard; verify with those.

## Secrets — never in code

Never hard-code or commit secrets (API keys, tokens, passwords). Read them at runtime via the **Key module** (`key.repository`, injected through DI), the environment, or config — never a literal in PHP/YAML/JS. The `drupal-code-quality-*` suite detects committed secrets (`gitleaks`) and treats them as `critical`/`security` (remove **and rotate**). Provisioning secrets is **hosting-specific** (e.g. Pantheon Secrets) — see the `drupal-secrets-management` skill.

## Development

Maintenance guidance (keeping the standards current with Drupal releases, vendor-neutrality) → `development/development-instructions.md`

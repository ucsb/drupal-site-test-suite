# Changelog

All notable changes to this test suite are documented in this file.

<!--
Workflow:
- Accumulate changes under Unreleased as you merge them.
- To cut a release, move the Unreleased entries under a new version heading
  (e.g. ## 1.1.0), then leave Unreleased empty above it.
- Bump the matching "version" field in package.json in the same commit.
-->

## Unreleased

*Nothing yet. Changes accumulate here until the next release.*

## 1.1.0

### Added

- **Drupal AI tooling examples:** copy-in Claude Code / GitHub Copilot **skills**,
  **agents**, and **prompts** for auditing and remediating a site's custom code,
  scaffolding modules/themes, writing PHPUnit tests, and managing config/secrets,
  under `examples/drupal-ai/` with a README covering install (no external CLI
  required) and usage. They pair with the `drush utest:*` suite: the suite
  produces findings, the skills read, prioritize, and remediate them.

### Changed

- Converted the `drush utest:*` commandfiles from annotated-command docblocks
  (`@command`, `@option`, `@usage`, `@bootstrap`) to PHP attributes
  (`#[CLI\Command]` etc.). No change to command names, aliases, options, or
  behavior; removes the Drush 12 deprecation and keeps the suite compatible
  with Drush 13.
- Replaced the deprecated `FileSystemInterface::EXISTS_REPLACE` constant with
  the `FileExists::Replace` enum in the report commands (deprecated in
  Drupal 10.3, removed in Drupal 12). No behavior change.

## 1.0.0

### Added

- Initial release of the Drupal 10/11 test suite, with every check exposed
  as a `drush utest:*` command and aggregated into one unified report. The suite
  groups its checks into four areas: Accessibility, Code Quality, Security, and
  Functional / Regression.
- **Orchestration & commands:** `drush utest:all` runs the full suite (lint →
  PHPUnit → accessibility lanes → report render) in order; `utest:lint` runs all
  code-quality engines in one pass (with `--module` / `--theme` narrowing);
  setup commands `utest:js-install`, `utest:browsers`, and `utest:check-config`
  (PASS / WARN / FAIL pre-flight); and `utest:report-render` to refresh the
  unified report after a single-lane run.
- **Accessibility lanes:** Siteimprove Alfa (key pages + full-site sitemap walk),
  axe-core (key pages + full-site), optional axe Developer Hub watcher,
  pa11y, reflow at 320 px (WCAG 2.1 SC 1.4.10), and meta-viewport zoom-blocking
  check (WCAG 2.0 SC 1.4.4). These lanes crawl public-facing pages reachable
  without logging in; pages behind a login are not covered in this version.
- **Accessibility profiles & gating:** selectable `strict` / `standard` /
  `comprehensive` / `custom` profiles (`comprehensive` is the CI default for
  full visibility) plus severity filtering — merge gating blocks on
  `critical` + `serious` only.
- **Code-quality lanes:** PHPCS (Drupal + DrupalPractice), PHPStan (baseline-managed),
  ESLint (Drupal Core config), cspell, TwigCS + HTMLHint, Stylelint
  (+ `stylelint-plugin-a11y`), yaml-lint, editorconfig-checker, markdownlint-cli2,
  and actionlint (+ shellcheck), orchestrated in one pass via `drush utest:lint`.
- **Compatibility / hygiene lanes:** deprecation + next-major readiness
  (`@deprecated` usage, `core_version_requirement`), reference resolution
  (library assets, `attach_library()` refs, Twig asset paths), config-install
  hygiene (no `uuid` / `_core` in shipped config), and permission baseline
  (security-sensitive permissions declare `restrict access: true`).
- **Functional / regression lane:** custom-module PHPUnit (Unit + Kernel) via
  `drush utest:phpunit` over `web/modules/custom` + `web/profiles/custom` test
  classes — core and contrib are never run. Report-only and fail-soft: skips
  when dev dependencies are absent, runs Unit-only without `pdo_sqlite`, and
  runs the same way in CI via `node tests/phpunit/run.js` (no Drupal bootstrap).
- **Security lanes:** `composer validate --strict` (composer.json schema +
  lock drift), `composer audit` (known CVEs in PHP dependencies), gitleaks
  committed-secret scanning, and Dependabot (grouped weekly/monthly dependency
  PRs, configured in `.github/dependabot.yml`).
- **Unified report:** aggregates every engine into one filterable view (Rules /
  Impact / Severity chips, collapsible groups, per-finding accordions), links to
  per-tool detail reports, and shows friendly Alfa rule titles resolved in-process
  from the official `@siteimprove/alfa-rules` SDK — no network calls or API key.
- **Portability & configuration:** three-layer custom-code path discovery
  (`composer.json` installer-paths → `custom-paths.json` → `*.info.yml`
  autodiscovery), per-site cspell additions via `site-words.txt`, and graceful
  degradation — a missing tool skips only its own lane with a warning while the
  rest of the run continues. Contrib, core, `vendor`, and `node_modules` are
  never scanned.
- **Example CI integration:** ready-to-copy pipelines for GitHub Actions, GitLab
  CI, and CircleCI, plus a full Pantheon multidev reference, under
  `examples/ci/`. CI is optional and host-agnostic: it runs the lanes directly
  against a reachable site URL (`drush utest:*` is the local entry point).

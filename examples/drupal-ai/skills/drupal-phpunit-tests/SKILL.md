---
name: drupal-phpunit-tests
description: Write PHPUnit Unit and Kernel tests for custom Drupal modules — service behavior, classification/transition logic, config defaults, entity validation, access logic, route requirements, CSV/export ordering, uninstall cleanup. Use when adding or improving test coverage for custom code, especially to protect behavior before an upstream/shared change is pushed across downstream sites. Test classes live inside the module under test. Vendor-neutral; follows drupal-coding-standards.
metadata:
  version: "2026.06.06"
---

# Drupal PHPUnit Tests

Writes **Unit** and **Kernel** PHPUnit tests for custom Drupal modules. The payoff: tests pin down behavior so a change in an upstream/shared codebase can't silently break it across many downstream sites — write the test *before* the change ships.

Follows **`drupal-coding-standards`** (Doxygen doc-blocks, translatable strings, constructor DI in the classes under test). This skill covers *writing tests*; running/QA is the test suite (`/tests/phpunit/run.js`, `drush utest:phpunit`) and `drupal-code-quality-audit`.

## Prerequisites

- A custom module with logic worth testing (a service, plugin, form, access handler, etc.).
- Drupal's dev dependencies installed (`phpunit`, `drupal/core-dev`); SQLite/pdo for Kernel tests.

## When To Use

- Adding coverage for custom-module logic (services, classification, transitions, validation, access).
- Locking in behavior before a rename/refactor/upgrade (regression protection).
- Writing the regression test that ships with a bug fix.

For the **update-path** test that proves a `hook_update_N` works, use `drupal-hook-update-n` (it uses `UpdatePathTestBase`). For *running* tests, use the suite.

## Scope — confirm the target module first (don't fan out)

Before writing anything, establish **which module** you're covering. Default to the **single custom module the user is working on**, not every custom module on the site:

- If the user named a module (or the working context is clearly one module), target just that one.
- If it's ambiguous — or the request sounds broad ("add test coverage") — **ask which module/component (and which classes/behaviors) to cover** rather than generating tests across all custom code. A site-wide test-writing pass is rarely what's wanted and buries the change under review.
- Widen to multiple modules only when the user explicitly asks.

State the resolved target (the module + the classes/behaviors in scope) before writing the first test.

## Where tests live (placement rule — important)

Test **classes live inside the module under test**, never in the top-level `tests/` dir:

- **Custom module:** `web/modules/custom/<module>/tests/src/{Unit,Kernel}/`
- **Module nested under a custom profile:** `web/profiles/custom/<profile>/modules/<module>/tests/src/{Unit,Kernel}/`
- **The profile itself:** only an optional **Functional** install/integration test at `web/profiles/custom/<profile>/tests/src/Functional/` — profiles rarely hold unit-testable logic; their *modules* do.

The top-level **runner stays at `/tests/phpunit/run.js`** — it discovers and executes the in-module tests; it is not where test classes go.

PSR-4: namespace `Drupal\Tests\<module>\{Unit,Kernel}\…` maps to `<module>/tests/src/{Unit,Kernel}/…`. Every test class has `@group <module>`.

## Unit vs Kernel — pick the lightest that works

- **Unit** (`Drupal\Tests\UnitTestCase`): pure logic, **no Drupal bootstrap, no DB**. Fast. For algorithms, value objects, pure service methods — mock collaborators. If a test needs the container, an entity, or config, it's not a Unit test.
- **Kernel** (`Drupal\KernelTests\KernelTestBase`): boots a minimal container + DB. For services that use real Drupal APIs, config, entities, schema. Declare `$modules`, `installConfig()` / `installEntitySchema()` as needed.

Writing patterns and examples → `information/unit-and-kernel.md`. What to cover (and coverage targets) → `information/what-to-test.md`.

## Preconditions to run the tests (local &amp; pipeline)

What each level needs (each row adds to the ones above):

- **Unit** — lowest bar: PHP + Composer **dev** dependencies (`drupal/core-dev` brings PHPUnit). No DB, no web server, no bootstrap — runs anywhere.
- **Kernel** — adds a **test database**: the `pdo_sqlite` PHP extension (SQLite is simplest) and `SIMPLETEST_DB` set in `phpunit.xml`. No web server.
- **Functional** (`BrowserTestBase`) — adds a **running site**: `SIMPLETEST_BASE_URL` + a web server serving the install, plus the DB above.
- **FunctionalJavascript** — adds a **WebDriver** (ChromeDriver/Chromium) reachable via `MINK_DRIVER_ARGS_WEBDRIVER`.

Required packages &amp; modules (install before running):

- **`drupal/core-dev`** (a `require-dev` dependency) is the package that makes tests runnable — it provides PHPUnit, the base classes (`UnitTestCase`, `KernelTestBase`, `BrowserTestBase`), Prophecy, and Mink/WebDriver for Functional(JS). Install with `composer require --dev drupal/core-dev`. There is **no separate "test" module to enable** — the framework lives in Drupal core + `core-dev`.
- **The module under test and its dependencies** must be present in the codebase (composer-required) so a Kernel test can enable them via `$modules` and a Functional test can install them — including any **contrib dependencies** (e.g. `key`, `token`, `pathauto`). A Kernel test fails if a module in `$modules` isn't installed.
- For **FunctionalJavascript** only: a running **ChromeDriver/Chromium** (the browser binary isn't a Composer package).

Then (same locally and in CI):

1. `composer install` **with dev dependencies** (don't use `--no-dev`).
2. PHPUnit config: copy `web/core/phpunit.xml.dist` → `web/core/phpunit.xml`; set `SIMPLETEST_DB` (e.g. `sqlite://localhost/sites/default/files/.ht.sqlite`) and, for Functional tests, `SIMPLETEST_BASE_URL`.
3. PHP extensions: `pdo_sqlite` (Kernel/Functional), plus `dom`/`xml`.

The project runner **`/tests/phpunit/run.js`** wraps this — it does its own preflight (dev deps + `pdo_sqlite`) and runs the custom-module Unit/Kernel tests the same way locally and in the pipeline, so you usually invoke it (or `drush utest:phpunit`) rather than raw PHPUnit. On a site that ships the test suite, this lane runs **Unit + Kernel only** (Functional/FunctionalJavascript need a deployed site and are excluded), is **report-only** (failures are flagged, never break the build), and is **fail-soft** (skips cleanly when dev deps aren't installed; runs Unit-only when `pdo_sqlite` is unavailable, since Kernel uses SQLite — no MySQL or site install needed). If the site ships the suite, its own `tests/README.md` documents the exact commands.

Prefer **Unit** (almost no preconditions); reach for Kernel/Functional only when the behavior needs the container/DB/browser.

## Guardrails

- **Custom code only** — test custom modules/themes/profiles; never core/contrib.
- **Never commit, never push.** Leave new test files in the working tree for review.
- **Deterministic** — no reliance on wall-clock, network, randomness, or test order. Each test sets up its own state.
- **One behavior per test method**, named for what it asserts; use data providers for input variations.
- **Test behavior, not implementation** — assert outcomes, not private internals.

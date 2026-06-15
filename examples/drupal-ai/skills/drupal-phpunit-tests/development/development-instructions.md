# Development — maintaining drupal-phpunit-tests

## Keep it vendor-neutral

Works for any Drupal 10/11 site. Examples use neutral names (`my_module`, `MyService`, `Classifier`) — no real module names, sites, or CI identifiers.

## Keep it current with Drupal

- Re-check each cycle: `UnitTestCase` / `KernelTestBase` base classes and setup helpers (`installConfig`, `installEntitySchema`, `installSchema`), PSR-4 test discovery, `@group`/data-provider conventions, PHPUnit major versions shipped with `drupal/core-dev`.

## Stay in your lane (cross-skill boundaries)

- **Update-path tests** (`hook_update_N` via `UpdatePathTestBase`) → `drupal-hook-update-n`.
- **New module + its `tests/` skeleton** → `drupal-module-scaffold`.
- **Coding/doc standards** (Doxygen, DI, translatable strings) → `drupal-coding-standards`.
- **Running tests / QA** → the test suite (`/tests/phpunit/run.js`, `drush utest:phpunit`) and `drupal-code-quality-audit`. This skill only *writes* tests.

## Structure

`SKILL.md` (navigational: placement rule, Unit vs Kernel, guardrails) + `information/` (`unit-and-kernel.md`, `what-to-test.md`) + this `development/` file.

## Placement rule is load-bearing

Always reinforce: test classes live **in the module under test** (`<module>/tests/src/{Unit,Kernel}`, profile-nested modules under the profile), the top-level `/tests/phpunit/run.js` runner only discovers them, and the profile root gets only an optional Functional install test.

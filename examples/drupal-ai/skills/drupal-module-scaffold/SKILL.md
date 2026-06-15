---
name: drupal-module-scaffold
description: Scaffold a new Drupal 10/11 custom module from scratch — `.info.yml`, `.module`, `.install`, `.services.yml`, `.routing.yml`, `.permissions.yml`, `src/` service layout, `Plugin/` directories, `tests/` boilerplate, `composer.json`, `README.md`. Follows Drupal + DrupalPractice coding standards from the start, declares D10/D11 compatibility, and uses proper dependency injection (no `\Drupal::service()` in classes). Use when creating a new custom module, consolidating modules under a new name, or starting a module destined for drupal.org.
metadata:
  version: "2026.06.06"
---

# Drupal Module Scaffold

Produces a clean, coding-standards-compliant starting point for a new custom Drupal module under `web/modules/custom/<area>/<module_name>/` (or a site-level `web/modules/custom/<module_name>/`). The goal: a module that passes a standard Drupal CI gate (PHPCS `Drupal`/`DrupalPractice`, PHPStan, `drupal-check`, PHPUnit) from the first commit.

This skill is **vendor-neutral** — it works for any Drupal 10/11 site. Where it says "your project," substitute your org/site conventions (machine-name prefix, package name, CI ticket prefix).

All generated code follows the **`drupal-coding-standards`** skill (modern Drupal 10.3+ idioms, Doxygen/comments, documentation-file conventions, DI, secrets) — see it for the full rules. This skill covers scaffold-specific structure and ready-to-fill templates that embody those standards.

## Prerequisites

- A Drupal 10/11 codebase you can write to (`web/modules/custom/` exists or can be created).
- Composer-installed dev tooling for the pre-commit checks (`drupal/coder` for PHPCS, PHPStan, optionally `drupal-check`).
- Decide the module's machine name and location before writing (see Pre-flight).

## When To Use

- Creating a new custom module (shared/upstream or site-level).
- Starting a module that will be contributed to drupal.org.
- Consolidating older modules under a new name — pair with **`drupal-hook-update-n`** for the rename/migration path.
- **Not** for adding a hook to an existing module — just edit that module.

## Pre-flight checks (before writing any file)

- **Namespace uniqueness is the worst preventable mistake.** A module's PHP namespace is `Drupal\<module_name>\…`, derived **directly from its machine name** — so the machine name must be unique not just on your site but against **contrib**. Two modules with the same machine name can't coexist, and a name that shadows a contrib module's namespace causes autoloading/identity conflicts. Confirm it's free on drupal.org (`https://www.drupal.org/project/<name>`) and Composer (`composer show drupal/<name>`).
- **Prefix the machine name** to your project/org (e.g. `myorg_<name>`) so the derived namespace `Drupal\myorg_<name>\…` — and the `drupal/<name>` package name — are guaranteed distinct from any contrib module. Never ship single-word names (`events`, `media`, `search`, `people`) — they collide with contrib.
- **Pick the location:** a shared area `web/modules/custom/<area>/<name>/`, or a site-level `web/modules/custom/<name>/`.
- **Pick the module type:** pure-service (no UI), block-provider, route-provider, entity-provider, plugin-provider, or a combination — this decides which files you add.

## Minimum file set (every module)

```text
<module_name>/
├── <module_name>.info.yml          # required — module metadata
├── <module_name>.module            # procedural hooks, kept thin
├── README.md                       # what, why, install, configuration, maintainer
└── tests/src/Unit/<ModuleName>Test.php   # one placeholder test so CI has something to run
```

Add `composer.json` only if the module ships via Composer (contrib) or pulls its own deps. Add everything else **only when the module needs it** — see the decision table.

## File templates and layout

Load the templates only for the files this module actually needs:

- All file templates (`.info.yml`, `.module`, `.install`, `.services.yml`, `.routing.yml`, `.permissions.yml`, `config/`, `composer.json`, `README.md`) → `information/file-templates.md`
- `src/` class layout, namespacing/DI rules, and `tests/` structure → `information/src-and-tests.md`

For **writing real tests** (not just the placeholder), use the **`drupal-phpunit-tests`** skill. For **schema/config/data migrations** and renames, use **`drupal-hook-update-n`**.

## When to add what (decision table)

| Module provides | Files to add |
| --- | --- |
| Just a hook in `.module` | `.info.yml`, `.module`, README |
| A service | + `src/Service/`, `.services.yml`, unit test |
| An admin form | + `src/Form/SettingsForm.php`, `.routing.yml`, `.permissions.yml` |
| A block | + `src/Plugin/Block/`, kernel test |
| An entity | + `src/Entity/`, `src/Access/`, `config/schema/`, kernel + functional tests |
| An editor/JS plugin | + `js/` sources + build output, build config, `.libraries.yml` |
| A migration source | + `src/Plugin/migrate/source/`, kernel test with fixture |
| An event subscriber | + `src/EventSubscriber/`, `.services.yml` tagged `event_subscriber` |

## Coding-standards checklist (before first commit)

Run against the new module path; all must be clean before merge (your CI — e.g. the `drupal-code-quality-*` skills / `utest:lint` — enforces the same on PR):

- `vendor/bin/phpcs --standard=Drupal,DrupalPractice <path>` — zero errors.
- `vendor/bin/phpstan analyse <path>` — no findings above the project baseline.
- `vendor/bin/drupal-check <path>` — no deprecated APIs (forward-compat for D10→D11).
- `vendor/bin/phpunit -c web/core/phpunit.xml.dist <path>` — tests pass.

## Comments & readability

Generated code must be well-commented and clear to other developers — see **`drupal-coding-standards`** (`information/documentation.md`) for the Doxygen doc-block and inline-comment rules. The template stubs model the expected comment density.

## Git operations

- Do **not** commit unless the user explicitly asks; **never push**.
- Scaffold + initial tests should land in one commit per module.
- Subject line: `Add <module_name> module` (≤72 chars); use your project's ticket/commit prefix.

## Anti-patterns to avoid

- Single-word machine names — always prefix.
- `\Drupal::service()` inside class methods — use constructor DI.
- Config without `config/schema/` — untestable; fails CI.
- A `version:` line in `.info.yml` — Composer/Drush derive it from the tag.
- `hook_install()` doing real work that belongs in `config/install/` — use `hook_install` only for state core can't express as config.
- Shipping without tests "because CI will catch it" — CI needs tests to run.

## Development

Maintenance guidance for this skill (keeping templates current with Drupal API changes, the genericization rules) → `development/development-instructions.md`

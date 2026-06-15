# Development — maintaining drupal-secrets-management

## Vendor-neutral core, hosting-specific providers

The core (never-commit, Key module, read via DI) is vendor-neutral and must stay so. Hosting specifics live **only** in the providers section as clearly-labeled options (Pantheon Secrets, env vars, file-based). Add a new provider as its own subsection; don't make the core skill assume any one host.

## Keep it current

- Re-check the Key module API (`KeyRepositoryInterface`, key types/providers), `pantheon_secrets` + Terminus `secret:site:set` syntax, and any new managed-secret providers each cycle.

## Boundaries (cross-skill)

- **Detecting committed secrets** (gitleaks) and remove-and-rotate handling → `drupal-code-quality-*` (this skill is about *not leaking* them in the first place).
- **DI / no static `\Drupal::` in classes** → `drupal-coding-standards`.
- **Mocking keys in tests** → `drupal-phpunit-tests` (never a real secret in a fixture).

## Structure

`SKILL.md` (core principle + guardrails) + `information/` (`key-module.md`, `providers.md`) + this `development/` file.

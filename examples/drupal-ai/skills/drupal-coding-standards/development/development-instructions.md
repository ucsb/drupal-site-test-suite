# Development: maintaining drupal-coding-standards

This skill is the **shared baseline** the other `drupal-*` skills cite. Keep it the single source of truth for cross-cutting standards.

## Keep it vendor-neutral

Works for any Drupal 10/11 site. No site/CI/host specifics here; hosting-specific concerns (e.g. Pantheon Secrets provisioning) belong in their own skill (`drupal-secrets-management`), which this doc only points to.

## Keep it current with Drupal

- Revisit each major/minor cycle: the supported-minor baseline (`^10.3 || ^11`), `#[Hook]` availability (D11.1+), attribute vs annotation status, `#config_target`, DI/service signatures.
- When Drupal shifts a recommended idiom, update here once, the other skills inherit it by reference.

## Boundaries (don't absorb other skills' jobs)

- **Authoring only**: this skill says *how to write* code; it does not run checks. QA execution lives in `drupal-code-quality-*` (code quality), `drupal-accessibility-*` (WCAG), and `drupal-phpunit-tests` (behavior).
- **No file templates**: ready-to-fill templates live in `drupal-module-scaffold`. This skill states the rules; scaffold embodies them.

## Who cites this

`drupal-module-scaffold`, `drupal-hook-update-n`, `drupal-phpunit-tests`, and the remediation skills reference this baseline instead of duplicating it. When adding a new cross-cutting standard, add it here and link from those.

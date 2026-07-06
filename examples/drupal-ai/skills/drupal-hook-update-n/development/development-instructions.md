# Development: maintaining drupal-hook-update-n

## Keep it vendor-neutral

Works for any Drupal 10/11 site. Examples use neutral names (`my_module`, `old_module`/`new_module`, `oldtheme`/`newtheme`); no real site/module/person/roadmap or CI identifiers. Generic "downstream sites" / "your CI," never a specific project's phases or scale.

## Keep it current with Drupal

- Re-check each cycle: `hook_update_N` vs `hook_post_update_NAME` semantics, `UpdatePathTestBase`, entity-definition-update-manager APIs, the numbering convention.
- Update hooks are **procedural** in `.install`; static `\Drupal::` calls are normal there (unlike classes). Keep that distinction clear when `drupal-coding-standards` changes.

## Stay in your lane (cross-skill boundaries)

- **Scaffolding a new module** → `drupal-module-scaffold`.
- **Modern coding/doc standards** → `drupal-coding-standards` (this skill cites it; don't duplicate the rules).
- **General Unit/Kernel test conventions** → `drupal-phpunit-tests` (this skill covers only the update-path/`UpdatePathTestBase` test).

## Structure

`SKILL.md` (navigational: when/when-not, numbering, idempotency, update-vs-post-update, cache, checklist, anti-patterns) + `information/` (`patterns.md`, `testing.md`) + this `development/` file.

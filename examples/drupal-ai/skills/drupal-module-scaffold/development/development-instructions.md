# Development — maintaining drupal-module-scaffold

Guidance for keeping this skill correct over time.

## Keep it vendor-neutral

This skill must work for **any** Drupal 10/11 site. Don't reintroduce site-specific details:

- No specific CI/ticket identifiers — say "your CI" / "your project's ticket prefix."
- No hard-coded machine-name prefixes, package names, or area folders — use placeholders (`<YourOrg>`, `<area>`, `myorg_*`) and describe the rule.
- No links to a particular site's roadmap or maintainer list.

## Keep templates current with Drupal

- Recommended `core_version_requirement` is **`^10.3 || ^11`** — Drupal 10.0–10.2 are end-of-life and 10.3 is the 10.x LTS, so don't claim support for the EOL minors. Update this if the supported-minor baseline shifts.
- Re-check the templates against the latest Drupal 10/11 API each major cycle: DI service IDs, `hook_help()` signature, plugin discovery paths.
- When Drupal deprecates an API used in a template, update the template and note the change.

## Stay in your lane (cross-skill boundaries)

- **Writing real tests** → `drupal-phpunit-tests`. This skill only ships the placeholder test + the `tests/` layout.
- **Schema/config/data migrations, renames, `hook_update_N`** → `drupal-hook-update-n`.
- **Auditing/fixing existing code quality** → `drupal-code-quality-audit` / `drupal-code-quality-remediation`.

Keep this skill focused on *scaffolding a new module's structure*; link out rather than duplicating those concerns.

## Structure

Follow the repo layout: `SKILL.md` (navigational) + `information/` (the templates and layout reference) + this `development/` file. Keep `SKILL.md` high-signal; put depth in `information/`.

# Development: maintaining drupal-theme-scaffold

## Keep it vendor-neutral

Works for any Drupal 10/11 site. Templates use neutral placeholders (`<theme_name>`, `<Human Name>`, `<YourOrg>`); no real theme names, site, or org branding.

## Keep it current with Drupal

- Re-check each cycle: the Starterkit generator command/flags (`php core/scripts/drupal generate-theme`), `.info.yml` keys (regions, `libraries-override`/`-extend`, `ckeditor5-stylesheets`), SDC (`*.component.yml`) schema, breakpoints format, and which base themes are deprecated.
- When core deprecates a theme API (e.g. classy/stable lineage changes), update the base-theme guidance.

## Stay in your lane (cross-skill boundaries)

- **CSS/JS/Twig standards** (BEM, tokens, `Drupal.behaviors`, translatable strings) → `drupal-coding-standards`. This skill provides theme structure + templates; link out for the rules.
- **A module's front-end** (a module shipping its own CSS/JS/Twig) → `drupal-module-scaffold`.
- **Fixing an existing theme** → `drupal-accessibility-remediation` (a11y) / `drupal-code-quality-remediation` (lint). This skill only scaffolds new themes.

## Structure

`SKILL.md` (navigational: pre-flight, Starterkit flow, decision table) + `information/` (`file-templates.md`, `structure.md`) + this `development/` file.

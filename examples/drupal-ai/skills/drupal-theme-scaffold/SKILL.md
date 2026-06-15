---
name: drupal-theme-scaffold
description: Scaffold a new custom Drupal 10/11 sub-theme from scratch — theme `.info.yml` (base theme, regions, libraries), `.libraries.yml`, `.breakpoints.yml`, `.theme` preprocess file, `templates/`, `theme-settings.php`, logo + screenshot, and CSS/JS wired through libraries. Prefers Drupal's Starterkit generator. Use when creating a new custom theme or sub-theme, not when editing an existing one. Vendor-neutral; follows drupal-coding-standards.
metadata:
  version: "2026.06.06"
---

# Drupal Theme Scaffold

Produces a clean, standards-compliant starting point for a new custom Drupal **theme / sub-theme** under `web/themes/custom/<theme_name>/`. The sibling to `drupal-module-scaffold` — modules provide behavior, themes provide the rendered front-end (markup, CSS, JS, regions).

Vendor-neutral; works for any Drupal 10/11 site. All generated CSS/JS/Twig follows **`drupal-coding-standards`** (BEM-ish naming, design tokens, `Drupal.behaviors` + `once`, translatable strings in Twig) — see it for the rules; this skill provides theme-specific structure and templates.

## Prerequisites

- A Drupal 10/11 codebase you can write to (`web/themes/custom/` exists or can be created).
- For the recommended Starterkit flow: CLI access to run `php core/scripts/drupal generate-theme`.
- Node tooling if the theme builds CSS/JS (stylelint/ESLint per the suite).

## When To Use

- Creating a new custom theme or sub-theme.
- Standing up the theme skeleton before building components.
- **Not** for editing an existing theme — just edit it. For *fixing* a theme's accessibility/CSS/JS, use `drupal-accessibility-remediation` / `drupal-code-quality-remediation`.

## Pre-flight (before writing any file)

- **Machine name uniqueness.** A theme's machine name must be unique against contrib themes (it's the namespace for its config, libraries, and template hooks). Prefix to your org (e.g. `myorg_<name>`); confirm it's free on drupal.org and Composer. Never ship single-word names that collide with contrib (`base`, `theme`, `bootstrap`).
- **Pick the base.** Two modern options:
  - **Starterkit (recommended).** `php core/scripts/drupal generate-theme <theme_name> --name "<Human Name>"` generates a **standalone** sub-theme by copying the current stable templates/assets — so there's *no runtime base-theme dependency* to track across core upgrades. This is the Drupal 10/11-recommended path.
  - **`base theme:`** an explicit base (a design-system or **CSS-framework base theme** — e.g. a Bootstrap base like Bootstrap Barrio or Radix, or `stable9`) when you intend to inherit and override it. **Inherit the framework's assets from the parent — never bundle your own duplicate copy** (see *Framework sub-themes* below). Avoid basing new work on the deprecated `classy`.
- **Decide scope:** which regions, whether you ship breakpoints (responsive images), whether you need theme settings, and whether you'll use **Single-Directory Components** (SDC, stable in 10.3+) for reusable UI.

## Recommended flow

```bash
# From the Drupal root — generates web/themes/<theme_name>/ as a standalone sub-theme
php core/scripts/drupal generate-theme <theme_name> --name "<Human Name>"
# then move it under custom/ to match installer-paths:
mv web/themes/<theme_name> web/themes/custom/<theme_name>
```

Then refine the generated files using the templates here (set `core_version_requirement`, trim regions, wire your libraries, add breakpoints/settings as needed). If you can't run the generator, hand-build from `information/file-templates.md`.

## Minimum file set (every theme)

```text
<theme_name>/
├── <theme_name>.info.yml          # required — theme metadata, regions, libraries
├── <theme_name>.libraries.yml     # define the theme's CSS/JS libraries
├── css/                           # stylesheets (attached via a library)
├── logo.svg                       # theme logo
├── screenshot.png                 # 588×438 — shown on the Appearance page
└── README.md
```

Add `.theme`, `.breakpoints.yml`, `templates/`, `theme-settings.php`, `js/`, and `components/` (SDC) **only when needed** — see the decision table.

## File templates and structure

- All file templates (`.info.yml`, `.libraries.yml`, `.breakpoints.yml`, `.theme`, Twig, `theme-settings.php`, CSS/JS, README) → `information/file-templates.md`
- Directory layout, regions, library attachment, and SDC components → `information/structure.md`

## When to add what (decision table)

| Theme provides | Files to add |
| --- | --- |
| Just CSS/JS over the generated markup | `.info.yml`, `.libraries.yml`, `css/`, README |
| Custom markup (template overrides) | + `templates/**`, often `.theme` for preprocess/suggestions |
| Responsive images | + `.breakpoints.yml` |
| Theme settings (logo toggle, custom options) | + `theme-settings.php`, `config/install/<theme>.settings.yml`, `config/schema/<theme>.schema.yml` |
| Reusable UI components | + `components/<name>/<name>.component.yml` (+ twig/css/js) — Single-Directory Components |
| Library tweaks to base/contrib assets | `libraries-override:` / `libraries-extend:` in `.info.yml` |

## Framework sub-themes & keeping bundled assets secure

If the sub-theme builds on a CSS-framework base theme (Bootstrap, Foundation, etc.):

- **Inherit, don't duplicate.** Pull the framework's CSS/JS **from the parent/base theme's libraries** (via `base theme:` + `libraries-extend` / `libraries-override`). A sub-theme that ships its *own* copy of the framework next to a parent that already provides it causes **double-loading** and — worse — a **second copy you must separately patch**.
- **Don't vendor an EOL / unpatched library.** Committing a frozen framework copy (an end-of-life Bootstrap 3, an old jQuery) drags its known CVEs along until *you* patch them. Prefer a maintained base theme, or the library via Composer / CDN-with-SRI, kept current.
- **Verify the sub-theme is set up correctly** before shipping:
  - It declares `base theme:` and takes framework assets from the parent — no `bootstrap/` or vendored-framework folder committed in the sub-theme.
  - No duplicate framework library loads (check the page's network panel / the resolved `libraries`).
  - Any third-party asset that *is* vendored is current — not EOL or known-vulnerable.
- **Audit → fix → verify.** Treat framework security like any finding: baseline-scan, fix (move to inheritance, patch, or upgrade), then re-scan to confirm. Version/CVE-specific scanners and patch sets belong with that framework's own repo (they're not vendor-neutral); the general security scan is part of `drupal-code-quality-audit`.

## Coding-standards checklist (before first commit)

Run against the new theme path; all must be clean (your CI / the `drupal-code-quality-*` skills enforce the same):

- `stylelint` (Drupal config) on `css/`/`scss/` — zero errors.
- `eslint` (Drupal config) on `js/` — zero errors.
- `twigcs` on `templates/` — zero errors; markup is accessible (alt, labels, landmarks).
- Strings in Twig are translatable (`{{ 'Text'|t }}` / `{% trans %}`).

## Git operations

- Do **not** commit unless the user explicitly asks; **never push**.
- Scaffold lands in one commit. Subject: `Add <theme_name> theme` (≤72 chars).

## Anti-patterns to avoid

- Single-word machine names — always prefix.
- Basing a new theme on deprecated `classy`/`stable` — use Starterkit or a maintained base.
- Inline `<style>`/`<script>` in templates — attach via `.libraries.yml` (`#attached` / `attach_library`).
- Hard-coded colors instead of design tokens / CSS custom properties (breaks contrast + forced-colors).
- A `version:` line in `.info.yml` — Composer/Drush derive it from the tag.
- Bundling a **duplicate copy of the base theme's framework** (your own `bootstrap/` when the parent already provides it) — double-loads and leaves a second, often unpatched/EOL copy to secure. Inherit from the parent instead.
- Editing core themes or contrib base themes directly — override in your sub-theme.

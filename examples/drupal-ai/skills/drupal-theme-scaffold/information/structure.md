# Theme Structure & Layout

## Directory layout

```text
web/themes/custom/<theme_name>/
├── <theme_name>.info.yml
├── <theme_name>.libraries.yml
├── <theme_name>.breakpoints.yml        # if shipping responsive images
├── <theme_name>.theme                  # if preprocessing / suggestions
├── theme-settings.php                  # if the theme has settings
├── config/
│   ├── install/<theme_name>.settings.yml
│   └── schema/<theme_name>.schema.yml
├── css/                                # base / layout / component / state / theme
├── js/
├── images/
├── templates/                          # Twig overrides, grouped by type
│   ├── layout/    (page, region, html)
│   ├── content/   (node, field, comment)
│   └── navigation/ (menu, breadcrumb, pager)
├── components/                         # Single-Directory Components (optional)
│   └── <name>/<name>.component.yml + .twig + .css + .js
├── logo.svg
├── screenshot.png                      # 588×438
└── README.md
```

## Regions

- Declared in `.info.yml` under `regions:`. The `content` region is **required**: Drupal errors without it.
- Only declared regions can receive blocks. Keep the set lean and meaningful; every region should have a place in `page.html.twig` (`{{ page.<region> }}`) or it's invisible.
- If you omit `regions:` entirely, Drupal applies a default set; but declaring them explicitly is clearer and matches your `page.html.twig`.

## Attaching assets (never inline)

- **Global** CSS/JS → list the library under `libraries:` in `.info.yml`.
- **Per-template / per-component** → attach on demand: `{{ attach_library('<theme_name>/slider') }}` in the Twig file, or `#attached: { library: [...] }` from a preprocess function.
- Never put `<style>`/`<script>` in a template. Libraries give caching, aggregation, and dependency ordering.
- Use `libraries-override` / `libraries-extend` in `.info.yml` to swap or augment inherited/core libraries instead of duplicating them.

## CSS organization

Follow the SMACSS-style buckets that map to the `.libraries.yml` weights; `base` (elements, tokens), `layout` (regions, grids), `component` (reusable UI), `state`, `theme`. Namespace component classes (BEM-ish: `block__element--modifier`); never style by `#id`; reference design tokens / CSS custom properties (see `drupal-coding-standards`).

## Single-Directory Components (SDC, Drupal 10.3+)

For reusable UI, prefer SDC over scattered template+library pairs. A component is a folder under `components/`:

```text
components/card/
├── card.component.yml     # schema: props + slots
├── card.twig
├── card.css
└── card.js
```

Render with `{{ include('<theme_name>:card', { title: node.label }) }}`. SDC bundles the markup, styles, and behavior, validates props against the schema, and auto-attaches its assets, the modern, encapsulated way to build theme UI.

## Base theme vs Starterkit

- **Starterkit-generated (recommended):** a standalone sub-theme; the generator copied the current stable templates into your theme, so there's no live base-theme dependency to track across core upgrades. You own and edit those templates directly.
- **`base theme: <name>`:** you inherit another theme's templates/libraries and override selectively. Use for a shared design-system base. Don't base new work on deprecated `classy`.

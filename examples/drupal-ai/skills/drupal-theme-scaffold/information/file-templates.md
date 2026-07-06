# Theme File Templates

Copy only what the theme needs. Substitute `<theme_name>` (machine name), `<Human Name>`, `<YourOrg>`. All CSS/JS/Twig follows `drupal-coding-standards`.

## `<theme_name>.info.yml` (required)

```yaml
name: '<Human Name>'
type: theme
description: '<One-line description of the theme.>'
package: '<YourOrg>'
core_version_requirement: ^10.3 || ^11

# Starterkit-generated themes are standalone (no runtime base theme):
base theme: false
# ...or inherit + override an explicit base instead:
# base theme: stable9

screenshot: screenshot.png
logo: logo.svg

# Global assets loaded on every page:
libraries:
  - <theme_name>/global

# Optional: swap or extend inherited/core libraries.
# libraries-override:
#   core/normalize: false
# libraries-extend:
#   core/drupal.dialog:
#     - <theme_name>/dialog

# Regions editors can place blocks into. `content` is required.
regions:
  header: 'Header'
  primary_menu: 'Primary menu'
  secondary_menu: 'Secondary menu'
  breadcrumb: 'Breadcrumb'
  highlighted: 'Highlighted'
  help: 'Help'
  content: 'Content'
  sidebar_first: 'Sidebar first'
  sidebar_second: 'Sidebar second'
  footer_top: 'Footer top'
  footer_bottom: 'Footer bottom'

# Stylesheets loaded inside the CKEditor 5 authoring frame:
# ckeditor5-stylesheets:
#   - css/ckeditor5.css
```

No `version:` line; Composer/Drush set it from the release tag.

## `<theme_name>.libraries.yml`

```yaml
global:
  version: VERSION
  css:
    # SMACSS weights: base < layout < component < state < theme.
    base:
      css/base.css: {}
    layout:
      css/layout.css: {}
    component:
      css/components.css: {}
  js:
    js/global.js: {}
  dependencies:
    - core/drupal
    - core/once
    - core/drupalSettings

# A component library attached on demand from a template
# ({{ attach_library('<theme_name>/slider') }}):
# slider:
#   version: VERSION
#   css:
#     component:
#       css/slider.css: {}
#   js:
#     js/slider.js: {}
#   dependencies:
#     - core/once
```

## `<theme_name>.breakpoints.yml` (responsive images)

```yaml
<theme_name>.mobile:
  label: Mobile
  mediaQuery: ''
  weight: 0
  multipliers:
    - 1x
<theme_name>.narrow:
  label: Narrow
  mediaQuery: 'all and (min-width: 560px)'
  weight: 1
  multipliers:
    - 1x
    - 2x
<theme_name>.wide:
  label: Wide
  mediaQuery: 'all and (min-width: 851px)'
  weight: 2
  multipliers:
    - 1x
    - 2x
```

## `<theme_name>.theme`

```php
<?php

/**
 * @file
 * Theme hooks and preprocess functions for the <Human Name> theme.
 */

/**
 * Implements hook_preprocess_HOOK() for html.html.twig.
 */
function <theme_name>_preprocess_html(array &$variables): void {
  // Expose values to the template or add body classes here.
}

/**
 * Implements hook_theme_suggestions_HOOK_alter() for page templates.
 */
function <theme_name>_theme_suggestions_page_alter(array &$suggestions, array $variables): void {
  // e.g. add a bundle-specific page suggestion.
}
```

Theme preprocess functions are procedural (theme layer); `\Drupal::` static calls are acceptable here, as in modules' `.module` hooks.

## `theme-settings.php` (+ config): only if the theme has settings

```php
<?php

/**
 * @file
 * Theme settings for the <Human Name> theme.
 */

use Drupal\Core\Form\FormStateInterface;

/**
 * Implements hook_form_system_theme_settings_alter().
 */
function <theme_name>_form_system_theme_settings_alter(array &$form, FormStateInterface $form_state): void {
  $form['<theme_name>_example'] = [
    '#type' => 'checkbox',
    '#title' => t('Example setting'),
    '#default_value' => theme_get_setting('<theme_name>_example'),
  ];
}
```

Ship defaults in `config/install/<theme_name>.settings.yml` and a typed `config/schema/<theme_name>.schema.yml` (config without schema fails CI).

## `js/global.js`

```js
((Drupal, once) => {
  Drupal.behaviors.<themeNameCamel> = {
    attach(context) {
      once('<theme_name>-init', '[data-example]', context).forEach((element) => {
        // Progressive enhancement; translate strings with Drupal.t().
      });
    },
  };
})(Drupal, once);
```

## `css/base.css` (design tokens)

```css
:root {
  /* Design tokens: reference these instead of hard-coded values so contrast
     and forced-colors hold. */
  --color-text: #1b1b1b;
  --color-link: #0061a8;
  --space: 1rem;
}

body {
  color: var(--color-text);
  font-family: system-ui, sans-serif;
}

a {
  color: var(--color-link);
}
```

## Twig override (`templates/…`)

Place overrides under `templates/` (organize by type; `layout/`, `content/`, `navigation/`). Keep strings translatable:

```twig
{# templates/layout/page.html.twig: minimal example #}
<div class="page">
  <header class="page__header">{{ page.header }}</header>
  <main class="page__content">
    <a id="main-content" tabindex="-1"></a>
    {{ page.content }}
  </main>
  <footer class="page__footer">{{ page.footer_bottom }}</footer>
</div>
```

## `README.md`

Follow the Drupal documentation-file standard in `drupal-coding-standards` (ALL-CAPS filename, initial-cap in-document headers, Unix LF, 80-col wrap; sections: Requirements, Installation, Configuration, Maintainers). Note the base/Starterkit origin and how to rebuild assets.

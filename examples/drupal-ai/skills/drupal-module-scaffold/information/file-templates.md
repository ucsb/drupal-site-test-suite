# File Templates

Copy only the files the module actually needs (see the decision table in `SKILL.md`). Substitute `<module_name>`, `<Human Readable Name>`, `<YourOrg>`, etc. for your project's conventions.

## Modern Drupal coding standards (apply to every template below)

These are why `core_version_requirement` is `^10.3 || ^11` — they all require Drupal 10.3+:

- **Translatable strings.** Every user-facing string must be translatable: `$this->t('…')` in classes, `t('…')` in procedural code, and `{{ 'Text'|t }}` or `{% trans %}…{% endtrans %}` in Twig. Never output a raw UI string — including in template files.
- **Constructor property promotion** for injected dependencies (and use DI, never `\Drupal::service()` in a class):

```php
public function __construct(
  protected readonly EntityTypeManagerInterface $entityTypeManager,
  protected readonly ConfigFactoryInterface $configFactory,
) {}
```

- **PHP attributes, not annotations,** for plugin discovery (Drupal 10.3+):

```php
#[Block(
  id: '<module_name>_example',
  admin_label: new TranslatableMarkup('Example'),
)]
final class ExampleBlock extends BlockBase {}
```

- **`#config_target` on `ConfigFormBase` forms** — bind each element to its config key instead of `#default_value` + a manual save; the parent class persists it (Drupal 10.3+):

```php
$form['toolkit'] = [
  '#type' => 'radios',
  '#title' => $this->t('Image toolkit'),
  '#config_target' => 'system.image:toolkit',
  '#options' => [],
];
// No submitForm() save needed — ConfigFormBase handles it.
```

- **OOP `#[Hook]` implementations** for the latest Drupal — see the hooks section below.

## `<module_name>.info.yml` (required)

```yaml
name: '<Human Readable Name>'
type: module
description: 'One-line description of what the module provides.'
package: '<YourOrg>'
core_version_requirement: ^10.3 || ^11
dependencies:
  - 'drupal:<core_module_if_needed>'
  - 'some_contrib:<contrib_module_if_needed>'
configure: <module_name>.settings_form  # only if the module has a settings form
```

- `core_version_requirement: ^10.3 || ^11` — **always declare both**, even for code you think is D10-only; it keeps the module forward-compatible for the D10→D11 upgrade.
- `package:` — **optional**; it only groups the module under a heading on the Extend (`admin/modules`) page. **For custom/site modules, set it** (e.g. `'Custom'` or your team/org name) so your modules are easy to find among core/contrib. **For a module destined for drupal.org (contrib), omit it** — drupal.org handles categorization and a custom package label is discouraged for contributed projects.
- List only runtime dependencies the module actually needs. Don't pad.
- **No `version:` line** — Composer / `drush pm:list` derive it from the tag/release.

## `<module_name>.module` (keep it thin)

```php
<?php

/**
 * @file
 * <Human readable> module hooks and shared procedural code.
 */

use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Implements hook_help().
 *
 * Surfaces module documentation in the Drupal UI at admin/help/<module_name>.
 * All but the most trivial modules should implement this.
 */
function <module_name>_help($route_name, RouteMatchInterface $route_match) {
  switch ($route_name) {
    // Main module help page (admin/help/<module_name>).
    case 'help.page.<module_name>':
      // Mirror the README synopsis in "About"; describe key tasks in "Uses".
      $output = '';
      $output .= '<h3>' . t('About') . '</h3>';
      $output .= '<p>' . t('<One-paragraph description of what the module does.>') . '</p>';
      $output .= '<h3>' . t('Uses') . '</h3>';
      $output .= '<dl>';
      $output .= '<dt>' . t('<Task or feature>') . '</dt>';
      $output .= '<dd>' . t('<How a site builder uses it.>') . '</dd>';
      $output .= '</dl>';
      return $output;

    default:
      return NULL;
  }
}
```

- Procedural hooks only. Business logic belongs in services under `src/`.
- Keep any single hook under ~50 lines — extract to a service method.
- Type-hint entity parameters in `hook_ENTITY_TYPE_*` so static analysis can verify them.

## Hook implementations — OOP `#[Hook]` vs procedural

Drupal 11.1+ supports **object-oriented hook implementations**: a `#[Hook('name')]` attribute on a method of an autowired class (conventionally `src/Hook/<ModuleClass>Hooks.php`). A module targeting the **latest Drupal** should implement its regular hooks this way rather than procedurally in `.module`:

```php
<?php

namespace Drupal\<module_name>\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Hook implementations for the <Human Readable Name> module.
 */
final class <ModuleClass>Hooks {

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): ?string {
    // Build help with $this->t(); inject services via the constructor
    // (the class is autowired).
    return NULL;
  }

}
```

Caveats:

- `#[Hook]` requires **Drupal 11.1+**; on Drupal 10.x the attribute is ignored. If the module supports `^10.3 || ^11`, any hook that must also fire on 10.x needs a **procedural** implementation in `.module` (or target `^11` only). Decide per your support matrix.
- **Install/update hooks stay procedural** in `.install` — `hook_install` / `hook_update_N` / `hook_uninstall` / `hook_requirements` / `hook_schema` are not regular hooks and aren't converted to `#[Hook]`.
- The Hook class is autowired (auto-discovered); inject dependencies via the constructor (with property promotion) — never `\Drupal::service()`.

## `<module_name>.services.yml` (only when you have services)

```yaml
services:
  <module_name>.<service_name>:
    class: Drupal\<module_name>\Service\<ServiceClassName>
    arguments:
      - '@entity_type.manager'
      - '@config.factory'
      - '@logger.factory'
```

- Inject dependencies — never call `\Drupal::service()` inside a service class.
- Logger: inject `'@logger.factory'`, then `->get('<module_name>')` in the constructor.

## `<module_name>.install` (install/update/uninstall logic)

```php
<?php

/**
 * @file
 * Install, update, and uninstall functions for <module_name>.
 */

/**
 * Implements hook_install().
 *
 * Any install/update/uninstall logic must live in this .install file.
 */
function <module_name>_install() {
  // Default config in config/install/ is imported by core automatically; add
  // code here only for state core can't express as config (e.g. external seeds).

  // Common courtesy: confirm the module is installed, and (if it has one) link
  // to its configuration page so the user knows where to start.
  \Drupal::messenger()->addStatus(t('The <Human Readable Name> module is installed.'));
  // If the module provides a settings form, also point users to it:
  // \Drupal::messenger()->addStatus(t('Configure it on its <a href=":url">settings page</a>.', [
  //   ':url' => \Drupal\Core\Url::fromRoute('<module_name>.settings_form')->toString(),
  // ]));
}

/**
 * Implements hook_uninstall().
 */
function <module_name>_uninstall() {
  // Clean up keyvalue/state entries. Config and schema are handled by core.
  \Drupal::state()->delete('<module_name>.last_run');
}

/**
 * Implements hook_requirements().
 */
function <module_name>_requirements($phase) {
  $requirements = [];
  if ($phase === 'runtime') {
    // Report runtime status — e.g. an external API being reachable.
  }
  return $requirements;
}
```

Update hooks (`hook_update_N`) live here too — use the **`drupal-hook-update-n`** skill for those patterns.

## `<module_name>.permissions.yml` (when the module defines permissions)

```yaml
administer <human readable feature>:
  title: 'Administer <feature>'
  description: 'Allow users to configure <feature> settings.'
  restrict access: true

use <human readable feature>:
  title: 'Use <feature>'
  description: 'Allow users to use <feature> at runtime.'
```

- Admin permissions set `restrict access: true`.
- Keys are lowercase, space-separated, verb + noun.

## `<module_name>.routing.yml` (when the module provides routes)

```yaml
<module_name>.settings_form:
  path: '/admin/config/<area>/<module_name>'
  defaults:
    _form: '\Drupal\<module_name>\Form\SettingsForm'
    _title: '<Module> settings'
  requirements:
    _permission: 'administer <human readable feature>'
```

## `config/install/` and `config/schema/`

- `config/install/<module_name>.settings.yml` — default config the module ships with.
- `config/schema/<module_name>.schema.yml` — schema for that config. **Required whenever you ship config** — omitting it makes the config untestable and trips coding-standards/CI checks.

## Front-end assets — `<module_name>.libraries.yml`, CSS, JS (when the module ships styling/behavior)

```yaml
# <module_name>.libraries.yml
global-styling:
  version: 1.x
  css:
    theme:
      css/<module_name>.css: {}
  js:
    js/<module_name>.js: {}
  dependencies:
    - core/drupal
    - core/once
```

Attach the library where it's needed: in Twig `{{ attach_library('<module_name>/global-styling') }}`, in a render array `'#attached': { library: ['<module_name>/global-styling'] }`, or globally via `hook_page_attachments()`.

`css/<module_name>.css` — starter stylesheet, populate as needed:

```css
/**
 * @file
 * Styles for the <Human Readable Name> module.
 */

/* Prefer the theme's design tokens; avoid hard-coded colors that fail contrast
   or break forced-colors mode. Scope rules to a module-specific class. */
.<module_name>-component {
  /* … */
}
```

`js/<module_name>.js` — Drupal behavior, populate as needed:

```js
/**
 * @file
 * Behaviors for the <Human Readable Name> module.
 */
((Drupal, once) => {
  'use strict';
  Drupal.behaviors.<module_name> = {
    attach(context) {
      once('<module_name>', '[data-<module_name>]', context).forEach((element) => {
        // …
      });
    },
  };
})(Drupal, once);
```

## Twig template — `templates/<module_name>-component.html.twig` (when the module renders custom markup)

Drupal template files are always `*.html.twig`, live in the module's **top-level `templates/`** directory, and are registered via `hook_theme()` (in `.module`):

```php
function <module_name>_theme($existing, $type, $theme, $path) {
  return [
    '<module_name>_component' => [
      'variables' => ['items' => [], 'attributes' => []],
    ],
  ];
}
```

```twig
{#
/**
 * @file
 * Default theme implementation for <module_name>_component.
 *
 * Available variables:
 * - items: list of items to render.
 * - attributes: HTML attributes for the wrapper.
 */
#}
<div{{ attributes.addClass('<module_name>-component') }}>
  {% for item in items %}
    <div class="<module_name>-component__item">{{ item }}</div>
  {% endfor %}
</div>
```

## `<module_name>.api.php` (only when the module defines hooks or an API)

If the module **invokes its own hooks** or exposes an API for other modules, ship a `<module_name>.api.php` documenting them with full Doxygen comments (parameters, return, `@see`). The file is **documentation-only — never loaded at runtime**; it powers api.drupal.org and IDE hook discovery. Model it on Drupal core's `system.api.php`.

```php
<?php

/**
 * @file
 * Hooks and API documentation for the <Human Readable Name> module.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Respond to <the event this module exposes>.
 *
 * <Describe when the hook fires and what an implementing module can do.>
 *
 * @param array $context
 *   <Description of each parameter, with its type.>
 *
 * @return array
 *   <Description of the expected return value, if any.>
 *
 * @see <module_name>_invoking_function()
 */
function hook_<module_name>_alter(array &$context) {
  // Example implementation: tweak $context before it is used.
  $context['extra'] = TRUE;
}

/**
 * @} End of "addtogroup hooks".
 */
```

## `composer.json` (only for Composer-shipped modules)

```json
{
  "name": "drupal/<module_name>",
  "description": "One-line description.",
  "type": "drupal-module",
  "license": "GPL-2.0-or-later",
  "require": {
    "php": ">=8.1",
    "drupal/core": "^10.3 || ^11"
  }
}
```

For a site-level module inside a hosting upstream, `composer.json` is usually unnecessary — the root `composer.json` handles dependencies.

## `README.md` (required) — follow Drupal's documentation-file standard

Documentation files follow the Drupal README standard:

- **Format & name:** Markdown (`.md`) or plain text (`.txt`); **base name ALL-CAPS, extension lowercase** — `README.md`, `INSTALL.md`, `CHANGELOG.txt`, `TODO.txt` (never `readme.md` or `README.MD`).
- **Section headers** inside the file are **initial-capitalized, not all-caps** — `## Requirements`, not `## REQUIREMENTS` (let `##` → `<h2>` carry the emphasis). The ALL-CAPS rule is for the *filename*, not the in-document headings.
- **Line endings:** Unix LF (`\n`) only — never CRLF (`\r\n`) or CR (`\r`).
- **Wrapping:** hard-wrap at **80 characters**.
- **Synopsis:** open with the same one-paragraph synopsis used on the module's drupal.org project page.
- **Split when large:** if the README grows, move system requirements + install/config into `INSTALL.md` / `INSTALL.txt` and keep the README pointing to it.
- **Orient new users (README *and* `hook_help()`):** show where to get started and how to find the module's functionality. List any **non-admin menu/route paths** the module adds (so users don't hunt through code or the admin UI). If it **alters existing forms** via the Form API, say what to look for to confirm it's working. If it **requires configuration**, link the settings page and give a short walk-through.

Template (based on the drupal.org README template; lines hard-wrapped at 80):

```markdown
# <Human Readable Name>

<One-paragraph synopsis — the same text as the drupal.org project page.>


## Table of contents

- Requirements
- Recommended modules
- Installation
- Configuration
- Troubleshooting
- FAQ
- Maintainers


## Requirements

This module requires no modules outside of Drupal core.
<Or: list required modules/libraries, each linked — including indirect ones.
If there are none, write "No special requirements".>


## Recommended modules

<Optional. Modules that enhance this one, with the benefit of each, e.g.:>
- [Markdown filter](https://www.drupal.org/project/markdown): renders this
  README's help text as Markdown when enabled.


## Installation

Install as you would normally install a contributed Drupal module. See
https://www.drupal.org/docs/extending-drupal/installing-modules for further
information. Note any Drush commands or steps that diverge from the standard.


## Configuration

1. Enable the module at Administration > Extend.
2. Grant permissions at Administration > People > Permissions.
3. <Configure settings at the module's settings route, if any.>
<If the module has no configuration, explain what enabling/disabling it does.>


## Troubleshooting

<Optional. Common problems and their fixes; summarize any external links.>


## FAQ

<Optional. Frequently asked questions and their answers.>


## Maintainers

- <Name> - https://www.drupal.org/u/<drupal-org-user>

<The Maintainers section replaces any legacy standalone MAINTAINERS.md file.>
```

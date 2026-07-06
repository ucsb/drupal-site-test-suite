# Modern PHP / Drupal Code Standards

Target **Drupal 10.3+**: these idioms require it (and several only work there).

## Versioning & namespacing

- `core_version_requirement: ^10.3 || ^11`: Drupal 10.0–10.2 are EOL; 10.3 is the 10.x LTS, and the attribute/`#config_target` features below need 10.3.
- A module's PHP namespace `Drupal\<module_name>\…` is derived **directly from its machine name**, so the machine name must be unique not just on your site but against **contrib** (prefix it, e.g. `myorg_<name>`). A name that shadows a contrib namespace causes autoload/identity collisions. Never hand-write a namespace root that differs from the machine name.
- **Placement:** put custom code under the conventional `custom/` paths that match `composer.json` `installer-paths`: `web/modules/custom/`, `web/themes/custom/`, `web/profiles/custom/`. Drupal discovers modules/themes anywhere under `web/modules` / `web/themes`, so a subtheme committed at the **root** (`web/themes/<theme>/`) still works and is picked up by `*.info.yml` autodiscovery; but it sits outside `installer-paths`. **Prefer relocating it to `web/themes/custom/<theme>/`**; if you can't, declare the location in `tests/code-quality/config/custom-paths.json` so tooling scopes it explicitly.

## Naming conventions (services, plugins, routes, permissions, config)

Names are derived from the module machine name and follow consistent patterns:

- **Service IDs:** `<module>.<service_name>`: lowercase, dot-separated (e.g. `my_module.api_client`).
- **Route names:** `<module>.<route_name>` (e.g. `my_module.settings_form`); the path is separate (e.g. `/admin/config/<area>/<module>`).
- **Plugin IDs:** `<module>_<plugin_name>`: snake_case (e.g. a block id `my_module_latest`). Discovered via PHP attributes (Drupal 10.3+), not annotations.
- **Permission keys:** lowercase, space-separated, verb + noun (e.g. `administer my feature`); admin permissions set `restrict access: true`.
- **Config object names:** `<module>.<name>` (e.g. `my_module.settings`), always paired with a `config/schema/` entry.
- **Hook functions:** `<module>_<hook>` (procedural in `.module`/`.install`); OOP hooks via `#[Hook('<hook>')]` (see below).
- **Classes:** `PascalCase`, file name matches the class; namespace mirrors the path under `Drupal\<module>\…`.

## Dependency injection

- Inject services via the constructor; **never** `\Drupal::service()` (or other static `\Drupal::` calls) inside a class method.
- Use **constructor property promotion** (Drupal 10+):

```php
public function __construct(
  protected readonly EntityTypeManagerInterface $entityTypeManager,
  protected readonly ConfigFactoryInterface $configFactory,
) {}
```

## Translatable strings

Every user-facing string must be translatable; including in template files:

- Classes: `$this->t('…')` (use `StringTranslationTrait`).
- Procedural code: `t('…')`.
- Twig: `{{ 'Text'|t }}` or `{% trans %}…{% endtrans %}`.

Never output a raw UI string.

## Attributes, not annotations

Define plugins with PHP attributes (Drupal 10.3+), not doc-block annotations:

```php
#[Block(
  id: '<module_name>_example',
  admin_label: new TranslatableMarkup('Example'),
)]
final class ExampleBlock extends BlockBase {}
```

## `#config_target` on `ConfigFormBase`

Bind each config-form element to its config key with `#config_target` instead of `#default_value` + a manual save, the parent class persists it (Drupal 10.3+):

```php
$form['toolkit'] = [
  '#type' => 'radios',
  '#title' => $this->t('Image toolkit'),
  '#config_target' => 'system.image:toolkit',
  '#options' => [],
];
// No submitForm() save needed; ConfigFormBase handles it.
```

## OOP hook implementations: `#[Hook]`

Drupal 11.1+ supports object-oriented hooks: a `#[Hook('name')]` attribute on a method of an autowired class (conventionally `src/Hook/<ModuleClass>Hooks.php`). A module targeting the **latest Drupal** should implement regular hooks this way rather than procedurally in `.module`.

Caveats:

- `#[Hook]` requires **Drupal 11.1+**; on Drupal 10.x the attribute is ignored. If the module supports `^10.3 || ^11`, any hook that must also fire on 10.x needs a **procedural** implementation in `.module` (or target `^11` only).
- **Install/update hooks stay procedural** in `.install`: `hook_install` / `hook_update_N` / `hook_uninstall` / `hook_requirements` / `hook_schema` are not regular hooks.
- The Hook class is autowired; inject dependencies via the constructor (with promotion).

## Cacheability

Render output carries **cacheability metadata**: get it right or you ship stale content (too much caching) or leak one user's data to another (too little). Linters don't catch this; it's a correctness standard.

- **`#cache` on render arrays**: declare `tags`, `contexts`, `max-age`:

```php
$build['item'] = [
  '#markup' => $text,
  '#cache' => [
    'tags' => $node->getCacheTags(),     // invalidate when this node changes
    'contexts' => ['user.permissions'],  // vary by what changes the output
    'max-age' => Cache::PERMANENT,
  ],
];
```

- **Cache tags** = *what invalidates this*; set when the underlying data changes (`node:5`, `node_list`, `config:my_module.settings`, a custom tag). Saving the entity clears its tag automatically.
- **Cache contexts** = *what varies this*, the request dimensions the output depends on (`user`, `user.permissions`, `url.path`, `route`, `languages`, `url.query_args:foo`). **Omitting a security-relevant context (e.g. `user.permissions`) leaks one user's view to another**: a real vulnerability, not just a perf bug.
- **`max-age`**: `Cache::PERMANENT` (default) until a tag invalidates; a positive integer for time-based; **`0` only when truly uncacheable**. Reaching for `max-age: 0` to "fix" stale content is the wrong fix; add the missing **tag/context** instead.
- **Bubble dependencies, don't recompute**: add cacheable objects (entities, config, access results) so their metadata bubbles up:

```php
$metadata = CacheableMetadata::createFromRenderArray($build);
$metadata->addCacheableDependency($node)->addCacheableDependency($config);
$metadata->applyTo($build);
```

- **Access checks** return cacheable `AccessResult` objects; use `->cachePerPermissions()` / `->addCacheableDependency(...)` rather than an uncached boolean, so access decisions cache correctly.

Anti-patterns: `max-age: 0` as a stale-content band-aid; clearing all caches to "pick up" a change; forgetting the context the output actually varies by (stale **or** leaked data).

## Front-end (CSS & JS)

Custom themes/modules ship CSS and JS, which have their own Drupal standards; enforced by **stylelint** and **ESLint** (Drupal configs); verify via `drupal-code-quality-audit`.

**JavaScript**

- Wrap behavior in `Drupal.behaviors.<name>` with `attach(context, settings)`, and use `once()` so it doesn't re-bind when Drupal re-attaches behaviors after AJAX.
- Translatable strings via `Drupal.t()`; pass server→JS data through `drupalSettings` (don't inline PHP into JS).
- Use strict mode and JSDoc; prefer vanilla DOM + `once` over adding jQuery.
- Load via a `.libraries.yml` library (`attach_library` / `#attached`); never an inline `<script>`.

**CSS**

- Component-based architecture; consistent, namespaced class naming (BEM-style); never style by `#id`.
- Use theme **design tokens / CSS custom properties**; avoid hard-coded colors (and avoid alpha on text/affordances) so contrast and forced-colors hold; see the `a11y` agent for the accessibility rules.
- Load via a `.libraries.yml` library (`css.theme`), scoped to a component class.

(Ready-to-fill `.libraries.yml`, CSS, and JS templates live in `drupal-module-scaffold`.)

## Secrets

Never hard-code or commit secrets; read them via the Key module (`key.repository`, injected), the environment, or config. Provisioning is hosting-specific → see `drupal-secrets-management`.

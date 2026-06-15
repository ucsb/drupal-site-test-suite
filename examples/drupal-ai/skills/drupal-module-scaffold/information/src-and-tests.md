# `src/` Layout and `tests/` Structure

## `src/` layout

```text
src/
├── Controller/
│   └── <Name>Controller.php         # routes returning render arrays / responses
├── Form/
│   ├── SettingsForm.php              # admin settings
│   └── <Name>Form.php                # other forms
├── Service/
│   └── <Name>Service.php             # business logic, DI-wired
├── Plugin/
│   ├── Block/
│   │   └── <Name>Block.php
│   └── Field/{FieldType,FieldFormatter,FieldWidget}/
└── EventSubscriber/
    └── <Name>Subscriber.php
```

Rules:

- Class names are `PascalCase`; file names match the class name.
- Namespace matches the path: `Drupal\<module_name>\Service\FooService` → `src/Service/FooService.php`. The root `Drupal\<module_name>` segment comes straight from the machine name, so a unique, prefixed machine name (see Pre-flight) is what keeps the whole namespace clear of contrib collisions — never hand-write a namespace root that differs from the machine name.
- Inject dependencies via the constructor — never `\Drupal::service()` in a class method.
- Every service class gets a matching unit test under `tests/src/Unit/Service/`.

### Conditional `src/` subdirectories

Add these **only when the module needs the capability** — they're not part of the default layout:

- `src/Access/` — custom route/entity **access checkers** (`AccessInterface` implementations / `_custom_access` callbacks). Only when you define custom access logic.
- `src/Entity/` — a **custom entity type** definition (content or config entity). Most modules use existing entities; add this (with a matching `src/Access/` handler and `config/schema/`) only when defining a new entity type.
- `src/Twig/` — a custom **Twig extension** (a PHP class adding Twig filters/functions, registered as a `twig.extension` service). This is **not** where templates go.

### Twig templates live in the module's top-level `templates/`

Drupal template files are always `*.html.twig` and live in `<module>/templates/` (e.g. `templates/<module_name>-component.html.twig`), registered via `hook_theme()` in `.module` — **not** under `src/`. Don't confuse the module's `templates/` (Twig) with a generic boilerplate folder.

## `tests/` structure

```text
tests/
└── src/
    ├── Unit/                         # pure PHPUnit, no Drupal bootstrap
    │   └── Service/<Name>ServiceTest.php
    ├── Kernel/                       # KernelTestBase — entities, config, DB, container
    │   └── <Name>KernelTest.php
    ├── Functional/                   # BrowserTestBase — full site, no JS
    │   └── <Name>FunctionalTest.php
    └── FunctionalJavascript/         # BrowserTestBase + JS
        └── <Name>FunctionalJavascriptTest.php
```

- Test namespace: `Drupal\Tests\<module_name>\{Unit,Kernel,Functional,FunctionalJavascript}`.
- **Where tests live by target:**
  - **Custom module** → in that module: `web/modules/custom/<area>/<module>/tests/src/...`.
  - **Module nested under a custom profile** → in *that module*: `web/profiles/custom/<profile>/modules/<module>/tests/src/...` (not the profile root).
  - **The profile itself** → only an optional *install/integration* test (Functional, occasionally Kernel) at `<profile>/tests/src/Functional/` — profiles rarely hold unit-testable logic.

Suggested coverage targets: ≥70% line coverage on service classes; every bug fix ships a regression test; anything touching entities/forms/render arrays gets at least one Functional test.

Ship one placeholder unit test from day 1 so CI has something to run:

```php
<?php

namespace Drupal\Tests\<module_name>\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\<module_name>\Service\<Name>Service
 * @group <module_name>
 */
class <Name>ServiceTest extends UnitTestCase {

  /**
   * @covers ::<method>
   */
  public function testPlaceholder(): void {
    $this->assertTrue(TRUE, 'Replace with a real assertion.');
  }

}
```

For writing **real** Unit/Kernel tests (behavior coverage, not just the placeholder), use the **`drupal-phpunit-tests`** skill. The top-level test runner stays at `/tests/phpunit/run.js`; test *classes* always live in the module being tested (above).

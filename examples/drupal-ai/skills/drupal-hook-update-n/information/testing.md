# Update-Path Test Fixture

Every non-trivial update hook ships with a test proving the update path works from a realistic pre-update fixture — and that it's idempotent.

## Layout (in the module being updated)

```text
tests/
├── src/
│   └── Functional/
│       └── Update/
│           └── <ModuleName>UpdateTest.php
└── fixtures/
    └── <module_name>-update-<N>.php.gz       # DB dump at the pre-update state
```

Base class: `\Drupal\FunctionalTests\Update\UpdatePathTestBase`.

```php
<?php

namespace Drupal\Tests\my_module\Functional\Update;

use Drupal\FunctionalTests\Update\UpdatePathTestBase;

/**
 * Tests my_module update path (theme rename + block migration).
 *
 * @group my_module
 */
class MyModuleUpdateTest extends UpdatePathTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles = [
      __DIR__ . '/../../../fixtures/my_module-update-10015.php.gz',
    ];
  }

  /**
   * Tests the update path and its idempotency.
   */
  public function testUpdate(): void {
    // Pre-update state.
    $this->assertSame('oldtheme', $this->config('system.theme')->get('default'));

    // Run updates.
    $this->runUpdates();
    $this->assertSame('newtheme', $this->config('system.theme')->get('default'));

    // Re-run to confirm idempotency — running again must not error or regress.
    $this->runUpdates();
    $this->assertSame('newtheme', $this->config('system.theme')->get('default'));
  }

}
```

## Generating the fixture

```bash
# On a Drupal 10.3+ site loaded with the pre-update module state:
drush sql:dump --gzip --result-file=my_module-update-10015.sql
# Rename to .php.gz per the UpdatePathTestBase convention.
```

Commit the fixture so CI can run the test. Prefer a **minimal** DB (the module's schema + a few representative entities) over a full production dump — smaller, faster, and no sensitive data committed.

## Notes

- The test namespace is `Drupal\Tests\<module>\Functional\Update` (it's a Functional/update-path test, not Unit/Kernel). For Unit/Kernel test conventions, see `drupal-phpunit-tests`.
- Assert **before** state, run updates, assert **after**, then run a second time and assert again — that second run is the idempotency proof.

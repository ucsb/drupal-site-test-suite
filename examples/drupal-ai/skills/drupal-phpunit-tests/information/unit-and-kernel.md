# Writing Unit and Kernel Tests

Neutral names (`my_module`, `MyService`) — substitute your real ones. Doc-block every class/method; use data providers for input variations.

## Unit test (no bootstrap, no DB)

For pure logic. Mock collaborators; never touch the container, entities, or config.

```php
<?php

namespace Drupal\Tests\my_module\Unit;

use Drupal\my_module\Classifier;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\my_module\Classifier
 * @group my_module
 */
class ClassifierTest extends UnitTestCase {

  /**
   * @covers ::classify
   * @dataProvider provideInputs
   */
  public function testClassify(string $input, string $expected): void {
    $this->assertSame($expected, (new Classifier())->classify($input));
  }

  /**
   * Data: input → expected classification.
   */
  public static function provideInputs(): array {
    return [
      'archived marker' => ['[archived] Foo', 'archived'],
      'plain title' => ['Foo', 'active'],
    ];
  }

}
```

Mocking a collaborator:

```php
$repository = $this->createMock(KeyRepositoryInterface::class);
$repository->method('getKey')->with('api')->willReturn($key);
$service = new MyService($repository);
```

## Kernel test (minimal container + DB)

For services using real Drupal APIs, config, or entities. Declare the modules you need and install their config/schema.

```php
<?php

namespace Drupal\Tests\my_module\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * @group my_module
 */
class SettingsDefaultsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['my_module'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['my_module']);
    // $this->installEntitySchema('node'); // when the test touches entities
  }

  /**
   * Config defaults ship with the expected values.
   */
  public function testConfigDefaults(): void {
    $config = $this->config('my_module.settings');
    $this->assertSame('default_value', $config->get('new_option'));
  }

  /**
   * The service is registered and behaves as expected.
   */
  public function testServiceBehavior(): void {
    $this->assertTrue($this->container->has('my_module.example'));
    $result = $this->container->get('my_module.example')->doThing('x');
    $this->assertSame('expected', $result);
  }

}
```

Common Kernel setup helpers:

- `installEntitySchema('<entity_type>')` — before creating that entity type.
- `installConfig(['<module>'])` — to load a module's default config.
- `installSchema('<module>', ['<table>'])` — legacy/custom DB tables.
- Create entities via the entity type manager and assert on saved/loaded state.

## Choosing the level

If a method is pure (inputs → output, no Drupal services) → **Unit**. The moment it needs the container, config, an entity, or the DB → **Kernel**. Reserve Functional (`BrowserTestBase`) for full-page/HTTP/JS behavior — heavier and slower; only when Kernel can't reach it.

# Update Hook Patterns

All examples use neutral names (`my_module`, `old_module`/`new_module`, `oldtheme`/`newtheme`) — substitute your real machine names. Every hook returns a translatable message and is idempotent.

## Anatomy

```php
/**
 * Set newtheme as default and migrate block placements off oldtheme.
 */
function my_module_update_10015(&$sandbox) {
  // 1. Idempotency: short-circuit if already applied.
  $config_factory = \Drupal::configFactory();
  if ($config_factory->get('system.theme')->get('default') === 'newtheme') {
    return t('Theme rename already applied.');
  }

  // 2. Do the work — small, targeted, reversible where possible.
  $config_factory->getEditable('system.theme')->set('default', 'newtheme')->save();

  // 3. Migrate block placements from old theme to new.
  $block_storage = \Drupal::entityTypeManager()->getStorage('block');
  foreach ($block_storage->loadByProperties(['theme' => 'oldtheme']) as $block) {
    $block->set('theme', 'newtheme')->save();
  }

  // 4. Return a user-visible, translated message (shown in the update UI / drush).
  return t('Default theme set to newtheme; block placements migrated.');
}
```

Throw `\Drupal\Core\Utility\UpdateException` on an unrecoverable error — the batch stops and subsequent updates don't run.

## Pattern 1 — Rewrite a config object without clobbering customization

```php
function my_module_update_10001() {
  $config = \Drupal::configFactory()->getEditable('my_module.settings');
  // Preserve user-set values; only add our new default.
  if (!$config->get('new_option')) {
    $config->set('new_option', 'default_value')->save(TRUE);
  }
  return t('Added new_option to my_module.settings.');
}
```

Prefer this surgical `getEditable()` + `set()` over a blanket config rewrite for small changes.

## Pattern 2 — Rename a module (pair with composer `replace`)

```php
function new_module_update_11001() {
  // Carry over state from the old module.
  $old_state = \Drupal::state()->get('old_module.last_run');
  if ($old_state) {
    \Drupal::state()->set('new_module.last_run', $old_state);
    \Drupal::state()->delete('old_module.last_run');
  }

  // Rewrite config that embeds the old module name in its dependencies.
  $config_factory = \Drupal::configFactory();
  foreach ($config_factory->listAll('views.view.') as $name) {
    $config = $config_factory->getEditable($name);
    $deps = $config->get('dependencies.module') ?? [];
    if (in_array('old_module', $deps, TRUE)) {
      $deps = array_map(fn($m) => $m === 'old_module' ? 'new_module' : $m, $deps);
      $config->set('dependencies.module', $deps)->save(TRUE);
    }
  }

  // Uninstall the old module last (a composer `replace` shim keeps it resolvable).
  if (\Drupal::moduleHandler()->moduleExists('old_module')) {
    \Drupal::service('module_installer')->uninstall(['old_module'], FALSE);
  }
  return t('Migrated from old_module to new_module.');
}
```

Pair with a `composer.json` `replace` entry in the upstream so sites referencing the old package name still resolve during the transition.

## Pattern 3 — Entity bundle / field storage changes

```php
function my_module_update_10002() {
  $manager = \Drupal::entityDefinitionUpdateManager();
  $storage = $manager->getFieldStorageDefinition('field_foo', 'node');
  if ($storage) {
    $storage->setCardinality(-1);          // unlimited
    $manager->updateFieldStorageDefinition($storage);
  }
  return t('Set field_foo cardinality to unlimited.');
}
```

- Add a field: `installFieldStorageDefinition(...)`. Remove one: `uninstallFieldStorageDefinition(...)` — count existing values first and warn before deleting.
- Changing a field **type** corrupts data — use `hook_post_update_NAME` + a proper migration instead.

## Pattern 4 — Grant a new permission to existing roles

```php
function my_module_update_10003() {
  $role_storage = \Drupal::entityTypeManager()->getStorage('user_role');
  foreach (['editor', 'administrator'] as $role_id) {
    $role = $role_storage->load($role_id);
    if ($role && !$role->hasPermission('use my feature')) {
      $role->grantPermission('use my feature')->save();
    }
  }
  return t('Granted "use my feature" to editor and administrator roles.');
}
```

For dynamic source roles, detect the role by a stable property rather than hard-coding the id.

## Pattern 5 — Batched update (touches many entities)

```php
function my_module_update_10004(&$sandbox) {
  $storage = \Drupal::entityTypeManager()->getStorage('node');

  if (!isset($sandbox['progress'])) {
    $sandbox['progress'] = 0;
    $sandbox['current_id'] = 0;
    $sandbox['max'] = (int) $storage->getQuery()
      ->accessCheck(FALSE)->condition('type', 'article')->count()->execute();
  }

  $nids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'article')
    ->condition('nid', $sandbox['current_id'], '>')
    ->sort('nid')->range(0, 50)->execute();

  foreach ($storage->loadMultiple($nids) as $node) {
    $node->set('field_foo', 'value')->save();
    $sandbox['progress']++;
    $sandbox['current_id'] = $node->id();
  }

  $sandbox['#finished'] = $sandbox['max'] ? ($sandbox['progress'] / $sandbox['max']) : 1;
  if ($sandbox['#finished'] >= 1) {
    return t('Updated @count article nodes.', ['@count' => $sandbox['progress']]);
  }
}
```

Rule of thumb: batch anything touching more than ~200 entities, or it times out on large sites.

## `hook_post_update_NAME`

```php
/**
 * Re-save media entities to regenerate tokens (needs the container rebuilt).
 */
function my_module_post_update_regenerate_media_tokens(&$sandbox) {
  // Batched iteration over media entities, same sandbox shape as Pattern 5.
}
```

Use `post_update` when the work **consumes** schema/config changed earlier in the same run, or needs fully-hydrated entity APIs.

## `hook_update_dependencies()`

```php
/**
 * Implements hook_update_dependencies().
 */
function my_module_update_dependencies() {
  return [
    'my_module' => [
      // my_module_update_10015 must run after new_module_update_11001.
      10015 => ['new_module' => 11001],
    ],
  ];
}
```

Use when your update depends on state produced by another module's update (e.g. a theme rename that must wait for block migrations to finish).

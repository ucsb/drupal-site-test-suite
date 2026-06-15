# The Key Module (the abstraction)

The [Key module](https://www.drupal.org/project/key) decouples *what* a secret is from *where* it's stored. A **Key** config entity names the secret and points at a **key type** (what kind of value) and a **key provider** (where the value lives). Code reads the key through `key.repository` and never knows or cares about the storage.

## 1. Install

```bash
composer require drupal/key
drush en key
```

## 2. Define a key

Add a key at **Administration › Configuration › System › Keys** (`/admin/config/system/keys`), or ship it as config in `config/install/key.key.<key_id>.yml`:

- **Key type:** `authentication` (a token/API key), `authentication_multivalue` (e.g. user+pass), or `encryption`.
- **Key provider:** where the value is read from — `config` (dev only — value stored in config, *not* for production secrets), `env` (environment variable), `file` (a file outside the docroot), or a host-specific provider (e.g. Pantheon Secrets). See `information/providers.md`.

Shipping the *key definition* (not the value) as config is fine — the value comes from the provider at runtime.

## 3. Read the key in code (via DI)

Inject `key.repository` and read the value where you need it — never hard-code, never `getenv()` directly in a class:

```php
<?php

namespace Drupal\my_module\Service;

use Drupal\key\KeyRepositoryInterface;

/**
 * Talks to an external API using a credential from the Key module.
 */
final class ApiClient {

  public function __construct(
    protected readonly KeyRepositoryInterface $keyRepository,
  ) {}

  /**
   * Returns the API token, or NULL if the key/provider isn't configured.
   */
  protected function token(): ?string {
    $key = $this->keyRepository->getKey('my_api');
    return $key ? $key->getKeyValue() : NULL;
  }

}
```

Service wiring (`my_module.services.yml`):

```yaml
services:
  my_module.api_client:
    class: Drupal\my_module\Service\ApiClient
    arguments:
      - '@key.repository'
```

## Notes

- Handle a **missing** key gracefully (provider not configured on this environment) — return `NULL`/throw a clear error, don't fatal.
- Don't cache the secret value in config/state; read it from the repository when needed.
- For tests, inject a mocked `KeyRepositoryInterface` (see `drupal-phpunit-tests`) — never put a real secret in a fixture.

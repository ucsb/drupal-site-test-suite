# Secret Providers

Where the actual value lives. Pick per environment/host; the Key definition points at one of these, and code reads it the same way regardless (`information/key-module.md`).

## Pantheon Secrets (hosting-specific)

Pantheon stores secrets in its Secrets Manager and exposes them to Drupal as Keys via the `pantheon_secrets` module.

```bash
composer require drupal/pantheon_secrets
drush en pantheon_secrets

# Set the secret value on the site (per environment), via Terminus:
terminus secret:site:set <site>.<env> my_api "<the-secret-value>"
```

Then define a Key (`/admin/config/system/keys`) whose **provider is Pantheon Secrets**, mapped to the `my_api` secret. Code reads it through `key.repository` as usual. The value never enters the repo — it lives in Pantheon's store and is injected at runtime.

## Environment variables

Good for containerized/local/CI environments. Set the variable in the environment (host dashboard, container config, `.env` that is **git-ignored**), then use the Key module's **environment** provider pointed at the variable name:

```php
// In settings.php (read from the environment, never a literal):
$settings['my_api_key'] = getenv('MY_API_KEY') ?: NULL;
```

Prefer the Key `env` provider over scattering `getenv()` calls; keep all `getenv()` access in `settings.php`/the provider, not in classes.

## File-based

A secret stored in a file **outside the docroot** (so it's never web-served) and **git-ignored**. Use the Key module's **file** provider pointed at the absolute path (e.g. `../secrets/my_api.key`). Ensure the file's permissions are restrictive and it's excluded from backups that leave the host.

## Choosing

- **Pantheon-hosted site →** Pantheon Secrets (managed, per-environment, nothing on disk in the repo).
- **Container/CI or non-Pantheon host →** environment variables.
- **No secret store available →** file-based, outside the docroot, git-ignored.

Never use the Key module's **`config`** provider for real secrets — it stores the value in config, which is exportable and often committed. It's for development placeholders only.

## CI/CD pipeline secrets (separate from Drupal-runtime secrets)

The providers above are read by the **running Drupal site**. **Pipeline secrets** are different — they're consumed by **CI/CD** (build, test, deploy) and stored in the **CI platform's secret store**, never in the repo and never in the Drupal Key module.

For **GitHub Actions**, store them under the repo's **Settings → Secrets and variables → Actions**, and reference them in a workflow as `${{ secrets.NAME }}` (injected as an env var into the step that needs it). Common ones for a Drupal/Pantheon pipeline:

- **`PANTHEON_MACHINE_TOKEN`** — authenticate Terminus with Pantheon (e.g. to create multidev environments). From Pantheon Dashboard → Account → Machine Tokens.
- **`PANTHEON_SSH_KEY`** — SSH private key for git operations against Pantheon. Generate a dedicated key:

  ```bash
  ssh-keygen -t rsa -b 4096 -m PEM -C "pantheon-github-actions" -f ~/.ssh/pantheon_github_actions
  ```

  Add the **public** key to Pantheon (Account → SSH Keys); put the **private** key content in the GitHub secret.
- **`AXE_API_KEY`** — axe Developer Hub token for enhanced a11y testing. **Optional:** if it's unset, invalid, or the hub is unreachable, the Developer Hub tests skip cleanly (the free axe-core full-site scan still runs), so the pipeline stays green; the PR comment notes the skip.

Rules: reference a CI secret only via `${{ secrets.NAME }}` and inject it as an env var into the step that needs it — never echo it into logs, never write it into the checkout. Rotate any that leak.

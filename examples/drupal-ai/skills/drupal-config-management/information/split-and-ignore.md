# Environment Splits & Site-Specific Overrides

Two contrib modules handle the "not everything belongs in the one shared sync" cases. Both are optional; only relevant if the site already uses (or chooses to adopt) them.

## config_split: environment-specific config

Keeps config that should exist in *some* environments out of the shared sync: dev-only modules (`devel`, `stage_file_proxy`, `dblog` verbosity), prod-only settings, per-environment API endpoints (non-secret).

- Define a split as a config entity (`config_split.config_split.<name>`) with its own storage folder, e.g. `config/splits/dev`.
- **Complete split (blacklist):** the listed config is *removed* from the main sync and stored only in the split; present only when the split is active.
- **Conditional split (graylist):** the config stays in the main sync but is *overridden* by the split's version when active.
- Activate per environment in `settings.php` (not in exported config), so each environment turns on its own splits:

```php
// settings.local.php (dev): enable the dev split:
$config['config_split.config_split.dev']['status'] = TRUE;
```

Workflow: enable the split, assign modules/config to it, `drush config:export`: the dev-only items land in the split folder, the shared sync stays clean. On prod the dev split is inactive, so those modules don't get imported.

## config_ignore: protect site-edited config

Prevents `config:import` from overwriting config that's legitimately edited in production and must persist across deploys, e.g. `system.site` (site name/mail), contact forms, webforms, scheduled-content settings, API connection config a site owner manages.

- List patterns in the `config_ignore.settings` config (supports wildcards):

```yaml
ignored_config_entities:
  - system.site
  - contact.form.*
  - webform.webform.*
```

- Ignored config is skipped on import (its active DB value is preserved) and, depending on mode, omitted from export.

## Choosing between them

- **Differs by environment, you control it in code** → `config_split` (dev tools, env settings).
- **Edited by site owners in prod, you must not clobber it** → `config_ignore`.
- **A secret** → neither; it never goes in config at all; use the Key module (`drupal-secrets-management`).

Both build on Drupal core's `config_filter` pipeline; if a site uses neither, the plain `config/sync` workflow in `workflow.md` is all that's in play.

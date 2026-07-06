# Config Sync Workflow

The export → review → commit → deploy → import cycle, plus drift triage.

## The sync directory

Set once in `settings.php`, **outside** the docroot, and committed to git:

```php
$settings['config_sync_directory'] = '../config/sync';
```

It holds the site's full active configuration as `*.yml` (`core.extension.yml`, `system.site.yml`, every entity/view/field config, etc.). This is the source of truth that moves between environments.

## Export (cex)

```bash
drush config:export            # write active DB config → config/sync
git add config/sync
git diff --staged              # REVIEW: config diffs are easy to over-commit
git commit -m "Export config: <what changed>"
```

Review the diff carefully: an export captures *everything* that differs, so an unrelated UI click or an enabled dev module can sneak in. Commit only the intended change; revert the rest in the UI and re-export, or use `config_split` (see `split-and-ignore.md`).

## Import (cim)

```bash
drush config:status            # ALWAYS check drift first
drush config:import            # apply config/sync → active DB config
```

`config:import` is transactional; it applies the full sync set. If active config has drifted (someone changed it in prod), import overwrites it unless that config is protected by `config_ignore`.

## Single / partial operations

```bash
drush config:status                          # list what differs and direction
drush config:import --partial                # import without deleting config absent from sync
drush config:get <name>                      # read one config object
drush config:set <name> <key> <value>        # set one value (then export)
drush config:import --source=path            # import from a non-default directory
```

Use `--partial` cautiously: it won't remove config that's missing from sync, which can mask deletions.

## Drift triage

- `drush config:status` shows each object as `Only in DB`, `Only in sync`, or `Different`.
- **Only in DB** → either export it (intended) or it's accidental/site-specific (candidate for `config_ignore`).
- **Different** → inspect with `drush config:get`; decide whether the UI change or the committed YAML is correct.
- Recurring drift on the same objects (e.g. `system.site`, webform settings) is the signal to add them to `config_ignore`.

## Site UUID

Config export carries the source site's `system.site` UUID. To import config between environments they must share that UUID (clones do automatically). For a fresh target, set it once:

```bash
drush config:set system.site uuid <source-uuid>
```

Otherwise `config:import` refuses with a UUID-mismatch error.

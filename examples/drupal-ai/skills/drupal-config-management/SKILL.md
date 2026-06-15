---
name: drupal-config-management
description: Manage Drupal 10/11 configuration across environments — the config sync workflow (drush config:export / config:import), module config (config/install vs config/sync, required schema), environment-specific config via config_split, and site-specific overrides via config_ignore. Detects whether a site actually uses config management before applying. Use when exporting/importing config, setting up per-environment config, or diagnosing config drift. Vendor-neutral; follows drupal-coding-standards.
metadata:
  version: "2026.06.06"
---

# Drupal Config Management

Manages Drupal configuration as committed, version-controlled YAML so changes move predictably across environments (local → dev → test → prod) instead of being clicked in each site's admin UI.

Vendor-neutral; follows **`drupal-coding-standards`**. **Secrets never go in config** — exported YAML is committed, so credentials belong in the Key module, not a config value (see `drupal-secrets-management`).

## Detect first — does this site use config management?

Config management is a **Drupal core** feature — the `config:export` / `config:import` sync workflow around a committed `config/sync` directory. No contrib required (`config_split` / `config_ignore` are *optional* add-ons, below). **Not every site uses it** — many smaller or single-environment sites keep configuration in the database only and never export it. Check before doing anything:

1. **Is the sync directory declared in the settings files?** This is the definitive signal — grep for the core setting:

   ```bash
   grep -rn "config_sync_directory" web/sites/*/settings.php web/sites/*/settings.*.php 2>/dev/null
   ```

   You want `$settings['config_sync_directory'] = '…';` — modern best practice points it **outside** the docroot (e.g. `'../config/sync'`). If it's not declared anywhere, the site is on the install-time default and almost certainly isn't doing managed config.
2. **Is that directory populated + committed?** It should hold exported `*.yml` — at minimum `core.extension.yml` and `system.site.yml` — tracked in git.
3. **Does Drush agree?** `drush config:status` runs and reports in-sync / differences.

**If 1–2 hold → core config management is in use; apply this skill.** That's the baseline; the contrib add-ons (`config_split` / `config_ignore`) are **optional** — plenty of sites run core config management without them, so their absence does *not* mean config management is off.

**If the sync directory isn't declared or is empty → the site manages config in the database only.** Say so plainly. Don't force the workflow; offer to *help set it up* (declare `config_sync_directory` in `settings.php`, run an initial `drush config:export`, review + commit) only if the user wants it. Otherwise this skill doesn't apply.

## When To Use (once detected as in use)

- Exporting config after admin-UI changes, or importing on deploy.
- Setting up **environment-specific** config (dev-only modules, prod-only settings).
- Preserving **site-specific** config that shouldn't be overwritten on import.
- Diagnosing **config drift** (active DB config vs committed YAML).

## The core sync workflow

```bash
drush config:export    # cex — write active (DB) config to the sync directory
git add config/sync && git commit   # review the diff, then commit
# on the target environment, after deploy:
drush config:import    # cim — apply committed config to the site
drush config:status    # show drift between DB config and the sync directory
```

Details (single-config import, partial export, UUID/site matching, drift triage) → `information/workflow.md`.

## Module config (where config lives)

- `config/install/<name>.yml` — applied **once at module install**; the module's default config.
- `config/optional/<name>.yml` — applied at install **only if its dependencies are met**.
- `config/schema/<name>.schema.yml` — **required** typed schema for any custom config (untyped config fails CI and can't be translated/validated).
- The **sync directory** (`config/sync`) holds the *site's* full active config for export/import — distinct from a module's `config/install`.

## Environments & overrides (optional contrib)

These are **optional contrib** modules — core config management works fully without them. Reach for them only when the plain core sync workflow isn't enough, and only confirm their use after checking they're actually installed:

- **`config_split`** — keep environment-specific config out of the shared sync (dev tools like `devel`/`stage_file_proxy`, prod-only settings). Splits activate per environment via `settings.php`.
- **`config_ignore`** — protect config that's edited in production and must survive `config:import` (e.g. `system.site`, contact/webform settings).

Both → `information/split-and-ignore.md`. If the site uses neither, the core `config/sync` workflow is all that's in play.

## Git operations

- Config **is** version-controlled — but this skill edits/exports config into the **working tree only**; the user reviews `git diff` and commits. **Never commit or push automatically.**
- **Never** let a secret reach exported config — if you spot one (e.g. an API key in a settings YAML), stop and route it through `drupal-secrets-management`.

## Anti-patterns

- Running `config:import` without reviewing `config:status` first — can revert intended changes or fail on unexpected drift.
- Committing environment-specific or secret values into the shared sync — use `config_split` / `config_ignore` / the Key module.
- Custom config without `config/schema/` — untyped, untestable, fails CI.
- Mismatched site UUID between environments — `config:import` refuses; export carries the source `system.site` UUID.
- Hand-editing sync YAML to "fix" drift instead of changing it in the UI and re-exporting.

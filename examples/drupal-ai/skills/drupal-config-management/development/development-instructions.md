# Development — maintaining drupal-config-management

## Detect-first is load-bearing

Not every site uses config management — many keep config in the database only. **Always reinforce the detection gate** (`config_sync_directory` set + populated/committed `config/sync` + `drush config:status` works + split/ignore present) before applying the workflow. If config management isn't in use, say so and offer to help set it up rather than assuming the workflow exists.

## Keep it vendor-neutral

Works for any Drupal 10/11 site. No site/host/environment names baked in — generic "dev/test/prod," generic config object names.

## Keep it current with Drupal

- Re-check each cycle: `drush config:*` command surface, `config_sync_directory` setting, `config_split` (complete vs conditional) and `config_ignore` config shapes, the `config_filter` pipeline, and module config dirs (`config/install` / `config/optional` / `config/schema`).

## Stay in your lane (cross-skill boundaries)

- **Secrets** never go in config → `drupal-secrets-management` (Key module). This skill stops and routes any secret it finds.
- **`hook_update_N` config rewrites** (changing config *already on running sites* in code) → `drupal-hook-update-n`. This skill is about the export/import sync workflow, not update hooks.
- **Coding/doc standards** → `drupal-coding-standards`.

## Structure

`SKILL.md` (navigational: detect-first gate, sync workflow, module config, splits/ignore, anti-patterns) + `information/` (`workflow.md`, `split-and-ignore.md`) + this `development/` file.

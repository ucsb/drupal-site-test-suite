---
name: drupal-hook-update-n
description: Write Drupal `hook_update_N` / `hook_post_update_NAME` functions for custom modules — config rewrites, module/theme machine-name renames, entity-bundle and field-storage changes, permission remaps, data backfills — plus the paired update-path test fixture. Use when an upstream/shared codebase ships a schema/config/data change that downstream sites must apply on update (mutating state that already exists on a running site). Vendor-neutral; follows drupal-coding-standards.
metadata:
  version: "2026.06.06"
---

# Drupal hook_update_N

Use this skill when a change requires running something on every downstream site when it updates — i.e. **mutating state that already exists on a running site**:

- Renaming a module or theme machine name.
- Moving a field from one entity bundle to another, or changing field storage.
- Rewriting a config object that already exists on downstream sites.
- Adding a permission and granting it to existing roles.
- Uninstalling a module that had content entities.
- Backfilling a new field with values computed from existing content.

Update hooks exist for the hard case. If a change is **purely additive** and handled by `config/install/` + Composer, you usually don't need one — Drupal's config import picks it up.

This skill follows **`drupal-coding-standards`** (translatable messages, Doxygen one-line docblocks). Note: update hooks are **procedural** (they live in `.install`), so `\Drupal::service()` / static `\Drupal::` calls are normal here — the "inject, don't use `\Drupal::` " rule applies to classes, not to procedural update functions.

## When NOT to use an update hook

- Pure CSS/JS/template changes — downstream just picks them up.
- New config that didn't exist before — ship in `config/install/`; importing does the work.
- Code-only refactors that don't change schema, config, or data.
- Cache clears "to pick up a change" — almost never right; fix the missing cache metadata instead.

## Naming and numbering

- Function name: `<module>_update_<N>`, where `<N>` is `<major><two-digit-minor>` — e.g. `my_module_update_10001` for the first D10-era update, `my_module_update_11001` under D11.
- Numbering is sequential within a module; the framework tracks the last-run `<N>` per module in `key_value`'s `system.schema` collection.
- **Never reuse a number or rename a function after it has shipped** to any downstream site — the framework keys state off the old number, and renaming strands it.
- If an update depends on another (even in a different module), declare it in `hook_update_dependencies()`.

## Idempotency (the most important rule)

An update may be re-run after a partial failure, or run on a site that was patched manually first. **Design every update hook to be safe to re-run:**

- Check current state at the top; return early if already applied.
- Prefer `ConfigFactory::getEditable()` + `set()` + `save()` over arbitrary DB writes — config is inherently idempotent.
- When setting a default on existing entities, check whether it's already set.
- Don't assume a clean starting state — downstream sites may have customized the config you're rewriting; preserve unrelated keys.

## `hook_update_N` vs `hook_post_update_NAME`

- `hook_update_N` — runs **before** the container is fully rebuilt; safe for schema/config changes; no entity rendering. Use when the update **adds or changes** schema/config.
- `hook_post_update_NAME` — runs **after** the `hook_update_N` batch and container rebuild; use when you need entity APIs fully hydrated or must consume config changed earlier in the same run.

## Cache handling

- Do **not** call `drupal_flush_all_caches()` — the framework rebuilds caches after the update batch.
- For a specific mid-update invalidation, use the targeted service: `\Drupal::service('cache.config')->invalidateAll()`, `\Drupal::service('config.typed')->clearCachedDefinitions()`, or `\Drupal::service('plugin.manager.<type>')->clearCachedDefinitions()`.

## Patterns and testing

- Common update patterns (config rewrite, module/theme rename, field-storage change, permission grant, batched update, `post_update`, `hook_update_dependencies`) → `information/patterns.md`
- The required **update-path test fixture** (`UpdatePathTestBase`) → `information/testing.md`

## Checklist before merging an update hook

- [ ] One-line docblock; returns a translated user-facing message.
- [ ] Idempotent — safe to re-run after partial failure (verified by running it twice in the test).
- [ ] Batched if it touches more than ~200 entities.
- [ ] `hook_post_update_NAME` if it needs the container fully rebuilt; `hook_update_dependencies()` if ordering matters.
- [ ] Update-path test with a pre-update fixture and before/after assertions.
- [ ] No `drupal_flush_all_caches()` call.
- [ ] One logical change per update hook (partial failures stay recoverable).
- [ ] Passes your CI (the `drupal-code-quality-*` checks).

## Git operations

- Do **not** commit unless the user explicitly asks; **never push**.
- Subject line: `Add <module>_update_<N> — <short description>` (≤72 chars).

## Anti-patterns

- Renumbering an update after it ships — strands downstream sites.
- `drupal_flush_all_caches()` inside the update — always redundant.
- Deleting a field without first counting values and warning — silent data loss.
- An entity query without `->accessCheck(FALSE)` — you'll miss entities in an update context.
- Shipping the update without a fixture test — "it worked on my multidev" is not proof for many downstream sites.
- Bundling multiple unrelated changes in one `_update_N` — makes partial failures unrecoverable.

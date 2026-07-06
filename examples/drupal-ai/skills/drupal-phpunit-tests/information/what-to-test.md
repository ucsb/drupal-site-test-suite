# What to Test (and Coverage Targets)

Test **behavior that would hurt if it silently broke**: especially logic that downstream sites depend on through an upstream/shared codebase. Each item below maps to a test level.

## High-value targets

| What | Level | Assert |
| --- | --- | --- |
| Service / business logic (classification, calculations, transitions) | Unit (pure) or Kernel (uses services) | given input → expected output / state transition |
| Status / archive transitions | Unit or Kernel | only valid transitions allowed; invalid ones rejected |
| CSV / export field order & formatting | Unit | exact column order, headers, escaping |
| Config defaults | Kernel | shipped `config/install` values are present and correct |
| Entity validation / constraints | Kernel | invalid entities fail validation; valid ones pass |
| Access logic (custom access checkers) | Kernel | each role/permission combination allowed/denied as intended |
| Route requirements | Kernel/Functional | route exists, permission/requirements enforced |
| Uninstall cleanup | Kernel | `hook_uninstall` removes state/keyvalue; no orphaned config |
| Plugin behavior (block, field formatter, etc.) | Kernel | renders/behaves per inputs |

For **update hooks** (`hook_update_N`), the test is an update-path test; see `drupal-hook-update-n`, not this skill.

## Coverage targets

- Aim for **≥70% line coverage on service classes** (the logic-heavy code).
- **Every bug fix ships with a regression test** that fails before the fix and passes after.
- Anything touching entities, forms, access, or render arrays gets at least one Kernel (or Functional) test, not just Unit.

## Practical guidance

- Start from the **public behavior**: what does a caller/role/editor expect? Test that, not private internals.
- Cover the **edge cases that motivated the code**: the empty case, the boundary, the "archived" marker, the missing-config case.
- Use **data providers** to cover many inputs in one method.
- When protecting behavior before a rename/upgrade: write the test against current behavior first, confirm it passes, *then* make the change and keep it green.

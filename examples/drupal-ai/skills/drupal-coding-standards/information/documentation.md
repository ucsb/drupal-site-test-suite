# Documentation & Comments

## Doxygen doc-blocks (document everything)

Use Doxygen-style doc-blocks throughout; they power api.drupal.org and IDE context-help:

- An `@file` doc-block on every file.
- A doc-block on every function, class, method, and property (`@param` / `@return` / `@var` with types).
- Group related items with `@ingroup` / `@addtogroup` per the Drupal commenting guidelines.

## Inline comments

- Full sentences (capitalized, period-terminated, `//` + a space) that explain the **why**: intent, edge cases, non-obvious decisions, not a restatement of the code.
- **Well-balanced:** enough that a new developer follows the logic; not so much that comments echo obvious lines. When code is self-evident, let it speak.

## `<module>.api.php` for hooks/APIs

If the module **invokes its own hooks** or exposes an API, ship a `<module>.api.php` documenting them with full Doxygen comments (parameters, return, `@see`). It's **documentation-only; never loaded at runtime**; it powers api.drupal.org and IDE hook discovery. Model it on core's `system.api.php`, wrapping hook docs in `@addtogroup hooks` / `@{ … @}`.

## `hook_help()`

All but the most trivial modules implement `hook_help()` to surface docs in the UI at `admin/help/<module>`. Use the **About / Uses** pattern; mirror the README synopsis in "About." Help text (and the README) should **orient new users**: list non-admin menu/route paths the module adds, note any forms it alters (and what to look for), and link the settings page with a short walk-through if configuration is required.

## README and documentation files (Drupal doc-file standard)

- **Format & name:** Markdown (`.md`) or plain text (`.txt`); **base name ALL-CAPS, extension lowercase**: `README.md`, `INSTALL.md`, `CHANGELOG.txt`, `TODO.txt` (never `readme.md` or `README.MD`).
- **In-document headers:** initial-capitalized, **not** all-caps; `## Requirements`, not `## REQUIREMENTS` (let `##` → `<h2>` carry the emphasis). The ALL-CAPS rule is for the *filename*.
- **Line endings:** Unix LF (`\n`) only; never CRLF/CR.
- **Wrapping:** hard-wrap at **80 characters**.
- **Synopsis:** open with the same one-paragraph synopsis used on the drupal.org project page.
- **Structure** (drupal.org README template): Table of contents, Requirements, Recommended modules, Installation, Configuration, Troubleshooting, FAQ, Maintainers. Omit optional sections that don't apply; if none required, Requirements says "No special requirements". The Maintainers section replaces any standalone `MAINTAINERS.md`.
- **Split when large:** move system requirements + install/config into `INSTALL.md` / `INSTALL.txt` and keep the README pointing to it.

(See `drupal-module-scaffold` for a ready-to-fill README template and the file templates that embody these conventions.)

# Adapter: folder (no test suite — run tools directly)

Audits a Drupal site's **custom code** when the `utest` test suite is **not installed**, by running the standard code-quality tools directly and normalizing their output. This is the case that makes audit a standalone capability: a site with no suite still needs its custom code evaluated.

It is **best-effort by definition** — coverage depends on which tools and configs happen to be present. Be loud about what couldn't run; a near-empty result because nothing was installed is **not** a clean bill of health. (This adapter only *reads/analyzes* — it never fixes.)

## Step 1 — Find the docroot and the custom scope (no `custom-paths.json` to lean on)

Resolve scope in this order (the same three-layer model the test suite uses):

1. **Docroot:** locate where Drupal lives — `web/`, `docroot/`, or the repo root (look for `index.php` + `core/`).
2. **`composer.json` → `extra.installer-paths` (canonical — read first).** The mapping is authoritative:
   - Paths bound to **`type:drupal-custom-module`**, **`type:drupal-custom-theme`**, **`type:drupal-custom-profile`** (typically `web/modules/custom/{$name}`, `web/themes/custom/{$name}`, `web/profiles/custom/{$name}`) → **custom = in scope**.
   - Paths bound to `type:drupal-module` / `-theme` / `-profile` / `-core` / `-library`, `type:*-asset`, or contrib drush → **contrib / core / libraries = out of scope**.
3. **`tests/code-quality/config/custom-paths.json` (overrides + extras),** when present — custom code committed outside installer-paths, plus extra surfaces (repo Markdown, `.github/workflows/**`).
4. **`*.info.yml` autodiscovery (safety net).** Any `*.info.yml` outside `core/`, `*/contrib/`, `vendor/`, `node_modules/` is custom even if not covered above — this catches **hand-committed code outside the installer paths**, e.g. a subtheme committed directly at `web/themes/<theme>/`. Include it in scope, and surface a recommendation to relocate it to the canonical `web/themes/custom/<theme>/` (or declare it in `custom-paths.json`) so it matches `installer-paths`.
5. **Never** scan Drupal core, `*/contrib/`, `vendor/`, `node_modules/`, or `web/libraries/` (third-party assets). Most important rule — scanning contrib produces thousands of irrelevant findings.

**If the caller named a single target** (a specific custom module/theme, by machine name or path), narrow scope to **just that directory** instead of all custom code — resolve its path under the custom paths above and run the tools only there. Report findings only for that target; don't scan its siblings. Default to the full custom scope only when no target was given.

State the resolved scope (the module/theme/profile dirs, or the single target) before running anything.

## Step 2 — Detect tools (and record what's missing)

For each engine, probe in order: `vendor/bin/` → `node_modules/.bin/` → global `PATH`. Record availability; a missing tool becomes an `engines_skipped` entry with a one-line install hint — never a silent gap.

| Engine | Looks for | If missing |
| --- | --- | --- |
| phpcs / phpcbf | `vendor/bin/phpcs`; Drupal standard via `phpcs -i` (needs `drupal/coder`) | skip; hint: `composer require --dev drupal/coder` |
| phpstan | `vendor/bin/phpstan` (+ `mglaman/phpstan-drupal` for Drupal awareness) | skip or run at low level; note no Drupal config |
| drupal-check (deprecations) | `vendor/bin/drupal-check` | skip; hint: `composer require --dev mglaman/drupal-check` |
| eslint / stylelint / htmlhint / markdownlint | `node_modules/.bin/*` | skip; hint: `npm i -D <tool>` |
| twigcs | `vendor/bin/twigcs` | skip |
| yaml-lint / cspell / editorconfig-checker | node or composer bin | skip |
| actionlint | `actionlint` on PATH | skip (only matters if `.github/workflows/**` present) |
| composer (validate + audit) | `composer` on PATH | run `composer validate` + `composer audit` |
| **gitleaks** | `gitleaks` on PATH | **run it** — the folder path *can* cover secret scanning that `utest:lint` omits; skip with a hint if absent |

## Step 3 — Resolve configs (site's own first, sane Drupal defaults otherwise)

- Use the project's configs if present (`phpcs.xml(.dist)`, `phpstan.neon`, `.eslintrc*`, `.stylelintrc*`, etc.) at the repo root or a `tests/`/`config` dir.
- Otherwise fall back to **Drupal + DrupalPractice** for phpcs (requires `drupal/coder`), and each tool's recommended Drupal preset. Don't invent stricter-than-default configs — that manufactures noise. If a tool has no usable config and no sane default, skip it (recorded) rather than guessing.

## Step 4 — Run each available tool over the custom scope (machine-readable output)

Run read-only, scoped to Step 1's paths, preferring JSON output:

- `phpcs --standard=<std> --report=json <scope>` · `phpstan analyse --error-format=json <scope>` · `eslint -f json <scope>` · `stylelint -f json` · `markdownlint --json` · `composer audit --format=json` · `gitleaks detect --report-format=json` · etc.

## Step 5 — Normalize (same target as every adapter)

Map each tool's native output into the finding schema (this is the direct-run path of `../reference/severity-levels.md`):

- Construct `id` deterministically: `{engine}:{rule_id}:{path}:{line}`.
- Map native severity → `severity` via `severity-levels.md`; set `impact_category` (`security` for gitleaks/`composer audit`, else `code-quality`).
- `locations` = `kind: file`, **repo-relative** `path` + `line`/`column`.
- Add ours: `suite: "linting"`, `engine`, `fix` = `null`, `data_sensitivity`/`requires_auth` = `null`, `tags` default `[]`.
- **Capture native fixability** when the tool exposes it — phpcs JSON has a per-message **`fixable`** flag (true = `phpcbf` can fix it), ESLint reports fixable rules, etc. Record it as a `tags` signal (`autofix:available` / `autofix:none`). This is **authoritative** — far better than guessing from the rule name — and the diagnostic prefers it over its heuristic table.
- Scope-guard: drop anything that resolves under core/contrib/`vendor/`/`node_modules/`.

## Step 6 — Coverage (the honesty surface — matters most here)

- `engines_run` = tools that actually executed.
- `engines_skipped` = every undetected/unconfigured tool, **with its install hint**.
- Call out that this is a **direct-run audit**: it approximates, but won't exactly match, a curated `utest:lint` run (different/looser configs), and **can't replicate the suite's bespoke checks** (the `references` library/twig-asset checks; `deprecations` unless `drupal-check` ran). Say what's approximate.

## Step 7 — Hand off

Emit the `suite: "linting"` envelope and hand to the **`drupal-code-quality-diagnostic`** agent. If coverage is thin (few tools installed), recommend either installing the missing dev-deps or — better — installing the `utest` test suite for a curated, CI-equivalent run (then use the drush adapter).

## Failure modes

| Symptom | Do |
| --- | --- |
| No custom modules/themes found | Report it — confirm the docroot/scope; don't fall back to scanning everything. |
| phpcs present but no Drupal standard | Note `drupal/coder` missing; skip phpcs or run a generic standard with a caveat. |
| A tool errors mid-run | Capture what it produced; mark the engine partial/errored in coverage. |
| Almost nothing installed | Emit the little you have, but make the thin coverage unmissable; suggest installing the suite. |

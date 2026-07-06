# Adapter: drush (default a11y runtime path)

Produces **one aggregated** findings envelope (`../reference/finding-format.md`) by running the project's per-lane a11y commands (`utest:alfa`, `utest:axe`, `utest:pa11y`, `utest:reflow`, `utest:meta-viewport`, `utest:axe-watcher`) and merging the per-lane `test-suite-findings.json` files they emit. Default adapter whenever a reachable Drupal site with the test suite is present.

Unlike the linting adapter (one `utest:lint` file), a11y is **one file per engine**: this adapter runs/reads several lanes and merges them into a single envelope.

## Step 1: Resolve drush (ordered probe)

Same probe as everywhere: `vendor/bin/drush` → `ddev drush` → `lando drush` → global `drush`; use the first that responds to `version`, and call the resolved invocation directly (never assume a PATH alias). Record as `DRUSH`.

## Step 2: Preflight (a live, reachable site is REQUIRED)

A11y engines test **rendered pages**, so unlike linting you need a running site. Resolve `BASE_URL` (flag → `BASE_URL` env → fail) and preflight:

```bash
<DRUSH> utest:check-config --base-url=<BASE_URL>
```

Require **BASE_URL reachable** = PASS. **sitemap.xml reachable** = PASS is strongly preferred, the full-site lanes crawl the sitemap; without it they fall back to a tiny path set and your coverage is thin (record that in `coverage`). If the site isn't reachable, stop: an a11y audit can't run against a site that isn't serving pages.

## Step 3: Discover and run the a11y lanes

Discover via `<DRUSH> list --format=json` (parse the per-lane `utest:*` names); don't hardcode. Every a11y lane emits both `test-suite-findings.json` and a standalone `<lane>-report.html`:

| Lane | Engine / what it checks |
| --- | --- |
| `utest:alfa` | Siteimprove Alfa |
| `utest:axe` | axe-core |
| `utest:pa11y` | pa11y-ci |
| `utest:reflow` | 320px reflow, SC 1.4.10 |
| `utest:meta-viewport` | SC 1.4.4 |
| `utest:axe-watcher` | axe Developer Hub; run **only when `AXE_API_KEY` is set**; skip if absent → record in `coverage` |

Key flags:

- `--base-url=<BASE_URL>` (all lanes).
- `--max-pages=N` on the sitemap-crawling lanes; caps the crawl. Cap for speed during iteration, but **record the cap in `coverage`** (a capped run is partial coverage). `all` or `0` = no cap.
- `--a11y-profile=standard|comprehensive` (default `comprehensive`). For a **legal-compliance** audit, run `standard`; for the CI-equivalent superset, `comprehensive`. The profile is captured per lane in the emitted JSON.

**Exit code:** a11y lanes exit **non-zero on critical/serious findings or an incomplete run** (errored pages, crawl failure). Non-zero is a signal, not a crash; **read the JSON regardless of exit code**: `summary.status` distinguishes `findings-found` from `incomplete` (these lanes emit `pass` / `findings-found` / `incomplete`; the broader envelope enum in `../reference/finding-format.md` covers other sources). (This differs from `utest:lint`, which exits 0 on findings.)

**Don't re-run if fresh findings exist.** If the lanes already ran recently (e.g. via `utest:all`), read the existing per-lane files instead of re-running. Reports all land under `…/test-reports/`.

## Step 4: Locate each lane's JSON

Each lane writes `public://test-reports/<lane>/test-suite-findings.json`. Resolve the real dir through drush (handles custom file locations / containers):

```bash
<DRUSH> php:eval "echo \Drupal::service('file_system')->realpath('public://test-reports');"
```

Then read `<resolved>/<lane>/test-suite-findings.json` for each lane dir (`axe-full`, `alfa-full`, `pa11y`, `reflow`, `meta-viewport`, and `axe-watcher-full` when that lane ran). Note the lane *output dirs* keep their historical names; `utest:axe` writes to `axe-full/`, `utest:alfa` to `alfa-full/`, `utest:axe-watcher` to `axe-watcher-full/`. Fallback: `<repo-root>/web/sites/default/files/test-reports/<lane>/test-suite-findings.json`. A missing file for a lane you ran → that lane is **skipped** in coverage (with reason), not clean.

## Step 5: Aggregate + normalize into ONE envelope

Validate each file's `schema_version` major (`1`), then merge:

1. **Envelope** = `suite: "accessibility"`, `source { adapter: "drush", command, base_url: <BASE_URL>, captured_at }`. Re-aggregate `summary.totals_by_severity` / `totals_by_impact` across all lanes; `pages_tested` = max across lanes (or per-lane, see coverage). Set the envelope's top-level `gate` from the lanes (all a11y lanes emit the same `{ fails_build: true, severities: ["critical", "serious"] }`) and keep each lane's own `gate` in `coverage.lanes`.
2. **`coverage`**: the honesty surface for a11y:
   - `engines_run` = the lanes that produced findings files.
   - `coverage.lanes` = per-lane detail: `{ engine, test, profile_key, status, pages_tested, pages_errored, gate, n_findings }`. This is where the caveats live (see Step 6). Pass through `summary.status` verbatim (full enum: `pass`/`fail`/`findings-found`/`skipped`/`incomplete`/`error`) and `summary.pages_errored` when present, an `incomplete` lane is never a clean pass.
   - `engines_skipped` = lanes not run / missing output, with reason (e.g. `axe-watcher: no AXE_API_KEY`).
3. **Per finding**: copy ground-truth verbatim (`id`, `rule_id`, `rule_url`, `severity`, `impact_category`, `headline`, `description`, `fix_hint`, `wcag_criteria`, `occurrences`, `locations`), then add ours:
   - `suite: "accessibility"`.
   - `engine`: from the lane's `tool` field (`axe-core`, `siteimprove-alfa`, `pa11y-ci`, `playwright-reflow`, `playwright-meta-viewport`). Cleaner than the `id` prefix for a11y. Pass unknown tools through.
   - `fix`: all `null` (the diagnostic enriches).
   - `data_sensitivity: null`, `requires_auth: null`.
   - `tags`: default `[]` when absent.
   - **WCAG enrichment:** `wcag_criteria` is often `[]` even for a11y findings (verified live). Recover the SC from `rule_id` when it encodes one (pa11y: `WCAG2AA.Principle2.Guideline2_4.2_4_1…` → `2.4.1`, level AA) and from the lane's `profile.tags`; populate `wcag_criteria` and/or a `wcag2aa`-style `tag`. The diagnostic relies on this to classify legal vs aspirational.
   - `locations` are `kind: "page"` with `path` (URL) + `selector`: pass through faithfully; do **not** try to turn them into file paths (that mapping is the remediation step's job).

## Step 6: Coverage caveats to record (a11y false-clean traps)

Capture these in `coverage.lanes` so the diagnostic and user aren't misled:

- **Alfa** emits only `critical`/`serious` (`profile.severity_levels`); 0 Alfa findings ≠ moderate/minor clean; it didn't check them.
- **pa11y** has no `profile` and defaults `impact_category` to `uncategorized`: pass through; the diagnostic refines impact.
- **axe-full** carries a rich `profile` (`comprehensive` → `wcag2a…aaa` + `best-practice`) and real `impact_category` (e.g. `screen-reader`).
- **Capped `--max-pages`** or a thin sitemap → low `pages_tested` → partial coverage. Record the number.
- A finding on a **core route** (`/core/install.php/*`, `/admin/*`) is likely upstream, not custom; pass it through; the diagnostic does custom-vs-upstream attribution.

## Step 7: Hand off

Emit the single aggregated envelope. Sanity-check `coverage` first; skipped/capped lanes get surfaced now, never hidden. Then hand to the **`drupal-accessibility-diagnostic`** agent.

## Failure modes: report honestly

| Symptom | Likely cause | Do |
| --- | --- | --- |
| Site not reachable / no `BASE_URL` | site down, wrong URL | Stop; a11y needs a live site. |
| No sitemap | sitemap module off | Lanes fall back to a few paths; record thin coverage. |
| Lane exits non-zero | critical/serious findings **or** an incomplete run | Read the JSON; `summary.status` says which. Findings ≠ failure; `incomplete` ≠ clean. |
| `summary.pages_errored` > 0 / `status: incomplete` | pages timed out or errored during the crawl | Pass through in `coverage.lanes`; the lane is partial, never a pass. |
| No `test-suite-findings.json` for a run lane | playwright/deps failed mid-run | Mark the lane skipped with reason. |
| `utest:axe-watcher` produced nothing | no `AXE_API_KEY` | Expected; record skipped. |
| `schema_version` major unknown | suite upgraded contract | Refuse that lane; report the version. |

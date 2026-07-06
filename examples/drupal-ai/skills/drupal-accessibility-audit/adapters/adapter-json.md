# Adapter: json (existing a11y report file)

Ingests **existing** accessibility report JSON; no drush, no running lanes, no live site. Use when the user hands you a11y findings from CI (a pipeline artifact) or a path/URL to one, or has already run the lanes and wants them triaged.

Like the a11y drush adapter, this produces **one aggregated** envelope; a11y is one file per engine, so expect several and merge them.

## Step 1: Locate the report(s)

Accept a **file**, **URL**, or **directory**. A11y CI artifacts usually contain several `…/test-reports/<lane>/test-suite-findings.json` files (`axe-full`, `alfa-full`, `pa11y`, `reflow`, `meta-viewport`). Read every a11y lane you find and record which.

## Step 2: Detect the format

1. **Our v1.0 envelope** (`test-suite-findings.json`); pass-through; version-gate on `schema_version` major (`1`).
2. **Raw engine JSON**: a native axe-core, pa11y-ci, or Alfa result file (not yet normalized). Map it via `../reference/severity-levels.md` (the accessibility per-engine mappings): each violation/issue → a finding; a `page` location from the URL + the engine's node/`selector`; `impact` → severity; carry WCAG tags. Pass unknown engines through best-effort.

## Step 3: Aggregate + normalize (same target as the drush adapter)

Merge all lanes into ONE envelope, `suite: "accessibility"`:

- Copy ground-truth fields verbatim (v1.0), or map raw output. Add ours: `suite`, `engine` (from `tool`), `fix` = `null`, `data_sensitivity`/`requires_auth` = `null`, `tags` default `[]`.
- `locations` stay `kind: "page"` (URL + `selector`); pass through; don't convert to file paths.
- **WCAG enrichment:** when `wcag_criteria` is empty, recover the SC from `rule_id` (pa11y encodes it, e.g. `WCAG2AA.…2_4_1` → `2.4.1`) and the lane's `profile.tags`: the diagnostic needs this to classify legal vs aspirational.
- Re-aggregate `summary` totals across lanes.

## Step 4: Build `coverage` honestly (the a11y false-clean traps still apply, even from a file)

A static report is only as honest as what it contains, and a11y is full of traps:

- `coverage.lanes` = per-lane `{ engine, profile_key, status, pages_tested, pages_errored, n_findings }` from each file. Pass `summary.status` and `summary.pages_errored` through verbatim; a lane with `status: incomplete`/`error` (or `pages_errored > 0`) is never a clean pass.
- **Which profile?** Read each lane's `profile.key`. If the report ran `standard`, that's the legal bar; if `comprehensive`, the diagnostic splits legal vs aspirational. If a report carries no profile (pa11y), note it.
- **Partial coverage:** low `pages_tested` (capped crawl / thin sitemap) = partial. **Alfa** files only cover critical/serious. Missing lanes = unknown, not clean. Surface all of this.

## Step 5: Hand off

Emit the aggregated envelope and hand to the **`drupal-accessibility-diagnostic`** agent. Flag stale/partial reports, the user may want a fresh run (the drush adapter) for current pages.

## Failure modes

| Symptom | Do |
| --- | --- |
| Path/URL not found | Stop; report what you looked for. |
| JSON won't parse | Report the error; don't fabricate. |
| `schema_version` major unknown | Refuse that file; report the version. |
| Only one lane's file present | Normalize it, but scope `coverage` to that lane; don't imply full a11y coverage. |
| No profile info anywhere | Note it; the diagnostic can still classify from WCAG levels in `rule_id`, with lower confidence. |

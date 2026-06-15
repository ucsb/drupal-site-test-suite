# Adapter: html (hosted report URL)

Ingests a **hosted HTML report** when the user gives you a URL instead of a file. The golden rule: **don't scrape the HTML — fetch the structured JSON behind it.** The HTML report is a *rendering* of the same `test-suite-findings.json` files; those are lossless and live right next to it.

## Step 1 — Derive the JSON URL(s) from the report URL

The suite publishes reports under `…/sites/default/files/test-reports/`:

- **Unified report:** `…/test-reports/index.html` → the per-lane data is at `…/test-reports/<lane>/test-suite-findings.json`. For linting, fetch `…/test-reports/lint/test-suite-findings.json`.
- **Per-tool report:** `…/test-reports/lint/lint-report.html` → the sibling `…/test-reports/lint/test-suite-findings.json` in the same directory.

So from the report URL, compute the sibling `lint/test-suite-findings.json` URL.

## Step 2 — Fetch the JSON and delegate

Fetch that JSON and run it through the **json adapter** (`adapter-json.md`) — same validation, normalization, path-handling, and coverage logic. This adapter is essentially "resolve the URL, then hand to json."

## Step 3 — Fallback: scrape only if the JSON is unreachable

If the sibling JSON 404s or isn't served (only the HTML exists), then and only then parse the HTML report's findings table — and treat it as **degraded**:

- You'll typically recover `headline`/`severity`/`rule`/`file` per row, but may lose `occurrences`, exact `locations`, `fix_hint`, etc.
- Mark `coverage` as `source: html-scrape` and note the lossiness.
- Prefer asking the user for the JSON (or the artifact directory) over relying on a scrape — say so.

## Step 4 — Hand off

Emit the `suite: "linting"` envelope and hand to the **`drupal-code-quality-diagnostic`** agent. Note in `source` that the input was an HTML URL (and whether you used the JSON-behind-it or had to scrape).

## Failure modes

| Symptom | Do |
| --- | --- |
| URL unreachable | Stop; report it. |
| `index.html` reachable but sibling JSON 404 | Try the per-lane path; if still missing, fall back to scrape (degraded) and say so. |
| HTML structure unrecognized on scrape | Recover what you can; flag the rest; recommend the JSON path. |
| Report is stale | Surface `generated_at`; offer a fresh `drush utest:lint` run instead. |

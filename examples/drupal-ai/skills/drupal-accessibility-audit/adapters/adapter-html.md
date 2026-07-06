# Adapter: html (hosted a11y report URL)

Ingests a **hosted HTML report** when the user gives a URL. Same golden rule as everywhere: **don't scrape the HTML; fetch the structured JSON behind it.** The unified report renders the same per-lane `test-suite-findings.json` files, which are lossless and sit right beside it.

## Step 1: Derive the per-lane JSON URLs

Reports live under `…/sites/default/files/test-reports/`:

- **Unified report:** `…/test-reports/index.html` → fetch each a11y lane's data at `…/test-reports/<lane>/test-suite-findings.json` for `axe-full`, `alfa-full`, `pa11y`, `reflow`, `meta-viewport`. (Not every lane will exist; fetch the ones that do.)
- **Per-tool report:** `…/test-reports/<lane>/<lane>-report.html` → the sibling `…/test-reports/<lane>/test-suite-findings.json`.

## Step 2: Fetch and delegate

Fetch the lane JSONs and run them through the **json adapter** (`adapter-json.md`); same aggregation, WCAG enrichment, profile handling, and coverage logic. This adapter resolves URLs; the json adapter does the work.

## Step 3: Fallback (scrape only if JSON is unreachable)

If the sibling JSONs aren't served (HTML only), parse the unified report's findings, treating it as **degraded**:

- HTML a11y reports often drop the `selector`, exact `wcag_criteria`, and per-engine detail; you may recover only headline/severity/page. That cripples the diagnostic's custom-vs-upstream attribution and legal classification.
- Mark `coverage` `source: html-scrape` and say the profile/selector data may be missing. Strongly prefer asking the user for the JSON or artifact directory.

## Step 4: Hand off

Emit the aggregated `suite: "accessibility"` envelope and hand to the **`drupal-accessibility-diagnostic`** agent. Record in `source` that the input was an HTML URL and whether you used the JSON-behind-it or scraped.

## Failure modes

| Symptom | Do |
| --- | --- |
| URL unreachable | Stop; report it. |
| `index.html` reachable but lane JSONs 404 | Try per-lane paths; if missing, scrape (degraded) and say so. |
| Scrape loses `selector`/profile | Flag that attribution + legal classification are weakened; recommend the JSON path. |
| Report is stale | Surface `generated_at`; offer a fresh run of the per-lane a11y commands (`utest:alfa`, `utest:axe`, `utest:pa11y`, `utest:reflow`, `utest:meta-viewport`). |

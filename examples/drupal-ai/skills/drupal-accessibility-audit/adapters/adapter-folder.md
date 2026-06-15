# Adapter: folder (no test suite — run a11y tools directly)

Audits accessibility when the `utest` test suite is **not installed**, by running a11y engines directly against the site and normalizing their output. This is the heaviest adapter: unlike linting (static code), a11y needs a **running, reachable site + a browser engine**, so it depends on more moving parts and is **best-effort** — be loud about what couldn't run.

Prefer installing the `utest` suite (curated profiles, five engines, unified report) and using the drush adapter. Use this only when that isn't available.

## Step 1 — Require a reachable site

A11y tests rendered pages, so you need a live `BASE_URL` (flag → `BASE_URL` env → fail). Verify it responds before doing anything. No reachable site → you cannot do a real a11y audit (see the static fallback in Step 5, which is weak). A sitemap (`/sitemap.xml`) lets you cover more than the homepage.

## Step 2 — Detect / acquire an engine

Probe for a11y tooling; record availability with install hints:

| Engine | Looks for | If missing |
| --- | --- | --- |
| pa11y-ci | `node_modules/.bin/pa11y-ci` or `npx pa11y-ci` | offer `npx pa11y-ci` (downloads on first use); or hint `npm i -D pa11y-ci` |
| axe-core CLI | `@axe-core/cli` / `npx @axe-core/cli` | offer `npx @axe-core/cli`; needs a browser |
| playwright + axe | `node_modules/.bin/playwright` + `@axe-core/playwright` | hint to install; needs `npx playwright install` browsers |

You don't need all of them — one engine (pa11y-ci or axe) is enough for a baseline. Record which ran. If none can run (offline, no browser), go to the Step 5 static fallback and mark coverage accordingly.

## Step 3 — Pick the profile (default to the legal bar)

Without the suite's `a11y-profiles.js`, default to the **standard** profile — **WCAG 2.0/2.1 A + AA**, the current legal bar — by running the engine at `WCAG2AA` (pa11y `--standard WCAG2AA`; axe tags `wcag2a,wcag2aa,wcag21a,wcag21aa`). Note in coverage that no `comprehensive` (AAA/best-practice/2.2) config is in play, so aspirational findings won't appear. Let the user opt into a wider tag set if they ask.

## Step 4 — Run against pages

- Build a path list: the **sitemap** (cap to a sane number, e.g. 25–50, and record the cap) or an explicit list the user gives. Fall back to `/` + `/user/login` (universal Drupal routes) and record the thin coverage.
- Run the engine per page, capturing **JSON** output (`pa11y-ci --json`, axe JSON reporter).
- **If the caller named a single target** (a custom module/theme): the scan still crawls whole rendered pages — there is no per-component scan flag — so run it normally, then in Step 6 **keep only findings whose selector/source maps to that target's** Twig/CSS/config, and record in coverage that pages were crawled site-wide but findings were filtered to the target.

## Step 5 — (Fallback) static template checks, clearly labeled weak

If no engine/browser is available at all, do a limited **static** pass over custom Twig templates only — e.g. `<img>` without `alt`, form inputs without an associated `<label>`, missing `lang` on `html.html.twig`, `user-scalable=no` in a viewport meta. This catches a *small* subset and **cannot** see contrast, focus, reflow, or anything in the rendered DOM. Mark `coverage` `source: static-template-scan` and say plainly it is not a real a11y audit.

## Step 6 — Normalize (same target as the a11y drush adapter)

Map each engine's native output into the finding schema (the accessibility direct-run path of `../reference/severity-levels.md`):

- `locations` = `kind: page`, `path` = URL, `selector` from the engine's node.
- Severity from the engine's impact/type via the a11y mappings; `impact_category` by disability where derivable, else `uncategorized`.
- **WCAG:** recover the SC from the engine's rule code (pa11y emits `WCAG2AA.…`) into `wcag_criteria`/tags — the diagnostic needs it for legal vs aspirational.
- Add ours: `suite: "accessibility"`, `engine`, `fix` = `null`, `data_sensitivity`/`requires_auth` = `null`, `tags` default `[]`.
- Aggregate all engines/pages into ONE envelope.

## Step 7 — Coverage (be very honest)

- `engines_run` + `coverage.lanes` (engine, profile≈standard, pages_tested, n_findings); `engines_skipped` with hints.
- Record the **profile** (standard-only here), the **page cap**, and whether this was a real engine run or the static fallback.
- Reminder for the diagnostic/user: page-located findings still need **custom-vs-upstream attribution**, and automated coverage is partial.

## Step 8 — Hand off

Emit the aggregated `suite: "accessibility"` envelope and hand to the **`drupal-accessibility-diagnostic`** agent. Recommend installing the `utest` suite for curated, multi-engine, profile-aware runs. Always carry the standing caveat: automated a11y ≠ accessible — **human testing required** (screen reader, keyboard, zoom).

## Failure modes

| Symptom | Do |
| --- | --- |
| Site not reachable | Stop (or static fallback, clearly labeled). A11y needs rendered pages. |
| No engine + no browser | Static fallback only; mark coverage as not-a-real-audit. |
| No sitemap | Crawl `/` + a couple of known routes; record thin coverage. |
| Engine errors on a page | Capture what you got; mark that page/engine partial. |

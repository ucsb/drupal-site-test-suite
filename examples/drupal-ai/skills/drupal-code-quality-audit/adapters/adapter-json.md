# Adapter: json (existing report file)

Ingests an **existing** code-quality report — no drush, no running tests, no live site. Use when the user hands you a CI/CD JSON report or a path/URL to one (e.g. a pipeline artifact), or when they've already run the suite and just want the findings triaged.

This adapter never executes anything; it reads and normalizes. That makes it the entry point for findings produced **outside** a live `utest:lint` run — including CI-only checks the drush path can't surface, like **gitleaks/SARIF secret scans**.

## Step 1 — Locate the report

Accept any of: a **local file path**, a **URL** (fetch it), or a **directory** (search for `test-suite-findings.json`). For linting the canonical file is `…/test-reports/lint/test-suite-findings.json`. If given a directory of CI artifacts, read every relevant report you find (and say which).

## Step 2 — Detect the format

Two shapes arrive here; detect and branch:

1. **Our v1.0 envelope** (`test-suite-findings.json`) — has `schema_version`, `summary`, `findings[]`. This is the same contract the drush adapter reads, so it's a **near pass-through**. Version-gate on `schema_version` major (`1`); refuse unknown majors.
2. **Raw tool output / SARIF** — a single engine's native JSON (phpcs JSON, ESLint JSON) or a **SARIF** file (common for gitleaks, CodeQL, and other CI security scanners). There's no pre-normalized severity here, so you **map it yourself** using `../reference/severity-levels.md` (the direct-run mappings) — exactly as the folder adapter would. For SARIF: each `result` → a finding; `ruleId` → `rule_id`; `level` (error/warning/note) → severity via the mapping; `locations[].physicalLocation.artifactLocation.uri` + `region.startLine` → a `file` location.

If the shape is unrecognizable, normalize best-effort and flag it in `coverage` rather than dropping data.

## Step 3 — Normalize

Same target as the drush adapter:

- **v1.0 envelope:** copy ground-truth fields verbatim (`id`, `rule_id`, `severity`, `impact_category`, `headline`, `description`, `fix_hint`, `wcag_criteria`, `occurrences`, `locations`, `tags`).
- **Raw/SARIF:** construct `id` deterministically (`{engine}:{rule_id}:{path}:{line}`), map `severity` via `severity-levels.md`, set `impact_category` (`security` for gitleaks/CVE, else `code-quality`).
- Then add ours on every finding: `suite: "linting"`, `engine` (from envelope `tool`/`id` prefix, or the tool that produced the raw file), `fix` = all `null`, `data_sensitivity`/`requires_auth` = `null`, `tags` default `[]`.
- **Path normalization + scope-guard** — identical to the drush adapter: use `file` paths as-is when they resolve from the repo root, else probe `web/modules/`/`web/themes/`/`web/profiles/`; drop locations under core/contrib/`vendor/`/`node_modules/`.

## Step 4 — Build `coverage` honestly (a static report only knows what it contains)

You didn't run anything, so be conservative:

- v1.0 envelope → `engines_run` from its `tool`; carry its `summary`.
- Single raw/SARIF file → `engines_run` is **just that one engine**. Do **not** imply the others are clean — they simply aren't in this report. Note that in `coverage`.
- If you were given several artifact files, merge them and list every engine seen; flag any expected-but-absent engine as unknown, not clean.

## Step 5 — Hand off

Emit the envelope (`suite: "linting"`) and hand to the **`drupal-code-quality-diagnostic`** agent. If the report is stale (old `generated_at`) or partial, say so — the user may want a fresh `drush utest:lint` run instead (the drush adapter).

## Failure modes

| Symptom | Do |
| --- | --- |
| Path/URL not found or unreadable | Stop; report what you looked for. |
| JSON won't parse | Report the parse error; don't fabricate findings. |
| `schema_version` major unknown | Refuse that file; report the version. |
| Single-engine file presented as a full audit | Normalize it, but **loudly** scope `coverage` to that one engine. |
| SARIF with no Drupal-relative paths | Map best-effort; flag locations you couldn't resolve to repo-relative. |

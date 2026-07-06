# Adapter: drush (default runtime path)

Produces a normalized findings envelope (`../reference/finding-format.md`) by running the project's own `utest:*` linting lane and reading the `test-suite-findings.json` it emits. This is the **default** adapter whenever a live Drupal checkout with the test suite is present; it guarantees the same engines and config as CI, and it's a near pass-through because the suite already emits our schema's ground truth.

Use this when: the user points at a Drupal checkout, a working `drush` resolves, and `utest:*` commands exist. If there's no test suite, fall back to the folder adapter (direct tool runs); if the user hands you an existing report file/URL, use the json/html adapter instead of re-running.

## Step 1: Resolve drush (ordered probe)

The runtime constraint: utest runs in the **same working tree** as the target site. Probe these in order and use the **first that responds**; call the resolved invocation directly every time after (never assume a `drush` on `PATH`: that's often just a shell alias that won't exist in a non-interactive run):

1. `vendor/bin/drush` (Composer-local; most common; preferred because it's the version the project pins)
2. `ddev drush` (if `.ddev/` present and DDEV is up)
3. `lando drush` (if `.lando.yml` present and Lando is up)
4. `drush` (global on `PATH`)

Probe with a cheap, bootstrap-free command:

```bash
<candidate> version --format=string      # e.g. "12.5.3.0" → this candidate works
```

Record the winner as `DRUSH` for the rest of the run. If none respond, stop and report: this site can't be audited via drush; offer the folder adapter (direct tool runs) instead.

> Containerized runtimes (ddev/lando) resolve the working tree *inside* the container. The report paths in Step 4 are inside that filesystem; read them via `<DRUSH> ...` resolution (Step 4), not by guessing the host path.

## Step 2: Discover the utest surface (don't hardcode)

List commands and confirm the linting lane exists rather than assuming a fixed command set:

```bash
<DRUSH> list --format=json     # parse for command names starting with "utest:"
```

For the **linting audit** you want `utest:lint`. Note what's present; the surface varies by site and version. Known lanes you may see: `utest:lint` (the one you want), `utest:phpunit`, `utest:check-config`, `utest:all`, `utest:report-render`, plus the per-lane a11y commands (`utest:alfa`, `utest:axe`, `utest:axe-watcher`, `utest:pa11y`, `utest:reflow`, `utest:meta-viewport`: those belong to the accessibility suite, not this one). If `utest:lint` is absent, fall back to the folder adapter.

Optional but recommended preflight (surfaces missing Node/deps before a long run):

```bash
<DRUSH> utest:check-config            # PASS/WARN/FAIL; non-blocking, informational
```

## Step 3: Run the linting lane

```bash
<DRUSH> utest:lint
```

- Scope is **custom code only**: the lane scans custom modules/themes/profiles (+ repo Markdown, `.github/workflows/**`) and never core/contrib/`vendor/`/`node_modules/`. You don't pass scope flags for a full audit.
- Narrow when asked: `--modules=<name,name>`, `--themes=<name,name>`, or `--profiles=<name,name>`: plural, comma-separated machine names; each is a real filter.
- **Exit code is not a pass/fail signal.** `utest:lint` deliberately does *not* throw when it finds issues; "findings exist" is reported via the JSON `summary.status` (`findings-found`), not a non-zero exit. Don't treat a clean exit as "no findings." Read the JSON.
- Engines this lane *can* bundle (it runs `tests/code-quality/lint-orchestrator.js`): **static-analysis** `phpcs`, `phpstan`; **linting** `eslint`, `stylelint`, `htmlhint`, `twigcs`, `yaml-lint`, `markdownlint`; **spelling** `cspell`; plus orchestrator-level `composer` (validate+audit), `actionlint`, `editorconfig`, `deprecations` (compat.*), `references` (libraries/twig/template asset+dependency). The exact set that actually ran is in the emitted `tool` field; **read it, don't assume**. The lane **lazy-loads and self-skips engines whose tool isn't installed** (e.g. stylelint/htmlhint), so an engine missing from `tool` means it was skipped, not clean; record it in `coverage.engines_skipped`. (Live runs commonly show only the 9 that are installed: `phpcs+phpstan+deprecations+references+eslint+cspell+composer+markdownlint+actionlint`.)
- **Secret scanning is NOT in this lane.** `gitleaks` is configured under `tests/code-quality/security/` but `utest:lint` does **not** invoke it; it runs separately (a CI security job). So this adapter never surfaces secret findings. Record in `coverage` that **secret scanning was not performed**, so a clean lint audit isn't mistaken for "no secrets." (gitleaks findings can still enter the pipeline via a CI report through the json/html adapter; the diagnostic and `severity-levels.md` know how to handle them; `critical`, `security`, remove-and-rotate.)
- **Don't re-run if fresh findings already exist.** If the user just ran `drush utest:all` (which runs every lane (`lint`, `phpunit`, and the a11y lanes) then builds the unified report), the lint findings are already at `…/lint/test-suite-findings.json`. Read the existing file instead of re-running, unless the user wants a fresh scan or the file is stale/absent. `utest:all` does not write a combined JSON, the lint lane's file is the one you want for a linting audit. (`phpunit` is a separate Functional/Regression lane, not linting; ignore it here.)

## Step 4: Locate and read the findings JSON

Every lane writes `test-suite-findings.json` into `public://test-reports/<lane>/`. For linting that's `public://test-reports/lint/`. Resolve the real path through drush (handles custom public-files locations and container filesystems):

```bash
<DRUSH> php:eval "echo \Drupal::service('file_system')->realpath('public://test-reports/lint');"
```

Read `<resolved>/test-suite-findings.json`. **Fallbacks**, in order, if `php:eval` fails (e.g. site won't bootstrap) or the file is missing:

1. `<repo-root>/web/sites/default/files/test-reports/lint/test-suite-findings.json` (the command's own fallback path).
2. Parse the `utest:lint` stdout; it prints the report location.

If you still can't find it: the lane didn't produce output (deps missing, JS install failed, bootstrap error). Report that honestly as a **skipped** run in `coverage`: do **not** emit an empty-but-clean envelope.

## Step 5: Validate, then normalize (near pass-through)

The file already matches our ground-truth schema, so normalization is mostly copying.

1. **Version gate.** Read `schema_version`. If its *major* isn't `1` (current `1.0`), refuse and report; don't guess at an unknown shape.
2. **Copy envelope fields** verbatim into the output envelope: `test`, `tool`, `surface`, `profile`, `generated_at`, `duration_ms`, `summary`. Add ours: `suite: "linting"`, `source { adapter: "drush", command: "<DRUSH> utest:lint", base_url, captured_at: <now ISO8601> }`.
3. **Build `coverage`.** `engines_run` = split the `tool` string on `+`. `engines_skipped`: if `summary.status === "error"` or an engine the lane normally runs is absent from `tool`, record it with a best-effort reason. When unsure, leave `engines_skipped` empty rather than inventing reasons; but never claim an engine ran if the data doesn't show it.
4. **Per finding; copy ground-truth fields verbatim** (`id`, `rule_id`, `rule_url`, `severity`, `impact_category`, `headline`, `description`, `fix_hint`, `wcag_criteria`, `occurrences`, `locations`, `tags`). Then add ours:
   - `suite: "linting"`.
   - `engine`: derive from the `id` prefix (`lint:phpcs:…` → `phpcs`); if the id isn't prefixed, fall back to matching `rule_id` against the `tool` list. Pass unknown engines through as-is.
   - `fix`: all `null`. **The adapter never decides fixability**: the diagnostic agent does.
   - `data_sensitivity: null`, `requires_auth: null` (public-page scope today).
   - **Trust the emitted `severity`**: do not re-map (the emitter already normalized it; see `severity-levels.md`).
5. **Confirm `file` paths are repo-relative (tolerate older suites), then scope-guard.** Current suite versions run a **normalization pass so every lane emits consistent repo-relative paths**: so normally you use each `file` path as-is. **Older suite versions emitted mixed prefixes**: phpcs/eslint computed paths relative to their *scope root* (e.g. `web/modules` → `custom/my_module/my_module.module`, `web/themes` → `custom/my_theme/my_theme.theme`; custom code can sit under a `custom/` subdirectory or directly at the `web/modules`/`web/themes`/`web/profiles` root, so the relative path may also be just `my_theme/my_theme.theme`) while deprecations/references used the repo root. Be tolerant of both so the skill works on any suite version: use the path as-is when it resolves from the repo root (the normal case); **only when it doesn't**, fall back to probing under `web/modules/`, `web/themes/`, `web/profiles/` and use the first that exists. Doing this **before** dedup keeps `id`-based dedup and file-resolution correct regardless of suite version. Then **scope-guard:** if a resolved `file` location lands under core/contrib/`vendor/`/`node_modules/`, drop that location; if a finding is left with no in-scope location, drop the finding and note the count in `coverage`. Never pass an out-of-scope or unresolvable path downstream.

## Step 6: Hand off

Emit the single envelope. Sanity-check `coverage` before returning:

- Engines **skipped** → surface to the user now. A near-empty report because a tool didn't run is **not** a clean bill of health.
- `findings` empty **and** nothing skipped → report the clean pass and stop.
- Otherwise → hand the envelope to the **`drupal-code-quality-diagnostic`** agent (registered read-only subagent), which enriches each `fix` block and produces the prioritized audit.

## Failure modes: report honestly, never fake a clean pass

| Symptom | Likely cause | Do |
| --- | --- | --- |
| No drush candidate responds | no Composer install / no container up | Stop; offer folder adapter. |
| `utest:lint` not in `drush list` | site lacks the test suite | Fall back to folder adapter. |
| `php:eval` errors / site won't bootstrap | no DB, broken settings | Use Step-4 fallbacks; if all fail, mark skipped. |
| No `test-suite-findings.json` after run | npm install / lint-orchestrator failed | Mark the run **skipped** in `coverage` with the reason. |
| `schema_version` major unknown | suite upgraded its contract | Refuse; report the version seen. |
| `summary.status === "error"` | an engine crashed | Emit findings you got; list the failed engine in `engines_skipped`. |

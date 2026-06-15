# Normalized Finding Format

The common schema every input adapter produces and every agent consumes. The whole point: a PHPCS violation, an ESLint error, and a composer-audit CVE all arrive at the diagnostic agent in *the same shape*, so audit and remediation logic never special-case where a finding came from.

Adapters are the *only* components that know about tool- or report-specific output. Once an adapter emits a `Finding`, downstream agents treat all findings identically.

## Ground truth: align to the project's emitted schema

Any Drupal 10/11 site that installs the `utest` test suite already emits a normalized findings contract — **this is the source of truth**, not a spec we invented:

> `tests/reports/_shell/findings.schema.json` (JSON Schema 2020-12), emitted by `tests/accessibility/utils/findings-emitter.js` and the per-lane emitters into `public://test-reports/<lane>/test-suite-findings.json`.

Our `Finding` is a **superset** of that schema: identical field names where the schema has them (so an adapter reading a `test-suite-findings.json` is a near pass-through), plus a few fields *we* own (`suite`, `engine`, the enriched `fix` block, and the reserved `data_sensitivity` / `requires_auth` seam). Never rename a ground-truth field — that forces lossy remapping and breaks dedup against the unified report.

**Version discipline.** Refuse to consume a `schema_version` whose *major* you don't recognize (current: `1.0`). The suite is **actively growing** — new lanes/engines can land before the schema's `test`/`tool` enums catch up (the enum has already been amended once to add `axe-full`/`meta-viewport`/`phpunit`) — so **pass unknown `test`/`tool`/engine values through** with best-effort normalization; never hard-validate against the enum.

## The Finding object

Each finding is one discrete, addressable problem. One PHPCS sniff failure = one finding (with one or more `locations`). One axe rule firing on a page = one finding (with one location per offending node).

```json
{
  "id": "lint:phpcs:Drupal.Commenting.FunctionComment.Missing",
  "suite": "linting",
  "engine": "phpcs",
  "rule_id": "Drupal.Commenting.FunctionComment.Missing",
  "rule_url": "https://www.drupal.org/docs/develop/standards",
  "severity": "serious",
  "impact_category": "code-quality",
  "headline": "Missing function doc comment — 3 instances",
  "description": "Missing function doc comment.",
  "fix_hint": "Add a /** … */ docblock above the function. `phpcbf` can scaffold it.",
  "wcag_criteria": [],
  "occurrences": 3,
  "locations": [
    { "kind": "file", "path": "web/modules/custom/foo/foo.module", "line": 42, "column": 1, "selector": null, "snippet": "function foo_help($route_name) {", "occurrences": 1 }
  ],
  "tags": ["coding-standard"],
  "fix": {
    "autofixable": null,
    "strategy": null,
    "confidence": null,
    "suggestion": null,
    "diff": null
  },
  "data_sensitivity": null,
  "requires_auth": null
}
```

### Field reference — ground-truth fields (mirror `findings.schema.json`)

| Field | Type | Req | Notes |
| --- | --- | --- | --- |
| `id` | string | yes | Stable dedup identity. **Reuse the upstream `id` verbatim** when reading a `test-suite-findings.json` (e.g. `lint:phpcs:…`, `alfa:SIA-R111`). Only *construct* one when parsing raw tool output (see **ID construction**). |
| `rule_id` | string | yes | Engine-native rule id without the test prefix (`Drupal.Commenting…`, `image-alt`, `SIA-R111`). The lookup key for fix knowledge and suppression/baseline. Preserve verbatim. |
| `rule_url` | string\|null | no | Upstream rule docs link, when present. |
| `severity` | enum | yes | `critical` \| `serious` \| `moderate` \| `minor`. See `severity-levels.md` — the single source of truth. (No `info`; map "needs review" signals to `minor` + a tag.) |
| `impact_category` | enum | yes | `screen-reader` \| `keyboard` \| `low-vision` \| `content` \| `code-quality` \| `security` \| `uncategorized`. Linting defaults to `code-quality`; composer-audit CVEs → `security`. |
| `headline` | string | yes | Plain-language one-liner the site builder sees. |
| `description` | string\|null | no | Tool-native message/detail. |
| `fix_hint` | string\|null | no | Plain-language remediation hint from the tool/curated headlines. **This is the tool's hint, not our fix decision** — see the `fix` block below. |
| `wcag_criteria` | string[] | no | WCAG SCs the finding violates (`["1.1.1","4.1.2"]`). a11y only; `[]` for linting. |
| `occurrences` | int | yes | How many times the finding appears across all its locations. |
| `locations` | object[] | yes | Where it manifested. See below. May be `[]` for a project-wide finding. |
| `tags` | string[] | no | Free-form filters: `coding-standard`, `security`, `wcag2aa`, `best-practice`, `inconclusive`, `needs-review`. |

### Field reference — fields we own (extensions)

| Field | Type | Req | Notes |
| --- | --- | --- | --- |
| `suite` | enum | yes | `linting` \| `accessibility`. Set by the adapter. |
| `engine` | string | yes | The specific tool: `phpcs`, `phpstan`, `eslint`, `cspell`, `composer`, `markdownlint`, `actionlint`, `axe`, `alfa`, `pa11y`, `reflow`, `meta-viewport`, … Derived from the `id` prefix (`lint:phpcs:…` → `phpcs`) or the envelope `tool`. The upstream `lint` lane bundles many engines into one file — split them out here. |
| `fix` | object | yes | **Remediation metadata, enriched by the diagnostic agent — NOT the adapter.** Adapters set every field to `null` ("unknown"). See below. |
| `data_sensitivity` | enum\|null | no | Reserved seam for the future admin/PII work: `null` (unknown) \| `none` \| `pii` \| `restricted`. Adapters set `null` today (public-page scope). |
| `requires_auth` | bool\|null | no | Reserved seam: was this finding on an authenticated/admin surface? `null`/`false` today; future admin-page reports will set `true`. |

### `locations[]` (mirror upstream)

Not every engine fills every field. Code engines fill `path`/`line`/`column`; a11y engines fill `selector`/`snippet` against a page `path` (URL). Set unused fields to `null` so consumers can rely on the keys.

| Field | Type | Applies to | Notes |
| --- | --- | --- | --- |
| `kind` | enum | all | **`file`** = source file (remediable in source). **`page`** = rendered URL + DOM node (a11y; *not* a file:line — remediation must trace it back to Twig/content/CSS/config). This split is the single most important signal for whether a finding is source-fixable. |
| `path` | string | all | `file`: repo-relative POSIX path. `page`: URL path. Normalize file paths to **repo-relative** always — the #1 dedup-failure source. |
| `line` / `column` | int\|null | `file` | 1-indexed. |
| `selector` | string\|null | `page` | CSS selector for the offending DOM node. |
| `snippet` | string\|null | both | Offending source/markup line(s). |
| `occurrences` | int | both | Times this finding occurs at this specific location. |

### `fix` (ours — set by the diagnostic agent)

Adapters emit `fix` with all-`null` fields. The **diagnostic agent** fills it using the `tools/` fix knowledge; the **remediation agent** acts on it.

| Field | Type | Notes |
| --- | --- | --- |
| `autofixable` | bool\|null | True only if a mechanical, safe fix exists (`phpcbf`, `eslint --fix`, formatter). `null` until the diagnostic agent decides. Set `false` when unsure — a missed safe-fix is a minor inefficiency; a wrong auto-fix erodes trust. **Independent of severity.** |
| `strategy` | string\|null | `phpcbf`, `eslint-fix`, `stylelint-fix`, `cspell-add-word`, `manual`, … |
| `confidence` | enum\|null | `high` \| `medium` \| `low`. The remediation agent auto-applies **only `high`**. |
| `suggestion` | string\|null | Concrete proposed change (prose or pseudo-diff) for human review. |
| `diff` | string\|null | Unified diff from a dry-run, when computed. |

## ID construction

Prefer the upstream `id` verbatim. Only construct when reading raw tool output (the folder adapter / no-test-suite case), and make it **deterministic** — the same underlying problem yields the same id every run, so two adapters reporting the same issue dedup, and re-runs can be diffed against a baseline.

Join stable parts with `:` —

- **Code findings:** `{engine}:{rule_id}:{path}:{line}`
- **A11y findings:** `{engine}:{rule_id}:{url}:{selector}`

Normalize each part first (repo-relative path, trimmed selector). For a genuinely absent part use the literal `~` so the position is held: `cspell:unknown-word:~:~`. Never put `severity` or `description` in the id — those change across tool versions without the problem changing.

## The findings envelope

Adapters emit an envelope, not a bare array, so agents know provenance and coverage. It mirrors the upstream envelope plus our `suite`, `source`, and `coverage`.

```json
{
  "schema_version": "1.0",
  "suite": "linting",
  "source": {
    "adapter": "drush",
    "command": "vendor/bin/drush utest:lint",
    "base_url": null,
    "captured_at": "2026-06-05T17:00:00Z"
  },
  "test": "lint",
  "tool": "phpcs+phpstan+deprecations+references+eslint+cspell+composer+markdownlint+actionlint",
  "surface": { "context": "local", "label": "feature branch", "base_url": null },
  "profile": null,
  "generated_at": "2026-06-05T20:25:13.524Z",
  "duration_ms": 78330,
  "summary": {
    "totals_by_severity": { "critical": 0, "serious": 4, "moderate": 80, "minor": 41 },
    "totals_by_impact": { "code-quality": 124, "security": 0, "content": 1 },
    "status": "findings-found",
    "files_scanned": 5343,
    "pages_tested": null
  },
  "coverage": {
    "engines_run": ["phpcs", "phpstan", "deprecations", "references", "eslint", "cspell", "composer", "markdownlint", "actionlint"],
    "engines_skipped": []
  },
  "findings": [ /* Finding objects */ ]
}
```

- `summary`, `surface`, `profile`, `generated_at`, `duration_ms`, `test`, `tool` come straight from the upstream `test-suite-findings.json`.
- `source` and `coverage` are ours. **`coverage` matters most for audit honesty:** zero findings because a tool was *skipped* is completely different from zero findings because the code is clean. Agents must surface skipped engines, never imply a clean pass.
- When an adapter merges several lane files into one envelope (the a11y case — one file per engine), `coverage.engines_run` lists them all and `summary` totals are re-aggregated.

## Rules for adapter authors

1. **Pass through, don't editorialize.** When reading a `test-suite-findings.json`, copy ground-truth fields verbatim; don't drop findings you don't recognize — pass them through with best-effort `engine`/`severity` and let the diagnostic agent group them.
2. **Never set the `fix` block.** That's the diagnostic agent's job. Emit all-`null`.
3. **Repo-relative `file` paths, always.**
4. **Trust upstream `severity` for utest-sourced findings** (the emitters already normalized it); only *map* severity yourself when running a tool directly (folder adapter) — using `severity-levels.md`.
5. **Fill `coverage` honestly**, including skipped engines and the reason.
6. **Set `data_sensitivity`/`requires_auth` to `null` today.** They exist so the future admin/PII remediator is an additive change, not a schema break.
7. **Custom code only — drop or flag everything else.** In scope: custom modules/themes/profiles, custom drush commands, repo Markdown, `.github/workflows/**`. **Never** Drupal core, contrib, `vendor/`, `node_modules/` — we don't manage those, so a finding pointing there is noise. utest reports are already custom-scoped; when running tools directly, honor `tests/code-quality/config/custom-paths.json` + `*.info.yml` autodiscovery. If a `file` location resolves outside custom scope, drop the location (and the finding, if it has no in-scope locations left) and note it in `coverage`. Downstream, the remediation agent treats out-of-scope paths as **write-forbidden** regardless.

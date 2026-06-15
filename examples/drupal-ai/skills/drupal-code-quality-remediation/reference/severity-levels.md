# Severity Levels & Impact Taxonomy

The single source of truth for the `severity` and `impact_category` fields in `finding-format.md`. Every agent reasons about priority using only this scale. If you find yourself reading an engine's *native* severity downstream of an adapter, something leaked — fix the adapter.

This taxonomy is fixed by the project's emitted contract (`tests/reports/_shell/findings.schema.json`). Match it exactly; don't add levels.

## The normalized severity scale

**Four levels**, ordered most-to-least urgent (the upstream schema enumerates exactly these — there is no `info`):

| Severity | Meaning | Merge gating |
| --- | --- | --- |
| `critical` | Breaks the build, a security vulnerability, or an accessibility barrier that fully blocks a user (no keyboard access, content invisible to assistive tech). | **Blocks merge.** |
| `serious` | A real defect that will bite: type errors, behavior-affecting coding-standard violations, WCAG A/AA failures with clear user impact. | **Blocks merge.** |
| `moderate` | Genuine issue, lower impact. Most style/consistency violations, WCAG AAA, best-practice rules. | Does not block. |
| `minor` | Cosmetic or pedantic. Whitespace, formatting, ordering, spelling. | Does not block. |

**Merge-gating rule (both suites): block only on `critical` + `serious`.** Matches the project's CI policy — the comprehensive profile is *run*, but gating is restricted so AAA/best-practice noise doesn't wall the team off from merging.

**No `info` level.** Where an engine emits an advisory/"can't tell"/"notice" signal (Alfa `cantTell`, pa11y `notice`), normalize to **`minor`** and add a `tags` entry (`needs-review`, `inconclusive`) so triage can still surface it. Severity stays coarse; tags carry nuance.

## Impact category

`impact_category` is the user-facing axis the unified report groups by. Fixed enum:

`screen-reader` · `keyboard` · `low-vision` · `content` · `code-quality` · `security` · `uncategorized`

- **Linting** findings default to `code-quality`.
- **composer audit** CVEs / abandoned packages → `security`.
- **Accessibility** findings map by the disability they affect (`screen-reader`, `keyboard`, `low-vision`) or `content` for content-semantics issues; `uncategorized` only when genuinely unknown.

## Severity is not fixability

Keep these orthogonal — conflating them is the classic mistake:

- **Severity** = how much it matters → drives prioritization and gating.
- **`fix.autofixable` / `fix.confidence`** = whether a machine can safely fix it → drives automation.

A `minor` whitespace issue is trivially autofixable. A `critical` PHPStan type error is not autofixable at all. The remediation agent decides what to auto-apply from the `fix` block, **never** from severity.

## When do you map severity yourself?

- **Reading a `test-suite-findings.json` (drush/json/html adapters):** **trust the emitted `severity`** — the project's emitters already normalized it. Do *not* re-map. You may apply the conformance floor below as a safety check, taking `max(emitted, floor)`.
- **Running a tool directly (folder adapter / no test suite):** there's no emitter, so you map native output using the tables below.

### Per-engine mapping (direct-run only)

**Linting suite**

| Engine | Native signal | → Normalized |
| --- | --- | --- |
| **PHPCS** | `type: error` | `serious` |
| | `type: warning` | `moderate` |
| **PHPStan** | reported error (post-baseline) | `serious` |
| | baseline-suppressed | not emitted |
| **ESLint** | severity `2` (error) | `serious` |
| | severity `1` (warn) | `moderate` |
| **Stylelint** | `error` | `moderate` (style) / `serious` (a11y-plugin rules) |
| | `warning` | `minor` |
| **TwigCS / HTMLHint** | error | `moderate` (a11y-related markup errors → `serious`) |
| | warning | `minor` |
| **cspell** | unknown word | `minor` |
| **yaml-lint** | parse error | `serious` |
| | style warning | `minor` |
| **editorconfig-checker / markdownlint** | any | `minor` |
| **actionlint** | error | `serious` (workflow logic can break CI/CD) |
| **shellcheck** | error / warning / info | `serious` / `moderate` / `minor` |
| **composer audit** | CVE critical/high · medium · low | `critical` · `serious` · `moderate` |
| **composer validate** | invalid schema / lock drift | `serious` |
| **deprecations** | removed-in-next-major API | `serious` |
| **gitleaks** | any secret detected | `critical` |

**Accessibility suite** (map by whichever is more severe — engine adjective or WCAG level)

| Engine | Native signal | → Normalized |
| --- | --- | --- |
| **axe-core** | impact critical / serious / moderate / minor | same |
| | best-practice tag (no WCAG) | cap at `moderate` |
| **Siteimprove Alfa** | failed WCAG A / AA | `serious` |
| | failed WCAG AAA | `moderate` |
| | `cantTell` / review needed | `minor` + tag `needs-review` |
| **pa11y** | error / warning / notice | `serious` / `moderate` / `minor`+`notice` |
| **Reflow** (SC 1.4.10) | content lost / h-scroll @320px | `serious` |
| **Meta-viewport** (SC 1.4.4) | `user-scalable=no` / `maximum-scale<2` | `serious` |

### Conformance-level floor

When a WCAG success-criterion level is known, apply this floor regardless of the engine's adjective; the adapter takes `max(engine_severity, floor)`:

- Level **A** / **AA** failure → at least `serious`
- Level **AAA** failure → at least `moderate`
- Best-practice / no SC → at most `moderate`

## Tags carry the detail

Severity is coarse on purpose. Preserve precise classification in `tags` so triage and humans can filter:

- WCAG: `wcag2a`, `wcag2aa`, `wcag21aa`, `wcag22aa`, `best-practice`
- Linting category: `coding-standard`, `type-safety`, `security`, `formatting`, `spelling`, `workflow`, `deprecation`
- Review state: `needs-review`, `inconclusive`

Gating reads `severity`; reporting and triage read `tags` and `impact_category`.

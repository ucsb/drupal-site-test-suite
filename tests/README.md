# Drupal 10/11 Test Suite

Accessibility, code-quality, and functional/regression testing for Drupal 10/11 sites and upstream projects. Drush is the primary entry point; every check is exposed as a `drush utest:*` command.

The suite targets WCAG 2.1 Level AA conformance, the technical baseline recognized under Section 504 of the Rehabilitation Act and a common compliance standard for higher-education and public-sector sites.

The CI run uses the broader `comprehensive` profile (WCAG 2.0 + 2.1 Levels A / AA / AAA + WCAG 2.2 Levels A / AA + best-practice rules) so the report exposes everything; merge gating uses `critical` + `serious` severity only.

- **Quick reference**: [Cheat Sheet](README_cheatsheet.md)
- **CI / GitHub Actions setup**: [Workflows guide](../.github/README.md)
- **Accessibility config (profiles, rule mappings)**: [accessibility/config/README.md](accessibility/config/README.md)

---

## Tests in this suite

The suite groups its checks into four areas:

- **Accessibility**: *is the rendered site accessible?*
- **Code Quality**: *is the code valid, consistent, and compatible?*
- **Security**: *are dependencies vulnerability-free and secrets kept out of the repo?*
- **Functional / Regression**: *does the custom Drupal functionality still work?*

Each row below is a separate engine the orchestrator (or CI) invokes; the unified report aggregates findings from all of them into one filterable view.

### Accessibility: *is the rendered site accessible?*

| Engine | Drush command | Scope | Default cap | Notes |
| --- | --- | --- | --- | --- |
| Siteimprove Alfa (key pages) | `utest:a11y:alfa` | 5 representative pages | — | No API key. Fastest deep check. |
| Siteimprove Alfa (full site) | `utest:a11y:alfa-full` | Sitemap walk | 50 pages (`--max-pages=all` for full) | No API key. The headline a11y lane in CI. |
| axe-core (local, key pages) | `utest:a11y:axe` | 5 representative pages | — | No API key. Fast dev-feedback overlay. |
| axe-core (local, full site) | `utest:a11y:axe-full` | Sitemap walk | 50 pages (`--max-pages=all` for full) | No API key. Provides the `best-practice` rule tag. The headline axe lane in CI. |
| axe Developer Hub *(optional)* | `utest:a11y:axe-watcher`, `utest:a11y:axe-watcher-full` | Key pages or sitemap | 50 pages | Requires `AXE_API_KEY` (paid Deque product). Adds an analytics dashboard. Skips silently when no key. |
| pa11y | `utest:a11y:pa11y` | Full sitemap | — | No API key. WCAG 2.0/2.1 A/AA via HTML_CodeSniffer. |
| Reflow (WCAG 2.1 SC 1.4.10) | `utest:a11y:reflow` | Full sitemap | 50 pages | Playwright at 320 px viewport. Catches horizontal-overflow that static a11y tools can't see. |
| Meta-viewport (WCAG 2.0 SC 1.4.4) | `utest:a11y:meta-viewport` | Full sitemap | 50 pages | Static DOM check for zoom-blocking `user-scalable=no` / `maximum-scale<2`. |

Alfa rule metadata comes from the official `@siteimprove/alfa-rules` SDK, resolved in-process, with no network calls, no scraping, and no API key.

### Code quality: *is the code valid, consistent, and compatible?* (one orchestrator pass: `drush utest:lint`)

| Tool | What it checks |
| --- | --- |
| PHPCS | Drupal + DrupalPractice coding standards |
| PHPStan (level 2, baseline-managed) | Static type / dead-code / undefined-behavior analysis |
| ESLint (extends Drupal Core config) | JS coding standards + Drupal globals |
| cspell | Spell-check across PHP / Twig / SCSS / Markdown |
| TwigCS + HTMLHint | Twig style + static a11y patterns (alt, label, aria) |
| Stylelint (+ `stylelint-plugin-a11y`) | CSS / SCSS style + static a11y patterns (focus, motion, contrast hints) |
| yaml-lint | YAML structure |
| editorconfig-checker | Whitespace / line-ending consistency |
| markdownlint-cli2 | Markdown style |
| actionlint (+ shellcheck) | GitHub Actions workflow YAML + inline shell |
| Deprecation / next-major readiness | `@deprecated` API usage + `core_version_requirement` / removed-subsystem incompatibility |
| Reference resolution | Library assets, `dependencies` / `attach_library()` refs, and Twig asset paths resolve on disk |
| Config hygiene | Shipped `config/install` carries no site-specific metadata (`uuid` / `_core`) that would drift across the fleet |
| Permission baseline | Security-sensitive custom permissions (`administer` / `bypass`) declare `restrict access: true` |

Scope follows the [What gets scanned](#what-gets-scanned) surface below: custom modules / themes / profiles by default, plus custom PHP outside `web/` declared in `custom-paths.json` (PHPCS). A few lanes are broader: cspell and editorconfig-checker run project-wide (excluding core / contrib / `vendor` / `node_modules`), markdownlint covers upstream-authored Markdown, and actionlint covers `.github/workflows/**`.

Configs live under `tests/code-quality/`, grouped by concern: `linting/` (ESLint, Stylelint, TwigCS, HTMLHint, yaml-lint, markdownlint), `static-analysis/` (PHPCS, PHPStan + baseline), `spelling/` (cspell), `security/` (gitleaks), and `config/` (custom-paths). The orchestrator itself (`lint-orchestrator.js`) sits at the `code-quality/` root.

### Security: *are dependencies vulnerability-free and secrets kept out of the repo?*

| Tool | What it checks | Where it runs |
| --- | --- | --- |
| `composer validate --strict` | composer.json schema + `composer.lock` drift | Lint orchestrator (`utest:lint`) |
| `composer audit` | Known CVEs in PHP dependencies (Drupal core + contrib + libs) | Lint orchestrator (`utest:lint`); findings route to the **Security** impact category |
| Dependabot | Weekly grouped PRs for outdated composer + npm + GitHub Actions deps. Hybrid cadence: security-updates weekly, version-updates monthly. | GitHub-native; configured in `.github/dependabot.yml` |
| gitleaks | Committed-secret scanning (AWS keys, GitHub tokens, SSH private keys, etc.) | Separate per-PR CI job (`pr-tests.yml`); local: `gitleaks detect --config=tests/code-quality/security/.gitleaks.toml --redact` |

**Not included** (deliberately): `npm audit` (test toolchain only, not shipped to production), PHPCS Security Audit ruleset (D7-only Drupal coverage), `drush pm:security` (composer audit covers the same threats), CodeQL (paid for private repos).

### Functional / Regression: *does the custom Drupal functionality still work?* (`drush utest:phpunit`)

| Tool | What it checks | Scope |
| --- | --- | --- |
| PHPUnit (Unit + Kernel) | Custom-module behavior: services, plugins, controllers, access logic | `web/modules/custom` + `web/profiles/custom` test classes under `tests/src/{Unit,Kernel}`; core and contrib are never run |

**Report-only**: failing tests are flagged in the unified report but never fail the build. The lane is **fail-soft**: it skips with a message when dev dependencies (PHPUnit) aren't installed, and runs Unit tests only when `pdo_sqlite` is unavailable (Kernel tests need a database, and the lane uses SQLite, so no MySQL or site install is required). Functional / FunctionalJavascript tests are excluded here; they need a deployed site. The same logic runs locally via `drush utest:phpunit` and in CI via the standalone `node tests/phpunit/run.js` (no Drupal bootstrap needed).

---

## Quick start

```bash
# 1. Install test-suite tooling (once per repo / after upstream pulls)
drush utest:js-install
drush utest:browsers
drush utest:check-config           # pre-flight verification

# 2. Set your local site URL (skip --base-url= on every command)
export BASE_URL=https://yoursite.test

# 3. Run everything: lint + PHPUnit + full a11y suite + render unified report
drush utest:all

# 4. Open the report
open "$BASE_URL/sites/default/files/test-reports/index.html"
```

Full command set lives in the [Cheat Sheet](README_cheatsheet.md).

---

## Setup

### Local environment

| Requirement | Minimum | Verify |
| --- | --- | --- |
| Node.js | 20.x | `node --version` |
| npm | 10.x | `npm --version` |
| Drush | 12.x | `drush --version` |
| PHP | 8.1 | `php --version` |
| Reachable site URL | Local dev URL serving your site | `curl -I https://yoursite.test` |

If you don't have Node 20+: macOS, `brew install node@20`; Linux, use [nvm](https://github.com/nvm-sh/nvm). DDEV / Lando users typically run drush from the host while the container serves the site.

### First-run commands

```bash
drush utest:js-install     # Install Node deps for the test suite
drush utest:browsers       # Install Playwright browsers (Chromium, ~150 MB)
drush utest:check-config   # Pre-flight: Node, browsers, BASE_URL, sitemap, custom paths
```

`drush utest:check-config` reports PASS / WARN / FAIL per item with a one-line remediation hint. Fix anything red before running the suite.

### Environment variables

The drush commands read from your process environment (no `.env` autoload). `export` values in your shell, your shell profile, or use direnv / DDEV / Lando which auto-load `.env` files. See [`tests/.env.example`](.env.example) for the full list.

The most useful ones:

```bash
export BASE_URL=https://yoursite.test          # all tests; default site URL
export A11Y_PROFILE=comprehensive              # strict | standard | comprehensive | custom
export A11Y_SEVERITY_LEVELS=critical,serious   # severity filtering
export AXE_API_KEY=…                           # only if you use axe Developer Hub
```

### Accessibility profiles

| Profile | Coverage |
| --- | --- |
| `strict` | WCAG 2.0 + 2.1 Level A only |
| `standard` | WCAG 2.0 + 2.1 Levels A + AA |
| `comprehensive` *(CI default)* | WCAG 2.0 + 2.1 Levels A / AA / AAA + WCAG 2.2 Levels A / AA + best-practice rules |
| `custom` | User-defined via `A11Y_CUSTOM_TAGS` |

The CI pipeline always uses `comprehensive` so the unified report exposes the broadest possible signal. Merge gating still only blocks on `critical` + `serious`; AAA + best-practice findings surface in the report but don't fail the build.

---

## Configuring custom-code paths

**Most sites can skip this.** The suite uses a three-layer discovery model:

1. **`composer.json`'s `extra.installer-paths`** is the canonical source. Custom modules / themes / profiles bound to `type:drupal-custom-module`, `type:drupal-custom-theme`, `type:drupal-custom-profile` are auto-discovered.
2. **`tests/code-quality/config/custom-paths.json`** declares extras (drush commands, repo docs, workflow YAML) and overrides for non-standard layouts. The shipped defaults are usually correct.
3. **`*.info.yml` autodiscovery** is the safety net for any custom code outside the above.

Edit `tests/code-quality/config/custom-paths.json` only if your site puts custom code in unusual places. The schema at `tests/code-quality/config/custom-paths.schema.json` documents every field with examples.

---

## Running tests

The [Cheat Sheet](README_cheatsheet.md) has the full copy-paste command set: single lanes, narrowing to one module or theme (`--module=` / `--theme=`), uncapped sitemap scans (`--max-pages=all`), background runs, and local secret scanning. While tests run, drush prints live progress per page.

The most common entry point is **`drush utest:all`**, which runs the following in order, then renders the unified report:

| # | Step | Command | Scope |
| --- | --- | --- | --- |
| 1 | Lint | `utest:lint` | All custom PHP/JS/CSS/Twig/YAML/MD + project-wide tools (PHPCS, PHPStan, ESLint, cspell, composer audit/validate, markdownlint, actionlint, etc.) |
| 2 | PHPUnit | `utest:phpunit` | Custom-module Unit + Kernel tests (Functional / Regression), report-only |
| 3 | Alfa full-site | `utest:a11y:alfa-full` | Every sitemap page, Siteimprove Alfa rule engine |
| 4 | pa11y | `utest:a11y:pa11y` | Every sitemap page, HTML_CodeSniffer rule engine |
| 5 | axe full-site | `utest:a11y:axe-full` | Every sitemap page, axe-core rule engine (free) |
| 6 | Reflow | `utest:a11y:reflow` | Every sitemap page, Playwright at 320 px viewport (WCAG 2.1 SC 1.4.10) |
| 7 | Meta-viewport | `utest:a11y:meta-viewport` | Every sitemap page, static `<meta name="viewport">` inspection (WCAG 2.0 SC 1.4.4) |
| 8 | Render report | `utest:report-render` | Builds the unified `public://test-reports/index.html` |

The key-pages variants (`utest:a11y:alfa`, `utest:a11y:axe`) remain available as standalone commands for fast dev feedback. The paid Deque lanes (`utest:a11y:axe-watcher`, `utest:a11y:axe-watcher-full`) skip silently without `AXE_API_KEY`. **Single-test runs** (e.g. just `utest:a11y:alfa-full`) need an explicit `drush utest:report-render` afterward to refresh `index.html`.

### Tuning the gitleaks allowlist

CI scans for committed secrets on every PR; you can also run it locally (see the [Cheat Sheet](README_cheatsheet.md#check-for-committed-secrets-locally)). False positives in instructional documentation (e.g. a sample `-----BEGIN RSA PRIVATE KEY-----`) can be added to `tests/code-quality/security/.gitleaks.toml`; CI and the local scan use the same allowlist file.

### Per-site cspell additions

Downstream sites can add their own brand / department / contributor terms without touching upstream-owned files:

1. Add words to `tests/code-quality/spelling/.cspell/site-words.txt` (sorted, lowercase, one per line). Upstream ships this file empty.
2. Mark it skip-worktree once per checkout so upstream pulls don't clobber local entries:

   ```bash
   git update-index --skip-worktree tests/code-quality/spelling/.cspell/site-words.txt
   ```

3. Reverse with `git update-index --no-skip-worktree …` to push terms back upstream.

cspell unions both dictionaries automatically; words in either count as known.

---

## What gets scanned

| Status | Surface |
| --- | --- |
| **Always** | Custom modules (`web/modules/custom/**`), custom themes (`web/themes/custom/**`), custom profiles (`web/profiles/custom/**`), custom drush commands (`drush/Commands/**`), other custom PHP declared in `custom-paths.json` `extras` (e.g. `scripts/**`), repo Markdown, `.github/workflows/**` |
| **Conditional** | Non-standard custom paths, scanned only if declared in `custom-paths.json` (`extras.custom_php_other` / `custom_js_other` / `custom_css_other` for extra code folders, `installer_paths_overrides` for relocated modules/themes) or covered by `*.info.yml` autodiscovery |
| **Never** | Drupal core (`web/core/**`), contrib (`web/{modules,themes,profiles}/contrib/**`), `vendor/**`, `node_modules/**` |

**Why contrib is excluded:** contrib code ships its own CI on drupal.org. Re-running those tests on top of your site's CI duplicates work and surfaces findings you can't fix in your repo. A11y crawls *do* exercise rendered output of contrib code (because the demo site renders it), but findings are attributed to the shipping surface so you can triage contrib-origin issues separately.

---

## Reports

After any run, the unified Test Report lives at:

```text
$BASE_URL/sites/default/files/test-reports/index.html
```

The unified report aggregates findings from every test that ran, with filterable chips for Rules / Impact / Severity, collapsible impact groups, and per-finding accordions. It also links to per-tool detail reports in the "Detailed reports per tool" subsection of its "About this report" panel:

| Report | Local path |
| --- | --- |
| **Unified Test Report** *(start here)* | `web/sites/default/files/test-reports/index.html` |
| Code-quality (lint) detail | `web/sites/default/files/test-reports/lint/lint-report.html` |
| Alfa full-site detail | `web/sites/default/files/test-reports/alfa-full/alfa-full-site-report.html` |
| Alfa key-pages detail | `web/sites/default/files/test-reports/alfa/alfa-report.html` |
| axe (local) detail | `web/sites/default/files/test-reports/axe/axe-report.html` |
| pa11y detail | `web/sites/default/files/test-reports/pa11y/pa11y-report.html` |

Reports overwrite on each test run. The lint report has a per-module / per-theme / per-profile breakdown that's especially useful when you want to triage by code surface.

---

## CI

CI is **optional** and host-agnostic. **Locally** the suite is driven with
`drush utest:*`. **In CI** the runner tests a remote/deployed site and has no
local Drupal to bootstrap, so it calls the packages directly — the lint
orchestrator (`node code-quality/lint-orchestrator.js`), the Playwright a11y
specs (`npx playwright test accessibility/...`), and the PHPUnit runner
(`node tests/phpunit/run.js`) — pointed at a reachable site URL via `BASE_URL`.
Nothing here requires a particular CI provider; see [`examples/ci/`](../examples/ci/)
for GitHub Actions, GitLab CI, and CircleCI starting points.

---

## Troubleshooting

**`Playwright cannot reach the URL`**: verify your local site is running and `BASE_URL` is set correctly. `curl -I $BASE_URL` from the same shell. DDEV / Lando: use the external URL (`*.ddev.site`, `*.lndo.site`). Self-signed certs: `export NODE_TLS_REJECT_UNAUTHORIZED=0` (local only, never CI).

**`Sitemap.xml not found`**: install [Simple XML Sitemap](https://www.drupal.org/project/simple_sitemap) or pass `--paths=/,/about,/contact` explicitly to skip sitemap-driven lanes. The pre-flight reports sitemap availability as WARN, not FAIL.

**Lint report shows findings I don't recognize**: likely autodiscovered code in unexpected paths. Run `drush utest:check-config`; the "globs validated" section lists every path the suite scans. Edit `tests/code-quality/config/custom-paths.json` to fix.

**Modules outside `web/modules/` aren't picked up**: declare the path in `tests/code-quality/config/custom-paths.json` per the [custom-paths config](#configuring-custom-code-paths) section above.

**A lint tool isn't installed**: the suite degrades gracefully. A missing subprocess tool (PHPCS, PHPStan, TwigCS, etc.) or lane-specific in-process tool (stylelint, htmlhint) **skips only its lane with a warning**, and the rest of the pass still runs. Run `composer install` and `npm ci` (in `tests/`) to enable everything.

**Where to file issues:**

- Test-suite bug (drush, reports, orchestrator): this repo
- Custom-code bug: your site's repo
- Contrib-code bug: drupal.org project page

---

## Command reference

The full command list (setup, every accessibility/code-quality/functional lane, scopes, and flags) lives in the [Cheat Sheet](README_cheatsheet.md).

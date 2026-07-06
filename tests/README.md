# Drupal 10/11 Test Suite

A test suite for Drupal 10/11 sites. It checks accessibility, code quality,
security, and functional/regression behavior. Every check is a
`drush utest:*` command, and results land in one unified report.

Accessibility checks target WCAG 2.1 Level AA. That is the standard Section
504 requires of higher-education and public-sector sites, and a solid
baseline for any site that wants to be usable by everyone.

CI scans with the `comprehensive` profile, which covers all WCAG levels plus
best-practice rules, so the report shows everything. Only critical and
serious findings fail a check. Everything else is advisory.

- **Quick reference**: [Cheat Sheet](README_cheatsheet.md)
- **CI setup (GitHub Actions, GitLab CI, CircleCI examples)**: [examples/ci/](../examples/ci/README.md)
- **Accessibility config (profiles, rule mappings)**: [accessibility/config/README.md](accessibility/config/README.md)

## Tests in this suite

The suite is organized into four test areas. You can run everything with
`drush utest:all`, or run one lane at a time while developing.

| Area | Main command(s) | What it answers | Scope model |
| --- | --- | --- | --- |
| Accessibility | `utest:alfa`, `utest:axe`, `utest:axe-watcher`, `utest:pa11y`, `utest:reflow`, `utest:meta-viewport` | Is the rendered site accessible? | URL based (`BASE_URL`, sitemap) |
| Code quality | `utest:lint` | Is custom code valid, consistent, secure, and compatible? | Custom code based (modules/themes/profiles) |
| Security | `utest:lint`, CI jobs | Are dependencies vulnerability-free and secrets out of the repo? | Project/dependency based |
| Functional / Regression | `utest:phpunit` | Does custom Drupal behavior still work? | Custom code based (modules/themes/profiles with tests) |

The unified report aggregates every lane that ran into one filterable HTML
view. The detailed per-lane reports remain available beside it.

### Running all vs. a scoped local run

By default, custom-code lanes run all custom modules, themes, and profiles.
For repeated local work, you can scope custom-code lanes with CLI flags or
`UTEST_CUSTOM_*` environment variables.

| Situation | What runs |
| --- | --- |
| No scope flags and no `UTEST_CUSTOM_*` variables | All custom code |
| `UTEST_CUSTOM_MODULES`, `UTEST_CUSTOM_THEMES`, or `UTEST_CUSTOM_PROFILES` set | The env-scoped custom code |
| Any CLI scope flag is passed | Only the CLI-scoped custom code; env scope is ignored |
| `--ignore-scope` is passed | All custom code; env scope is ignored |

Examples:

```bash
# One-off scoped lint run.
drush utest:lint --modules=my_custom_module,another_module --themes=my_theme

# One-off scoped PHPUnit run.
drush utest:phpunit --modules=my_custom_module --profiles=my_profile

# Repeated local scoped runs.
export UTEST_CUSTOM_MODULES=my_custom_module,another_module
export UTEST_CUSTOM_THEMES=my_theme
export UTEST_CUSTOM_PROFILES=my_profile
drush utest:lint
drush utest:phpunit

# Temporarily bypass local env scope.
drush utest:lint --ignore-scope
drush utest:phpunit --ignore-scope
```

Scoped reports are local-only and do not overwrite the canonical all-custom
reports:

- `web/sites/default/files/test-reports/scoped/lint/custom/index.html`
- `web/sites/default/files/test-reports/scoped/phpunit/custom/index.html`

### Accessibility: rendered-page checks

Accessibility lanes test pages, not source directories. Scope them with URLs,
paths, sitemap settings, and page caps rather than module/theme/profile names.

| Engine | Drush command | Scope | Default cap | Notes |
| --- | --- | --- | --- | --- |
| Siteimprove Alfa | `utest:alfa` | Sitemap walk | 50 pages (`--max-pages=all` for full) | No API key. Headline a11y lane in CI. |
| axe-core | `utest:axe` | Sitemap walk | 50 pages (`--max-pages=all` for full) | No API key. Includes best-practice rules. |
| axe Developer Hub *(optional)* | `utest:axe-watcher` | Sitemap walk | 50 pages | Requires `AXE_API_KEY`. Skips silently without a key. |
| pa11y | `utest:pa11y` | Full sitemap | n/a | No API key. WCAG 2.0/2.1 A/AA via HTML_CodeSniffer. |
| Reflow | `utest:reflow` | Full sitemap | 50 pages | 320 px viewport check for horizontal overflow. |
| Meta-viewport | `utest:meta-viewport` | Full sitemap | 50 pages | Detects zoom-blocking viewport settings. |

Which a11y command should you run?

| Goal | Command |
| --- | --- |
| CI-style full accessibility signal | `drush utest:all` |
| Full-site Alfa only | `drush utest:alfa` |
| Full-site axe only | `drush utest:axe` |
| HTML_CodeSniffer/pa11y comparison | `drush utest:pa11y` |
| Check mobile reflow/horizontal scrolling | `drush utest:reflow` |
| Check zoom-blocking viewport settings | `drush utest:meta-viewport` |
| Use paid axe Developer Hub dashboards | `drush utest:axe-watcher` with `AXE_API_KEY` |

Sitemap notes:

- All a11y lanes (`alfa`, `axe`, `axe-watcher`, `reflow`, `meta-viewport`,
  `pa11y`) crawl the sitemap and need a reachable one. Use
  `--sitemap-url=https://site.test/sitemap.xml` when it is not available at
  `${BASE_URL}/sitemap.xml`.
- Full-site lanes scan pages sequentially and print progress such as
  `[17/230] Testing: /example`. The default cap is 50 pages. For large
  sitemaps, use `--max-pages=100` for a larger sample or `--max-pages=all`
  for a full sweep. Full sweeps on 200+ pages can take a while; consider
  running them in the background with `nohup` as shown in the cheat sheet.

Useful scope controls:

```bash
export BASE_URL=https://yoursite.test
export ALFA_MAX_PAGES=100
export AXE_MAX_PAGES=100
```

Alfa rule metadata comes from the official `@siteimprove/alfa-rules` SDK,
resolved in-process with no network calls, scraping, or API key.

### Code quality: custom-code static checks (`drush utest:lint`)

The lint orchestrator checks custom modules, themes, and profiles. Full runs
also include project-level checks such as dependency audit, spelling, Markdown,
and GitHub Actions linting.

| Tool | What it checks |
| --- | --- |
| PHPCS | Drupal + DrupalPractice coding standards (incl. PHPUnit test classes under `tests/src/`) |
| PHPStan (level 2, baseline-managed) | Static type / dead-code / undefined-behavior analysis |
| ESLint (extends Drupal Core config) | JS coding standards + Drupal globals |
| cspell | Spell-check across PHP / Twig / SCSS / Markdown |
| TwigCS + HTMLHint | Twig style + static a11y patterns (alt, label, aria) |
| Stylelint (+ `stylelint-plugin-a11y`) | CSS / SCSS style + static a11y patterns |
| yaml-lint | YAML structure |
| editorconfig-checker | Whitespace / line-ending consistency |
| markdownlint-cli2 | Markdown style |
| actionlint (+ shellcheck) | GitHub Actions workflow YAML + inline shell |
| Deprecation / next-major readiness | Deprecated APIs and incompatible `core_version_requirement` values |
| Reference resolution | Library assets, dependencies, `attach_library()`, and Twig asset paths |
| Config hygiene | Shipped config has no site-specific `uuid` / `_core` metadata |
| Permission baseline | Sensitive permissions declare `restrict access: true` |

Scope notes:

- cspell, composer checks, markdownlint, and actionlint always scan the whole
  project, even on a scoped run.
- PHPCS also checks PHPUnit test classes under each component's `tests/src/`.
  Test fixtures are excluded, and the other lanes skip `tests/` entirely.

**Reading the result:** lint "PASSED" means the tools ran, not that nothing
was found. Findings are report-only, so read the lint report, not the badge.

### Security: dependency and secret checks

Security findings appear in the unified report under the **Security** impact
category.

| Tool | What it checks | Where it runs |
| --- | --- | --- |
| `composer validate --strict` | composer.json schema + `composer.lock` drift | Lint orchestrator (`utest:lint`) |
| `composer audit` | Known CVEs in PHP dependencies | Lint orchestrator (`utest:lint`) |
| Dependabot | Scheduled dependency update PRs | GitHub-native; configured in `.github/dependabot.yml` |
| gitleaks | Committed-secret scanning | Separate per-PR CI job; local: `gitleaks detect --config=tests/code-quality/security/.gitleaks.toml --redact` |

Some tools were evaluated and left out on purpose: `npm audit` (the npm
packages are test tooling only, not shipped code), `drush pm:security`
(`composer audit` already reports the same advisories), the PHPCS Security
Audit ruleset (Drupal 7 only), and CodeQL (not free for private
repositories).

### Functional / Regression: custom-code PHPUnit (`drush utest:phpunit`)

| Tool | What it checks | Scope |
| --- | --- | --- |
| PHPUnit (Unit + Kernel) | Custom behavior: services, plugins, controllers, access logic, entity/config integration | `tests/src/{Unit,Kernel}` under selected custom modules, themes, and profiles; core and contrib are never run |

This lane is **report-only** and **fail-soft**:

- Failing tests are shown in the standalone `phpunit-report.html` and in the
  unified report, but do not fail the build.
- If PHPUnit dev dependencies are missing, the lane skips with a message.
- If `pdo_sqlite` is missing, only Unit tests run; Kernel tests need SQLite.
- Functional / FunctionalJavascript tests are excluded because they require a
  deployed browser-test site.

The same runner is used by Drush and CI:

```bash
drush utest:phpunit
node tests/phpunit/run.js
```

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

## Setup

### Local environment

| Requirement | Minimum | Verify |
| --- | --- | --- |
| Drupal | 10.3+ (any later 10.x, such as 10.6) or 11.x | `drush status --field=drupal-version` |
| Node.js | 20.x | `node --version` |
| npm | 10.x | `npm --version` |
| Drush | 12.x or 13.x | `drush --version` |
| PHP | 8.1 | `php --version` |
| Reachable site URL | Local dev URL serving your site | `curl -I https://yoursite.test` |

If you don't have Node 20+: macOS, `brew install node@20`; Linux, use [nvm](https://github.com/nvm-sh/nvm). DDEV / Lando users typically run drush from the host while the container serves the site.

**Docroot layout**

The suite expects the docroot at `web/`: custom code under
`web/{modules,themes,profiles}`, reports under `web/sites/default/files/`.
A configurable docroot is not yet supported. If your project uses a different
docroot (for example `docroot/`), create a symlink at the project root and
commit it so local runs and CI checkouts both see it:

```bash
ln -s docroot web
git add web
```

**Shell requirement**

The lanes shell out via `bash -lc`, so a Unix shell is required.

- **Windows:** use WSL, Git-Bash, DDEV, or Lando. Running `drush` inside the DDEV/Lando container works on any host.
- **macOS:** the suite calls `bash` directly even if your default shell is zsh. Homebrew-installed Node is found automatically. If you use nvm configured only in `~/.zshrc`, also add it to `~/.bash_profile` (or use Homebrew Node) so the `bash` login shell resolves the same Node.
- **Node mismatch:** `drush utest:check-config` reports the Node path it resolves, so you can spot one.

### First-run commands

```bash
drush utest:js-install     # Install Node deps for the test suite
drush utest:browsers       # Install Playwright browsers (Chromium, ~150 MB)
drush utest:check-config   # Pre-flight: PHP/Composer, Node, browsers, reports, BASE_URL, sitemap, scope
```

`drush utest:check-config` reports PASS / WARN / FAIL per item with a one-line remediation hint. It checks Composer/vendor dependencies, PHPUnit availability, `pdo_sqlite` for Kernel tests, PHP lint tools, Node/npm/browser setup, report-directory permissions, `BASE_URL`, sitemap availability, sitemap URL host sanity (flags installer-prefixed or wrong-host `<loc>` entries), custom paths, and any `UTEST_CUSTOM_*` scope variables. Fix anything red before running the suite.

### Environment variables

The drush commands read from your process environment (no `.env` autoload). `export` values in your shell, your shell profile, or use direnv / DDEV / Lando which auto-load `.env` files. See [`tests/.env.example`](.env.example) for the full list.

The most useful ones:

```bash
export BASE_URL=https://yoursite.test                         # all tests; default site URL
export UTEST_CUSTOM_MODULES=my_custom_module,another_module    # local custom-code scope
export UTEST_CUSTOM_THEMES=my_theme,another_theme              # local custom-code scope
export UTEST_CUSTOM_PROFILES=my_profile,another_profile        # local custom-code scope
export A11Y_PROFILE=comprehensive                             # strict | standard | comprehensive | custom
export A11Y_SEVERITY_LEVELS=critical,serious                  # severity filtering
export AXE_API_KEY=…                                          # only if you use axe Developer Hub
```

### Accessibility profiles

| Profile | Coverage |
| --- | --- |
| `strict` | WCAG 2.0 + 2.1 Level A only |
| `standard` | WCAG 2.0 + 2.1 Levels A + AA |
| `comprehensive` *(CI default)* | WCAG 2.0 + 2.1 Levels A / AA / AAA + WCAG 2.2 Levels A / AA + best-practice rules |
| `custom` | User-defined via `A11Y_CUSTOM_TAGS` |

Pick one per run with `--a11y-profile=standard`, or for the whole session
with `export A11Y_PROFILE=standard`. Details and custom tags:
[accessibility config README](accessibility/config/README.md).

The CI pipeline always uses `comprehensive` so the unified report exposes the broadest possible signal. Merge gating still only blocks on `critical` + `serious`; AAA + best-practice findings surface in the report but don't fail the build.

## Configuring custom-code paths

**Most sites can skip this.** The suite uses a three-layer discovery model:

1. **`composer.json`'s `extra.installer-paths`** is the canonical source. Custom modules / themes / profiles bound to `type:drupal-custom-module`, `type:drupal-custom-theme`, `type:drupal-custom-profile` are auto-discovered.
2. **`tests/code-quality/config/custom-paths.json`** declares extras (drush commands, repo docs, workflow YAML) and overrides for non-standard layouts. The shipped defaults are usually correct.
3. **`*.info.yml` autodiscovery** is the safety net for any custom code outside the above.

Edit `tests/code-quality/config/custom-paths.json` only if your site puts custom code in unusual places. The schema at `tests/code-quality/config/custom-paths.schema.json` documents every field with examples.

## Running tests

The [Cheat Sheet](README_cheatsheet.md) has the full copy-paste command set: single lanes, narrowing to one module or theme (`--module=` / `--theme=`), uncapped sitemap scans (`--max-pages=all`), background runs, and local secret scanning. While tests run, drush prints live progress per page.

The most common entry point is **`drush utest:all`**. This is the same
high-level flow used by CI/CD: run the custom-code checks, run the full-site
a11y checks, then render the unified report.

### What `drush utest:all` includes

| # | Step | Underlying command | What runs |
| --- | --- | --- | --- |
| 1 | Lint | `utest:lint --ignore-scope` | Full custom-code lint plus project-level checks: PHPCS, PHPStan, ESLint, cspell, composer audit/validate, markdownlint, actionlint, and related checks. |
| 2 | PHPUnit | `utest:phpunit --ignore-scope` | Full custom-code Unit + Kernel tests. Report-only Functional / Regression lane. |
| 3 | Alfa | `utest:alfa` | Sitemap-based Siteimprove Alfa crawl. |
| 4 | pa11y | `utest:pa11y` | Sitemap-based HTML_CodeSniffer crawl. |
| 5 | axe | `utest:axe` | Sitemap-based axe-core crawl. |
| 6 | Reflow | `utest:reflow` | Sitemap-based 320 px viewport check for horizontal overflow. |
| 7 | Meta-viewport | `utest:meta-viewport` | Sitemap-based static viewport inspection. |
| 8 | Render report | `utest:report-render` | Builds `public://test-reports/index.html`. |

Important behavior:

- The run ends with a per-lane summary table. Statuses: PASSED, PASSED
  (advisory), FAILED (critical or serious findings), INCOMPLETE (the lane did
  not verify everything). The command exits non-zero when any accessibility
  lane is FAILED or INCOMPLETE; lint and PHPUnit never block.
- `utest:all` intentionally ignores `UTEST_CUSTOM_MODULES`,
  `UTEST_CUSTOM_THEMES`, and `UTEST_CUSTOM_PROFILES` for lint/PHPUnit. This
  prevents a developer's local scoped environment from accidentally producing
  an incomplete CI-style report.
- Use the single-lane commands (`utest:lint`, `utest:phpunit`) for scoped local
  work against selected modules/themes/profiles.
- Accessibility lanes are page-based, not module/theme/profile-based. Scope
  them with URLs, paths, sitemap settings, and page caps.

### Inputs `utest:all` honors

| Input | Applies to | Notes |
| --- | --- | --- |
| `BASE_URL` or `--base-url=...` | All rendered-site checks and report URLs | Defaults to `http://127.0.0.1:8888` when unset. |
| `--sitemap-url=...` | Full-site a11y lanes | Defaults to `${BASE_URL}/sitemap.xml`. |
| `--a11y-profile=...` | Alfa/axe lanes | CI/default is `comprehensive`. |
| `--a11y-custom-tags=...` | Alfa/axe lanes | Used when the a11y profile is `custom`. |
| `--a11y-severity-levels=...` | Selected a11y lanes | Full-site report output still surfaces the configured findings. |
| `AXE_API_KEY` | Optional axe Developer Hub lane only | `utest:all` uses the free axe lane, not the paid watcher lane. |

`UTEST_CUSTOM_*` variables are intentionally **not** inputs to `utest:all`.
They are for repeated local scoped runs of `utest:lint` and `utest:phpunit`.

Notes:

- `utest:axe-watcher` (the paid Deque lane) requires `AXE_API_KEY`. Without
  it, the command explains what is missing and skips.
- A single a11y lane (for example `utest:alfa`) writes its own report right
  away. Run `drush utest:report-render` afterward if you also want the
  unified `index.html` refreshed. `utest:all` does this automatically.
- Scoped lint and PHPUnit runs render their scoped reports automatically.

### Tuning the gitleaks allowlist

CI scans every PR for committed secrets with gitleaks. You can also run the
scan locally; see the [Cheat Sheet](README_cheatsheet.md#check-for-committed-secrets-locally).

Sometimes the scan flags text that is not a real secret, such as a sample
key inside documentation. Add those false positives to the allowlist in
`tests/code-quality/security/.gitleaks.toml`. CI and the local scan share
that file.

### Per-site cspell additions

Add your site's own terms (brand names, departments, people) to
`tests/code-quality/spelling/.cspell/site-words.txt`, one per line, sorted.
Words in that file count as known, the same as the upstream dictionary.

- The lint lane creates the file empty if it does not exist.
- Upstream does not track the file, so upstream merges never touch your words.
- Commit it to your site's repo once:

  ```bash
  git add -f tests/code-quality/spelling/.cspell/site-words.txt
  ```

  The `-f` bypasses the inherited gitignore.

Migrating from the old skip-worktree setup? Unmark the file, then commit it
as above:

```bash
git update-index --no-skip-worktree tests/code-quality/spelling/.cspell/site-words.txt
```

## What gets scanned

Always scanned:

- Custom modules: `web/modules/custom/**`
- Custom themes: `web/themes/custom/**`
- Custom profiles: `web/profiles/custom/**`
- Custom drush commands: `drush/Commands/**`
- Other custom PHP declared in `custom-paths.json` `extras` (e.g. `scripts/**`)
- Repo Markdown and `.github/workflows/**`

Scanned only when declared in `custom-paths.json` (or found by `*.info.yml`
autodiscovery):

- Extra code folders via `extras.custom_php_other`, `custom_js_other`, and
  `custom_css_other`
- Relocated modules and themes via `installer_paths_overrides`

Never scanned:

- Drupal core (`web/core/**`)
- Contrib modules, themes, and profiles (`web/{modules,themes,profiles}/contrib/**`)
- `vendor/**` and `node_modules/**`

**Why contrib is excluded:** contrib projects run their own tests on
drupal.org, and their findings cannot be fixed in your repo anyway. Pages
rendered by contrib code still get checked by the a11y crawls, since those
test the rendered site.

## Reports

After any run, the unified Test Report lives at:

```text
$BASE_URL/sites/default/files/test-reports/index.html
```

The unified report aggregates findings from every test that ran, with filterable chips for Rules / Impact / Severity, collapsible impact groups, and per-finding accordions. It also links to per-tool detail reports in the "Detailed reports" subsection of its "About this report" panel:

| Report | Local path |
| --- | --- |
| **Unified Test Report** *(start here)* | `web/sites/default/files/test-reports/index.html` |
| Code-quality (lint) detail | `web/sites/default/files/test-reports/lint/lint-report.html` |
| Alfa detail | `web/sites/default/files/test-reports/alfa-full/alfa-full-report.html` |
| axe detail | `web/sites/default/files/test-reports/axe-full/axe-full-report.html` |
| pa11y detail | `web/sites/default/files/test-reports/pa11y/pa11y-report.html` |
| Reflow detail | `web/sites/default/files/test-reports/reflow/reflow-report.html` |
| Meta-viewport detail | `web/sites/default/files/test-reports/meta-viewport/meta-viewport-report.html` |
| PHPUnit detail | `web/sites/default/files/test-reports/phpunit/phpunit-report.html` |
| Scoped lint report *(local only)* | `web/sites/default/files/test-reports/scoped/lint/custom/index.html` |
| Scoped PHPUnit report *(local only)* | `web/sites/default/files/test-reports/scoped/phpunit/custom/index.html` |

Reports overwrite on each test run. Scoped reports are for local focused debugging and are not CI artifacts. The lint report has a per-module / per-theme / per-profile breakdown that's especially useful when you want to triage by code surface.

## CI

CI is **optional** and host-agnostic. **Locally** the suite is driven with
`drush utest:*`. **In CI** the runner tests a remote/deployed site and has no
local Drupal to bootstrap, so it calls the packages directly: the lint
orchestrator (`node code-quality/lint-orchestrator.js`), the Playwright a11y
specs (`npx playwright test accessibility/...`), and the PHPUnit runner
(`node tests/phpunit/run.js`), pointed at a reachable site URL via `BASE_URL`.
Nothing here requires a particular CI provider; see [`examples/ci/`](../examples/ci/)
for GitHub Actions, GitLab CI, and CircleCI starting points.

The example pipelines follow the suite's gating policy: the CI check fails on
critical or serious accessibility findings, or when a lane is incomplete
(it crawled 0 pages or errored on pages). Lint and PHPUnit are informational
and never fail the check. Whether a red check blocks merging depends on your
repository's required status checks.

**Skipping the suite for a hotfix** (GitHub Actions example workflow): add
the `skip-tests` label to the PR to skip the lint, functional, and
accessibility jobs (secrets scanning still runs).

## Troubleshooting

### Playwright cannot reach the URL

- Check the site is running and `BASE_URL` is right: `curl -I $BASE_URL`.
- DDEV / Lando: use the external URL (`*.ddev.site`, `*.lndo.site`).
- Self-signed certs: `export NODE_TLS_REJECT_UNAUTHORIZED=0` (local only,
  never CI).

### The sitemap is missing, or its URLs point at the wrong host

- The a11y lanes need a sitemap whose URLs match `BASE_URL`.
- No sitemap? Install [Simple XML Sitemap](https://www.drupal.org/project/simple_sitemap).
- Host mismatch (for example installer URLs)? Regenerate it:

  ```bash
  drush --uri=$BASE_URL simple-sitemap:rebuild-queue
  drush --uri=$BASE_URL simple-sitemap:generate
  drush cr
  ```

- URLs containing `index.php` are fine.

### A lane reports INCOMPLETE

- The run did not verify everything, usually because pages errored mid-crawl
  or the site went down.
- Check the lane report for which pages errored, then re-run.

### The unified report is stale, or one of its links 404s

- Single-lane runs do not rebuild `index.html`.
- Run `drush utest:report-render` (`utest:all` does it automatically).

### Lint reports findings I don't recognize, or skips some custom code

- Both come from custom-code path discovery.
- Run `drush utest:check-config` to see every path the suite scans.
- Adjust `tests/code-quality/config/custom-paths.json` (see
  [custom-paths config](#configuring-custom-code-paths)).

### A lint tool isn't installed

- A missing tool skips only its own lane, with a warning; the rest still runs.
- Run `composer install` and `npm ci` (in `tests/`) to enable everything.

### Where to file issues

- Test-suite bug (drush, reports, orchestrator): this repo
- Custom-code bug: your site's repo
- Contrib-code bug: drupal.org project page

## Command reference

The full command list (setup, every accessibility/code-quality/functional lane, scopes, and flags) lives in the [Cheat Sheet](README_cheatsheet.md).

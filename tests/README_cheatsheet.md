# Accessibility & Code Quality Testing Cheat Sheet

Quick command reference for accessibility, code-quality, and security testing.
See [the main README](README.md) for setup, configuration, and troubleshooting.

## One-time setup

Run these once to install the test dependencies (JS packages and Playwright
browsers) and verify your environment.

```bash
drush utest:js-install
drush utest:browsers
drush utest:check-config
```

`utest:check-config` flags anything missing before you run tests. It checks:

- PHP and Composer tools
- Node version and JS test packages
- Playwright browsers
- A writable report directory
- `BASE_URL` reachability
- Sitemap reachability and URL host match
- Any scoped `UTEST_CUSTOM_*` targets

Fix what it flags before running tests.

## Set your site URL once

Most commands accept `--base-url=…`. To skip typing it every time, export
your local URL:

```bash
export BASE_URL=https://my-site.test
```

If you use **DDEV**, **Lando**, or **direnv**, copy `tests/.env.example` to
`.env` at the project root and the tooling picks it up automatically.

## Run everything

```bash
drush utest:all
```

Full local/CI-style run. Runs in order, then renders the unified report:

1. Lint: full custom-code
2. PHPUnit: full custom-code (Functional / Regression)
3. Alfa full-site
4. pa11y
5. axe full-site
6. reflow
7. meta-viewport

- Ignores `UTEST_CUSTOM_*` scope, so a scoped local setting can't leave the report incomplete.
- Live per-page progress: `[1/50] Testing /home`.
- Report: `$BASE_URL/sites/default/files/test-reports/index.html` (pass/fail, filterable findings, per-tool links).
- Step order details: [README → utest:all](README.md#running-tests).

## Commands by category

Run a single check (faster than `utest:all`). Report paths below are relative
to `$BASE_URL/sites/default/files/test-reports/`.

| Category | Command | Checks | Report |
| --- | --- | --- | --- |
| **Accessibility** | `drush utest:alfa` | Siteimprove Alfa, full sitemap (deep rule set, no API key) | `alfa-full/alfa-full-report.html` |
| **Accessibility** | `drush utest:axe` | axe-core, full sitemap (no API key) | `axe-full/axe-full-report.html` |
| **Accessibility** | `drush utest:axe-watcher` | axe Developer Hub, full sitemap. Commercial service, needs `AXE_API_KEY` | Deque Developer Hub dashboard |
| **Accessibility** | `drush utest:pa11y` | pa11y-ci (HTML_CodeSniffer), full sitemap | `pa11y/pa11y-report.html` |
| **Accessibility** | `drush utest:reflow` | Reflow at 320px (WCAG 1.4.10) | `reflow/reflow-report.html` |
| **Accessibility** | `drush utest:meta-viewport` | Zoom not blocked (WCAG 1.4.4) | `meta-viewport/meta-viewport-report.html` |
| **Code Quality** | `drush utest:lint` | PHPCS, PHPStan, ESLint, cspell, composer audit, Markdown, etc. on custom code | `lint/lint-report.html` |
| **Functional** | `drush utest:phpunit` | Custom-code PHPUnit (Unit + Kernel), report-only | `phpunit/phpunit-report.html` |
| **Security** | `gitleaks detect --config=tests/code-quality/security/.gitleaks.toml --redact` | Committed-secret scan (details below) | Terminal output |

### Scope lint / PHPUnit to your code

`utest:lint` and `utest:phpunit` cover all custom code by default. Limit them to
specific modules, themes, or profiles with flags:

```bash
drush utest:lint --modules=my_module,other_module --themes=my_theme --profiles=my_profile
drush utest:phpunit --modules=my_module --themes=my_theme
```

For repeated runs, set the scope once with env vars instead of flags:

```bash
export UTEST_CUSTOM_MODULES=my_module,other_module
export UTEST_CUSTOM_THEMES=my_theme
export UTEST_CUSTOM_PROFILES=my_profile
drush utest:lint
drush utest:phpunit
```

- Flags override the `UTEST_CUSTOM_*` env vars; `--ignore-scope` runs the full lanes once.
- `utest:all` ignores scope entirely and always runs the full lanes.
- Scoped reports land at `scoped/lint/custom/index.html` and `scoped/phpunit/custom/index.html` (under `$BASE_URL/sites/default/files/test-reports/`).

## Full-site scans

The full-site a11y lanes (`alfa`, `axe`, `pa11y`, `reflow`, `meta-viewport`)
crawl your sitemap and cap at 50 pages by default to keep runs quick.

### Scan depth (`--max-pages`)

```bash
drush utest:alfa --max-pages=200      # bounded, thorough
drush utest:axe --max-pages=all       # every page in the sitemap
```

- `--max-pages=50` (the default): quick check while you work.
- `--max-pages=200`: deeper check that still finishes in a reasonable time.
- `--max-pages=all`: scans every page in the sitemap. Use for occasional full
  audits. Runtime grows with page count (axe takes about 3 minutes per 50
  pages) and each lane stops at 2 hours, so on large sites run it in the
  background (below).

### Pick a rule profile (`--a11y-profile`)

```bash
drush utest:alfa --a11y-profile=standard   # WCAG 2.0/2.1 A + AA only
drush utest:axe --a11y-profile=strict      # WCAG 2.0/2.1 A only
```

- The default is `comprehensive`: all WCAG levels plus best-practice rules.
- Profiles: `strict`, `standard`, `comprehensive`, `custom`.
- `export A11Y_PROFILE=standard` works too, for a whole session.
- Details and custom tags:
  [accessibility config README](accessibility/config/README.md).

### Long runs in the background

Big crawls and the whole suite can take a while. Detach with `nohup` so you can
keep working:

```bash
nohup drush utest:alfa --max-pages=all > /tmp/alfa-run.log 2>&1 &
nohup drush utest:all > /tmp/utest-all.log 2>&1 &
```

Watch progress or check whether it's still running:

```bash
tail -f /tmp/alfa-run.log        # live progress
jobs                             # background jobs in this shell
ps -ef | grep 'drush utest'      # find the run from any shell
```

After a standalone a11y lane finishes, refresh the unified report with
`drush utest:report-render` (`utest:all` does this automatically). The report is
at `$BASE_URL/sites/default/files/test-reports/index.html`.

## Check for committed secrets locally

CI runs `gitleaks` automatically, but if you want to scan before pushing:

```bash
# One-time install
brew install gitleaks            # macOS
# or
go install github.com/gitleaks/gitleaks/v8@latest

# Scan the whole repo against the project allowlist
gitleaks detect --config=tests/code-quality/security/.gitleaks.toml --redact

# Scan only what you've staged (faster, pre-commit feel)
gitleaks protect --config=tests/code-quality/security/.gitleaks.toml --staged --redact
```

## Common workflows

**Reviewing a custom module (code checks):**

```bash
drush utest:lint --modules=my_custom_module
drush utest:phpunit --modules=my_custom_module
```

**Reviewing a custom theme (code checks plus rendered pages):**

```bash
drush utest:lint --themes=my_theme
drush utest:alfa
drush utest:axe
```

The a11y lanes test pages from the sitemap, not code. Run them when your
change affects rendered output, and make sure a page using it is in the
sitemap.

**Before opening a PR (thorough):**

```bash
drush utest:all
```

**Just looking at code quality:**

```bash
drush utest:lint
```

**Investigating an a11y issue someone reported:**

```bash
drush utest:alfa --max-pages=all
```

---

## Running the suite in CI

CI is optional. **Locally** you drive the suite with `drush utest:*`. **In CI**
the runner tests a remote/deployed site and has no local Drupal to bootstrap, so
it calls the packages directly instead: the lint orchestrator
(`node code-quality/lint-orchestrator.js`), the Playwright a11y specs
(`npx playwright test accessibility/...`), and the PHPUnit runner
(`node tests/phpunit/run.js`), pointed at a reachable site URL via `BASE_URL`.
See [`examples/ci/`](../examples/ci/) for ready-to-copy pipelines.

What makes a CI check red:

- Critical or serious accessibility findings, or an incomplete lane
  (0 pages crawled, or pages that errored).
- Moderate and minor findings, lint, and PHPUnit never fail the check.
- Whether a red check blocks merging depends on the repository's required
  status checks.

# Accessibility & Code Quality Testing — Cheat Sheet

Quick command reference for accessibility, code-quality, and security testing.
See [the main README](README.md) for setup, configuration, and troubleshooting.

---

## One-time setup

Run these once after cloning the repo. They install the testing tools and
double-check your environment.

```bash
drush utest:js-install
drush utest:browsers
drush utest:check-config
```

`utest:check-config` will tell you if anything is missing (Node version,
Playwright browsers, accessible BASE_URL). Fix what it flags before running
tests.

---

## Set your site URL once

Most commands accept `--base-url=…`. To skip typing it every time, export
your local URL:

```bash
export BASE_URL=https://my-site.test
```

If you use **DDEV**, **Lando**, or **direnv**, copy `tests/.env.example` to
`.env` at the project root and the tooling picks it up automatically.

---

## Run everything

This is what you want most of the time. It runs the lint orchestrator,
the custom-module PHPUnit lane (Functional / Regression), and the full
a11y suite (Alfa full-site, pa11y, axe full-site, reflow, meta-viewport),
then renders the unified report. The key-pages variants stay available as
standalone commands (`utest:a11y:alfa`, `utest:a11y:axe`) for fast dev
feedback. See [README → utest:all step list](README.md#running-tests) for
the full order.

```bash
drush utest:all
```

**What you'll see**: live progress per test (`[1/50] Testing /home`, etc.).
**Where the report lands**:

```text
$BASE_URL/sites/default/files/test-reports/index.html
```

Open that URL in a browser; it shows pass/fail, filterable findings, and
links to per-tool details.

---

## Run one test at a time

Use these when you want to focus on a single check (faster than `utest:all`).

| Command | What it does |
| --- | --- |
| `drush utest:lint` | All code-quality checks (PHPCS, PHPStan, ESLint, cspell, composer audit, Markdown, etc.) on every custom module + theme + profile + custom PHP outside `web/` |
| `drush utest:phpunit` | Custom-module PHPUnit (Unit + Kernel); Functional / Regression, report-only |
| `drush utest:a11y:axe` | axe-core on 5 key pages; fastest a11y check, no API key needed |
| `drush utest:a11y:alfa` | Siteimprove Alfa on 5 key pages; deeper rule set, no API key needed |
| `drush utest:a11y:alfa-full` | Siteimprove Alfa on the first 50 sitemap pages |
| `drush utest:a11y:pa11y` | pa11y on the full sitemap |

**After a single-test run**, render the unified report so the new findings
roll into `index.html`:

```bash
drush utest:report-render
```

`utest:all` does this automatically; the per-test commands don't.

---

## Just my module / theme

```bash
# Check one module only
drush utest:lint --module=my_custom_module

# Check one theme only
drush utest:lint --theme=my_custom_theme
```

Custom install profiles under `web/profiles/custom/` are scanned automatically
when you run `drush utest:lint`; no flag needed.

---

## Scan more than 50 pages

The default 50-page cap on the full-site lanes keeps runs manageable. To scan
the whole sitemap:

```bash
drush utest:a11y:alfa-full --max-pages=all
drush utest:a11y:axe-full --max-pages=all
```

You can also pass an explicit number, e.g. `--max-pages=100`.

---

## Run a long scan in the background

Running a large sitemap and want to keep working? Detach the run from your
terminal:

```bash
nohup drush utest:a11y:alfa-full --max-pages=all \
  > /tmp/alfa-run.log 2>&1 &

# Watch progress whenever you want
tail -f /tmp/alfa-run.log

# Or just check if it's still going
jobs

# When finished, render the unified report
drush utest:report-render
```

Open the report at `$BASE_URL/sites/default/files/test-reports/index.html`.

---

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

---

## Where reports live

After any run, look here:

| Report | URL |
| --- | --- |
| **Unified Test Report (start here)** | `$BASE_URL/sites/default/files/test-reports/index.html` |
| Code-quality (lint) detail | `$BASE_URL/sites/default/files/test-reports/lint/lint-report.html` |
| Alfa full-site detail | `$BASE_URL/sites/default/files/test-reports/alfa-full/alfa-full-site-report.html` |
| Alfa key-pages detail | `$BASE_URL/sites/default/files/test-reports/alfa/alfa-report.html` |
| axe (local) detail | `$BASE_URL/sites/default/files/test-reports/axe/axe-report.html` |
| pa11y detail | `$BASE_URL/sites/default/files/test-reports/pa11y/pa11y-report.html` |

The unified report links to each per-tool report in its "Detailed reports
per tool" section, so opening just `index.html` is usually enough.

---

## Common workflows

**Before opening a PR (quick):**

```bash
drush utest:lint --module=my_custom_module
drush utest:a11y:axe
drush utest:report-render
```

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
drush utest:a11y:alfa-full --max-pages=all
drush utest:report-render
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

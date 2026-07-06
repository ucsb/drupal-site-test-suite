# Drupal Test Suite

Accessibility, code-quality, security, and functional/regression testing for **any Drupal 10 / 11 site**, exposed as `drush utest:*` commands with a unified, filterable report.

Hosting-agnostic in what it **tests**: it points at a Drupal site at any reachable URL, wherever that site is hosted. You **run** the suite itself from your local dev environment or a CI runner (anywhere you can install Node, Playwright, and PHP), then point it at the site under test. Locally, `drush utest:*` is the entry point; in CI the lanes run directly.

This repo is a **copy-in** distribution: you copy its files into your Drupal project. Adopt it on an individual site, or on a custom upstream so every downstream site inherits it.

## What's included

- **Accessibility:** Siteimprove Alfa, axe-core, pa11y, reflow (320 px), meta-viewport, each with its own command (`drush utest:alfa`, `utest:axe`, `utest:axe-watcher`, `utest:pa11y`, `utest:reflow`, `utest:meta-viewport`).
- **Code quality:** PHPCS, PHPStan, ESLint, Stylelint, TwigCS, HTMLHint, cspell, yaml-lint, editorconfig, markdownlint, actionlint, plus deprecation / next-major, reference-resolution, config-hygiene, and permission-baseline lanes.
- **Security:** `composer validate`/`audit`, gitleaks.
- **Functional / Regression:** custom-module PHPUnit (Unit + Kernel), report-only.

**Scope (this version):** the accessibility lanes crawl **public-facing pages** reachable without logging in (discovered via the sitemap or explicit paths). Pages behind a login are not crawled, so authenticated or role-specific content is not yet covered.

**How results read:** every lane uses the same gate: critical and serious findings fail the run, while moderate and minor findings are advisory. `drush utest:all` ends with a per-lane summary table (`PASSED`, `PASSED (advisory)`, `FAILED`, `INCOMPLETE`); a lane that crawls 0 pages or errors on pages is marked `INCOMPLETE` instead of passing. Each lane renders its own HTML report, and the unified report links them all.

Full command reference and usage live in **[`tests/README.md`](tests/README.md)** and the **[cheat sheet](tests/README_cheatsheet.md)**.

## Requirements

| Tool | Minimum |
| --- | --- |
| Drupal | 10.3+ (any later 10.x, such as 10.6) or 11.x. The report commands use the `FileExists` enum, added in core 10.3. |
| Drush | 12+ (via the project's Composer, not the platform's bundled drush) |
| Node.js | 20+ |
| PHP | 8.1+ |

The PHP lanes shell out to standard dev tools; add them to your project:

```bash
composer require --dev drupal/coder phpstan/phpstan mglaman/phpstan-drupal friendsoftwig/twigcs
```

**Docroot layout:** the suite expects the standard Composer project layout with
the docroot at `web/` (`web/modules/custom`, `web/sites/default/files`, and so
on). A configurable docroot is not yet supported. If your project uses a
different docroot (for example Acquia's `docroot/`), create a symlink at the
project root and commit it:

```bash
ln -s docroot web
git add web
```

## Install (copy-in)

**Set up and run the suite locally (or in CI), not on your hosting environment.** It needs Node 20+ and Playwright browsers, which managed hosts (e.g. Pantheon) don't support. Install it in your local dev environment (DDEV, Lando, a plain LAMP stack) or a CI runner, then point `BASE_URL` at the site you want to test (your local site, or a deployed/preview URL).

Clone this repo, then copy the suite into your Drupal **project root**, preserving the layout. Set `DST` to your project root:

```bash
git clone https://github.com/ucsb/drupal-site-test-suite.git
cd drupal-site-test-suite

DST=/path/to/your-drupal-project
mkdir -p "$DST/drush/Commands"
cp drush/Commands/TestingCommands.php drush/Commands/UTestReportCommands.php "$DST/drush/Commands/"
cp -R tests "$DST/"
```

If your project already has a `tests/` directory, merge into it instead. Note
that `rsync -a` silently overwrites files with the same name (for example an
existing `tests/package.json` or `tests/playwright.config.ts`), so preview
with a dry run first and keep backups of anything replaced:

```bash
rsync -an tests/ "$DST/tests/"                          # dry run: lists what would change
rsync -a --backup --suffix=.orig tests/ "$DST/tests/"   # merge; overwritten files keep a .orig copy
```

**Add the suite's ignore rules.** The copy does not bring this repo's
`.gitignore`, so add these rules to your project's `.gitignore` to keep
generated artifacts out of your commits:

```gitignore
# Drupal test suite: generated artifacts
tests/reports/*
!tests/reports/_shell/
tests/bin/
tests/playwright-report/
tests/test-results/
tests/test-run-results/
tests/.cache/
tests/node_modules/
tests/.pa11yci.json
.phpunit.result.cache

# Site-owned cspell dictionary: ignored so suite updates never touch it;
# commit your site's copy once with `git add -f`
tests/code-quality/spelling/.cspell/site-words.txt

# Local environment values (never commit real values)
.env
tests/.env
```

**Optional: copy a CI pipeline.** The CI examples are not part of the suite itself and nothing activates them automatically; copy one into your provider's config location. For example, for GitHub Actions on Pantheon:

```bash
mkdir -p "$DST/.github/workflows"
cp examples/ci/github-pantheon/workflows/* "$DST/.github/workflows/"   # workflows + pipeline guide README
cp examples/ci/github-pantheon/dependabot.yml "$DST/.github/"
```

Or the minimal host-agnostic variants: `examples/ci/github/test-suite.yml` into `$DST/.github/workflows/`, `examples/ci/gitlab/.gitlab-ci.yml` into `$DST/`, or `examples/ci/circleci/config.yml` into `$DST/.circleci/`. Each needs placeholders and secrets filled in before it will run; see [CI (optional)](#ci-optional) below and [`examples/ci/README.md`](examples/ci/README.md) for what to replace.

Drush discovers the commands under `drush/Commands/`. If `drush utest:*` isn't found, run `drush cr`. Then, **from your project root**:

```bash
drush utest:js-install                  # npm install for the suite (in tests/)
drush utest:browsers                    # Playwright Chromium
export BASE_URL=https://your-site.test  # the running site to test
drush utest:check-config                # pre-flight: Node, browsers, BASE_URL, sitemap, paths
drush utest:all                         # run everything, render the unified report
```

Open the report at `$BASE_URL/sites/default/files/test-reports/index.html`.

## Configure

**Most sites only need to set `BASE_URL`.** Either `export BASE_URL=https://your-site.test` in your shell, or copy `tests/.env.example` to `.env` (auto-loaded by direnv / DDEV / Lando). Run `drush utest:check-config` to confirm.

Everything else is optional, edited only as needed. These files are **consumer-owned**, so your edits survive a re-copy of a newer suite version:

| File | Edit it when… |
| --- | --- |
| `.env` | you want `BASE_URL` and other settings loaded automatically instead of exporting them. |
| `tests/reports/_shell/branding.json` | you want your org name, title, and footer on the report (ships neutral). |
| `tests/code-quality/config/custom-paths.json` | your custom code lives outside the standard `web/{modules,themes,profiles}/custom` paths (most sites skip this). |
| `tests/code-quality/spelling/.cspell/site-words.txt` | cspell flags site-specific terms you want to allow (untracked here; the lint lane creates it empty on first run; commit your copy with `git add -f`). |
| `tests/code-quality/static-analysis/phpstan-baseline.neon` | you want to silence pre-existing PHPStan findings; generate your own (ships empty). |
| `tests/code-quality/security/.gitleaks.toml` | gitleaks flags a false positive you want to allowlist. |

Everything else (drush commands, orchestrators, a11y specs, lint rules, report shell) is **suite-owned**, so re-copy it to update.

## CI (optional)

CI is optional. Locally you drive the suite with `drush utest:*`; in CI the runner tests a remote/deployed site and has no local Drupal to bootstrap, so it calls the lanes directly (the lint orchestrator, the Playwright a11y specs, and the PHPUnit runner) against a reachable `BASE_URL`. The runner needs Node 20+, Playwright, and PHP with Composer; not Drush. The runner does the work, not the host being tested.

The one host-specific part is **provisioning the running site** that `BASE_URL` points at: a Pantheon multidev (via `push-to-pantheon`), an Acquia on-demand environment, a CI-built local site, or an existing staging URL. The suite steps are identical regardless, so that provisioning step is left as a placeholder in each example.

[`examples/ci/`](examples/ci/) has starting points (none wired to run in this repo):

- [`github/`](examples/ci/github/): minimal generic GitHub Actions.
- [`gitlab/`](examples/ci/gitlab/): minimal generic GitLab CI.
- [`circleci/`](examples/ci/circleci/): minimal generic CircleCI.
- [`github-pantheon/`](examples/ci/github-pantheon/): full real-world Pantheon-multidev reference.

To use one, copy it into your CI location (e.g. `.github/workflows/`), supply a reachable `BASE_URL`, and add any host secrets. See [`examples/ci/README.md`](examples/ci/README.md) for the shared pattern, the host-specific provisioning notes, and why you should run only one CI provider per repo.

## Disclaimer

We built this suite for our own Drupal work and are sharing it in case it's useful
to others. It is provided **as-is, without warranty of any kind** and without any
commitment to support, maintenance, or future updates. Use at your own risk under
the terms of the [LICENSE](LICENSE).

- **Run it only against sites you are authorized to test.** The suite scans, and
  in some workflows can modify, the site you point it at.
- **Automated checks are not complete.** A clean report covers only what the tools
  check (for accessibility, roughly a third of WCAG, and public-facing pages only)
  and does **not** certify a site as accessible, secure, or compliant.
- **Review before you ship.** Treat results and any generated changes as a
  starting point that needs human review, not a guarantee of correctness. Nothing
  here is legal advice.

## Versioning & license

- Releases follow [Semantic Versioning](https://semver.org); see [`CHANGELOG.md`](CHANGELOG.md).
- Licensed under **GPL-2.0-or-later** (see [`LICENSE`](LICENSE)), matching the Drupal ecosystem.

# Example GitHub Actions CI (Pantheon multidev)

This directory holds an **example** GitHub Actions CI set, based on a Pantheon
multidev preview workflow. It is the pipeline the test suite's authors use, kept
here as a starting point you can copy and adapt.

These files are **not active in this repository.** Nothing here runs on push or
pull request — they live under `examples/` rather than `.github/workflows/`, so
GitHub never picks them up. To use them in your own project:

1. Sanitize the placeholders (see below) for your site.
2. Move the workflow files into `.github/workflows/`:
   - `workflows/pr-tests.yml`
   - `workflows/pr-cleanup.yml`
   - `workflows/pr-cleanup-enhanced.yml`
3. Move `dependabot.yml` into `.github/dependabot.yml`.
4. Add the referenced repository secrets in your repo settings
   (e.g. `PANTHEON_MACHINE_TOKEN`, `PANTHEON_SSH_KEY`, `AXE_API_KEY`).

## Placeholders to replace

| Placeholder | Replace with |
| --- | --- |
| `your-site` | Your Pantheon (or other host) site machine name |
| `example_theme` | Your custom theme machine name |
| `web/themes/custom/example_theme` | The real path to the theme |
| `Example Theme` | Friendly display name used in PR comments |
| `AXE_PROJECT_ID` (`00000000-...`) | Your axe Developer Hub project ID (optional) |
| `secrets.*` references | Secrets defined in your own repository settings |

The example runs a **single theme/site**. To test multiple themes, add more
entries under `strategy.matrix.include` in `pr-tests.yml` and list each site in
the `PANTHEON_SITES` env of the `pr-cleanup*` workflows.

## CI is optional; the suite is host-agnostic

The test suite does **not** depend on GitHub Actions or Pantheon. Any pipeline
works (GitLab CI, CircleCI, Jenkins, etc.), and CI is entirely optional. The hard
requirements are a **reachable site URL** (typically a preview or staging deploy
of the branch under review) and the suite toolchain (Node 20+, Playwright,
PHP 8.1+). In CI the lanes run **directly** against that URL: the lint
orchestrator (`node code-quality/lint-orchestrator.js`), the Playwright a11y
specs, and the PHPUnit runner (`node tests/phpunit/run.js`). `drush utest:*` is
the **local** entry point and needs a bootstrapped local Drupal, so it is not
used in CI.

This example happens to provision a per-PR Pantheon multidev as that reachable
URL, then runs the accessibility and code-quality lanes against it. On a
non-Pantheon host, replace the multidev provisioning and cleanup steps
(`pantheon-systems/push-to-pantheon`, `terminus ...`, the `pr-cleanup*`
workflows) with your platform's own deploy/preview mechanism, and point the
suite at whatever URL that produces. The test lanes themselves stay the same.

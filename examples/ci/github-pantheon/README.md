# Example GitHub Actions CI (Pantheon multidev)

An **example** GitHub Actions CI setup that tests every pull request against a
per-PR Pantheon multidev. It is the pipeline the test suite's authors use, kept
here as a starting point you can copy and adapt.

## What's in this directory

| File | What it does |
| --- | --- |
| `workflows/pr-tests.yml` | Main pipeline: lint, PHPUnit, gitleaks, and the accessibility lanes against a per-PR multidev |
| `workflows/pr-cleanup-enhanced.yml` | Deletes the multidev and GitHub deployment environments when a PR closes |
| `workflows/pr-cleanup.yml` | Dry run: previews what cleanup would delete, without deleting |
| `workflows/README.md` | The full pipeline guide (jobs, gating, secrets, troubleshooting) |
| `dependabot.yml` | Grouped weekly/monthly dependency update PRs |

## Not active in this repository

Nothing here runs on push or pull request. These files live under `examples/`
rather than `.github/workflows/`, so GitHub never picks them up.

## How to adopt it

1. Replace the placeholders (next section) with your site's values.
2. Copy the contents of `workflows/` into `.github/workflows/` in your
   project. That includes the pipeline guide (`workflows/README.md`), which
   belongs at `.github/workflows/README.md` in your repo.
3. Copy `dependabot.yml` to `.github/dependabot.yml`.
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

## Testing more than one theme

The example runs a **single theme/site**. To test multiple themes, add more
entries under `strategy.matrix.include` in `pr-tests.yml`, and list each site
in the `PANTHEON_SITES` env of the `pr-cleanup*` workflows.

## Using a different host

The test suite does **not** depend on GitHub Actions or Pantheon, and CI is
entirely optional. The hard requirements are:

- a **reachable site URL**, typically a preview or staging deploy of the
  branch under review, and
- the suite toolchain on the runner: Node 20+, Playwright, PHP 8.1+.

In CI the lanes run **directly** against that URL: the lint orchestrator
(`node code-quality/lint-orchestrator.js`), the Playwright a11y specs, and the
PHPUnit runner (`node tests/phpunit/run.js`). `drush utest:*` is the **local**
entry point. It needs a bootstrapped local Drupal, so it is not used in CI.

This example provisions a per-PR Pantheon multidev as that reachable URL. On a
non-Pantheon host, replace the Pantheon-specific parts, which are the multidev
provisioning and cleanup steps (`pantheon-systems/push-to-pantheon`,
`terminus ...`, the `pr-cleanup*` workflows), with your platform's own
deploy/preview mechanism, and point the suite at whatever URL that produces.
The test lanes themselves stay the same. Other providers (GitLab CI, CircleCI,
Jenkins, etc.) work the same way; see [`../README.md`](../README.md) for the
shared pattern.

## Full pipeline documentation

For how the pipeline behaves once installed (jobs and their dependencies,
gating and statuses, the multidev lifecycle, branch protection, skipping,
troubleshooting), see the guide that ships with the workflow files:
[`workflows/README.md`](workflows/README.md).

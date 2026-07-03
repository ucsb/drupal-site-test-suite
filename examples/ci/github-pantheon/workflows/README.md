# Accessibility & Code Quality Testing Pipeline

This guide explains the PR testing pipeline (workflow: `PR Quality Checks`):
code linting, functional/regression (PHPUnit), and accessibility testing, from
PR creation to final reporting. It ships alongside the workflow files so that
copying `workflows/` into `.github/workflows/` brings the documentation with
them. (Keep it at `.github/workflows/README.md`, not `.github/README.md`:
GitHub renders `.github/README.md` in place of your root `README.md` on the
repo homepage.)

## Table of Contents

1. [Quick Start](#quick-start)
2. [Pipeline Overview](#pipeline-overview)
3. [Setup](#setup)
4. [Skipping & Emergency Merges](#skipping--emergency-merges)
5. [Troubleshooting](#troubleshooting)

## Quick Start

**For developers getting started:**

1. **Set up repository secrets** (see [Setup](#setup))
2. **Configure branch protection** to require the test jobs to pass (see [Setup → Branch Protection](#branch-protection))
3. **Push changes**: tests run automatically on PRs
4. **Check results** in PR comments and downloadable reports

**Key Facts:**

- Runs on every PR; no manual trigger needed
- Tests every theme defined in the workflow matrix simultaneously (this example ships a single entry; see `pr-tests.yml` for the active list)
- WCAG 2.0/2.1 (Levels A, AA, AAA) + best-practice rules with the `comprehensive` profile; merge gating uses `critical` + `serious` severity only
- Multidev environments created automatically for realistic testing
- Skip with `[skip ci]` in the commit message for non-code changes

## Pipeline Overview

The pipeline runs code-quality + accessibility checks against every theme listed in the workflow matrix. Each matrix entry maps a theme to a Pantheon site for multidev provisioning. Projects adopting this pipeline replace the matrix entries in `pr-tests.yml` with their own theme + Pantheon-site pairs; no other doc changes are required.

### What Gets Tested

- **Code quality**: PHPCS, PHPStan, ESLint, cspell, composer (validate + audit), markdownlint, actionlint, twigcs, htmlhint, stylelint, yaml-lint, editorconfig, plus built-in static lanes: deprecation / next-major readiness, reference resolution, config hygiene, permission baseline
- **Accessibility**: Siteimprove Alfa (full sitemap; rule metadata from the in-process `@siteimprove/alfa-rules` SDK; no network/API key), axe-core (local + optional axe Developer Hub when an API key is configured), pa11y, reflow (320px viewport overflow, WCAG 1.4.10), meta-viewport (zoom-blocking check, WCAG 1.4.4)
- **Functional / Regression**: custom-module PHPUnit (Unit + Kernel), report-only
- **Secrets scanning**: gitleaks on the PR diff
- **WCAG coverage**: Levels A / AA / AAA + best-practice rules under the `comprehensive` profile; merge gating uses `critical` + `serious` only
- **Real content**: Tests against live Pantheon multidev environments with actual site content

### Pipeline Flow

1. **PR Trigger** → GitHub Actions workflow starts
2. **Multidev Creation** → Fresh environments created from each target's configured source environment
3. **Code Deployment** → PR changes deployed to each theme's site
4. **Parallel Testing** → every theme in the matrix tested simultaneously
5. **Result Reporting** → PR comments + downloadable reports
6. **Status Checks** → Branch protection enforcement
7. **Cleanup** → Automatic multidev removal on PR close/merge

---

## Setup

### Required Repository Secrets

Go to your repository → **Settings** → **Secrets and variables** → **Actions**:

#### `PANTHEON_MACHINE_TOKEN`

Authenticate with Pantheon to create multidev environments. Get from Pantheon Dashboard → Account → Machine Tokens.

#### `PANTHEON_SSH_KEY`

SSH key for secure git operations with Pantheon. Generate with:

```bash
ssh-keygen -t rsa -b 4096 -m PEM -C "pantheon-github-actions" -f ~/.ssh/pantheon_github_actions
```

- Add **public key** to Pantheon Account → SSH Keys
- Add **private key** content to GitHub repository secret

#### `AXE_API_KEY`

Authenticate with axe Developer Hub for enhanced accessibility testing. **Optional**: if it's unset, invalid, or the hub is unreachable, the axe Developer Hub tests are skipped cleanly (and the free axe-core full-site scan still runs), so the pipeline stays green either way. The PR comment notes when the Developer Hub tests were skipped.

#### `AXE_PROJECT_ID`

The axe Developer Hub project UUID, used to build dashboard links in PR comments. Set as a workflow-level `env` variable in `pr-tests.yml` (not a secret; it's not sensitive). The example ships a placeholder UUID; update it to match your own project, or leave it if you don't use the Developer Hub.

### Pantheon Sites Required

Each entry in the workflow matrix needs a corresponding Pantheon site to host its multidev. Create one Pantheon site per theme you want to test, then list each `theme_name` / `pantheon_site` pair in `pr-tests.yml`'s `matrix.include` block. The same site list must also appear in the `PANTHEON_SITES` env at the top of `pr-cleanup.yml` and `pr-cleanup-enhanced.yml` so multidev cleanup targets the right environments.

### Branch Protection

Configure your default branch to require the `PR Quality Checks` jobs as status checks. The workflow produces three single jobs plus one accessibility job per theme in the matrix:

- `Code linting`
- `Functional / Regression (PHPUnit)`
- `Secrets scanning (gitleaks)`
- `Accessibility Testing - <theme_name> Theme`: one per matrix entry

The exact names appear in the PR's checks list once the workflow has run at least once on a feature branch. Mark whichever of these should gate merges as required; if you matrix more than one theme, remember to add each new `Accessibility Testing - <theme_name> Theme` check.

### Workflow Features

#### Theme-specific reporting

Every job, comment, and artifact is scoped to one matrix entry so reviewers can tell which theme produced which finding. Replace `<theme_name>` and `<theme_display_name>` below with whatever you put in the workflow matrix.

**Job names:** three single jobs (`Code linting`, `Functional / Regression (PHPUnit)`, `Secrets scanning (gitleaks)`) plus one accessibility job per theme:

```text
Accessibility Testing - <theme_name> Theme
```

**PR comments include a header per matrix entry:**

```markdown
## <status_icon> Accessibility & Code Quality Testing - <theme_display_name>
**Accessibility profile**: `comprehensive` (covers WCAG 2.0 & 2.1 Levels A, AA, AAA + best-practice rules)
```

**Artifact names:**

```text
accessibility-reports-<theme_name>-theme
```

#### Testing configuration

See [What Gets Tested](#what-gets-tested) for the full engine list and WCAG coverage. This section covers how the jobs are wired:

- **Profile & gating**: `comprehensive` profile (set in `pr-tests.yml`); every accessibility lane (Alfa, axe-core, axe Developer Hub, pa11y, reflow, meta-viewport) gates on `critical` + `serious`; moderate and minor findings surface in the report but don't fail the run
- **Run outcomes**: each accessibility job ends `passed`, `failed` (a lane found critical or serious issues), or `incomplete` (a lane crashed, crawled 0 pages, or errored on pages mid-run). `incomplete` fails the check so it is never mistaken for a pass. Lane results come from each lane's `test-suite-findings.json` via `tests/accessibility/utils/lane-result.js`, and a per-lane summary table appears on the Actions run page
- **Functional / Regression**: the PHPUnit job runs before accessibility and is **report-only** (never fails the build); its result appears in the PR comment, and its standalone `phpunit-report.html` is linked from the unified report
- **Secrets scanning**: gitleaks runs as a separate job, once per PR
- **Skipping the suite**: add the `skip-tests` label to a PR to skip lint, functional, and accessibility (secrets scanning still runs), for hotfixes
- **Parallel execution**: every matrix entry runs simultaneously

#### Multidev Environment Management

For each PR, the workflow:

1. **Creates or reuses** unique multidev environments: `pr-{number}-{site-name}`
2. **Copies database and files** from each theme's configured source environment for realistic testing
3. **Deploys latest PR changes** to respective theme sites
4. **Runs accessibility tests** against live multidev URLs with real content
5. **Keeps multidevs alive** throughout the PR lifecycle
6. **Automatically cleans up** multidev environments when PR is closed/merged

**Multidev Lifecycle:**

- **First commit**: Creates new multidev environments from each theme's configured source environment (includes database + files)
- **Subsequent commits**: Reuses existing multidevs, deploys latest PR code changes via git, runs database updates and clears cache
- **PR closed/merged**: Automatically deletes multidev environments
- **Manual cleanup**: Available via GitHub Actions workflow dispatch

**Content Strategy:**

- **Source**: each target clones from its configured `source_env` (set per matrix entry in `pr-tests.yml`; the example uses `dev`), so tests run against representative content
- **Content cloning**: `clone_content: true` copies a fresh database + uploaded files from the source environment on each run, then applies database updates (updb) and clears cache

This gives every run consistent, realistic content via the standard Drupal deployment workflow, with no stale-content drift.

### Testing Process

**When Tests Run:**

- Pull request opened
- Pull request updated (new commits)
- Pull request reopened

**What gets tested:**

1. Code-quality checks on all custom modules, themes, and profiles
2. Accessibility checks per matrix entry, each against its dedicated Pantheon multidev
3. Secrets scanning (gitleaks) once per PR
4. All required status checks must pass for the PR to be mergeable (subject to branch protection)

**Test results:**

- Per-matrix-entry PR comments with theme-scoped pass/fail summary
- Downloadable per-theme test report artifacts
- Clear pass/fail status for each matrix entry
- Overall PR status reflects every matrix entry

### Multidev Cleanup

#### Automatic Cleanup

Multidev environments are automatically cleaned up by the **Enhanced PR Cleanup** workflow (`pr-cleanup-enhanced.yml`) when:

- **PR is merged** to the default branch
- **PR is closed** without merging

This also removes the corresponding GitHub deployment environments.

#### Manual Cleanup

If you need to manually clean up multidev environments:

1. Go to **Actions** tab in your repository
2. Select **"Enhanced PR Cleanup"** workflow
3. Click **"Run workflow"**
4. **Options:**
   - **PR number**: the PR whose multidev should be deleted. This is the normal case
   - **Confirm all**: required when no PR number is given. A full sweep deletes ALL `pr-*` multidevs, including environments for PRs still under review, so the workflow refuses to run without this confirmation
   - **Force cleanup**: Continue even if some deletions fail
   - **Cleanup GitHub environments**: Also remove GitHub deployment environments (default: yes)

**Cleanup Features:**

- **Automatic**: Runs when PRs are closed/merged
- **Selective**: Can target specific PR multidevs
- **Bulk**: Can cleanup all PR multidevs at once (guarded by **Confirm all**; use the dry run first)
- **Safe**: Confirms multidev exists before attempting deletion
- **GitHub environments**: Cleans up deployment environments and their deployments
- **Informative**: Posts cleanup confirmation to PR comments

#### Dry Run Preview

To see what *would* be cleaned up without actually deleting anything:

1. Go to **Actions** tab
2. Select **"PR Cleanup Dry Run (Preview Only)"** workflow (`pr-cleanup.yml`)
3. Click **"Run workflow"**
4. Optionally enter a PR number to check a specific environment
5. Review the output; it lists all multidevs and GitHub environments that would be deleted

#### Workflow Permissions

The cleanup workflows use the same `PANTHEON_MACHINE_TOKEN` secret as the main testing workflow; no additional secrets are needed. `GITHUB_TOKEN` is provided automatically by GitHub.

Each workflow declares the `GITHUB_TOKEN` scopes it needs in its own `permissions:` block, so no repository Actions settings need changing:

- `pr-tests.yml`: `contents: read`, `pull-requests: write` (PR comments), and `deployments: write` (push-to-pantheon creates the `pr-<N>` deployment records).
- `pr-cleanup-enhanced.yml`: `deployments: write` to delete GitHub deployment environments, plus `contents: read` and `pull-requests: write`.

If a run fails with "Missing required GitHub permissions", a needed scope was removed from that workflow's `permissions:` block.

### Workflow Files

| File | Purpose | Trigger |
| --- | --- | --- |
| `pr-tests.yml` | Main testing pipeline: linting, PHPUnit, gitleaks, Alfa, axe, pa11y, reflow, meta-viewport, report upload | `pull_request` (opened, synchronize, reopened) |
| `pr-cleanup-enhanced.yml` | Delete multidevs + GitHub environments | `pull_request` (closed) + `workflow_dispatch` |
| `pr-cleanup.yml` | Dry run: preview what would be cleaned up | `workflow_dispatch` only |

### Customization

#### Changing test profiles

Edit `pr-tests.yml`:

```yaml
env:
  A11Y_PROFILE: standard  # Options: strict, standard, comprehensive, custom
  A11Y_SEVERITY_LEVELS: critical,serious  # Options: critical, serious, moderate, minor
```

#### Adding more themes

Add entries to the matrix strategy in the workflow file:

```yaml
matrix:
  include:
    - theme_name: new_theme_name
      theme_display_name: "new_theme_name (Description)"
      pantheon_site: new-pantheon-site-name
      theme_path: web/themes/custom/new_theme_name
```

---

## Skipping & Emergency Merges

### Skipping CI on non-code changes

Add `[skip ci]` (or `[ci skip]`, `[no ci]`, `[skip actions]`, `***NO_CI***`) anywhere in the commit message. GitHub Actions skips the run for that push.

```bash
git commit -m "Update setup guide [skip ci]"
git commit -m "Bump composer dep [skip ci]"
```

Use it for docs, composer-only changes, comments, gitignore tweaks. **Do not** skip CI for theme code, Twig templates, CSS/JS, or anything that affects rendered output; those changes need the a11y lanes.

If a PR has WIP commits with `[skip ci]`, the **last** commit determines whether tests run before merge. Make sure the final commit triggers CI.

### Emergency merge

When tests legitimately can't pass (host outage, expired key, infrastructure issue) and the change must ship (typically a security patch or production-down fix), repository admins can override branch protection:

1. On the PR, open the **Merge pull request** dropdown.
2. Choose **Override branch protection and merge**.
3. In the merge commit, document **why** the override was needed and **what** follow-up is owed (typically: a tracking issue for any deferred a11y findings, with a target fix date).
4. After merging, file the follow-up issue and re-run tests against the default branch once the blocker clears.

Don't use overrides to skip tests because they're slow, because deadlines are tight on non-emergency work, or because findings look minor; fix the findings instead.

---

## Troubleshooting

### Common Issues

#### Authentication issues

- **"Missing required GitHub permissions"**: the workflow's `permissions:` block is missing a scope the failing action needs (push-to-pantheon needs `deployments: write`). See [Workflow Permissions](#workflow-permissions).
- **"Pantheon authentication failed"**: Check `PANTHEON_MACHINE_TOKEN` secret and permissions.
- **"axe Developer Hub authentication failed"**: Verify `AXE_API_KEY` secret is correct and active.
- **"SSH key authentication failed"**: Regenerate SSH key pair and update both Pantheon and GitHub secrets.

#### Multidev issues

- **"Multidev creation failed"**: Verify Pantheon sites exist and token has permissions.
- **"Site not ready"**: Wait up to 7.5 minutes for initialization, or check the multidev URL manually.
- **"Could not find environment"**: Multidev creation may have failed; check the Pantheon dashboard.

#### Test failures

- **Theme-specific test failures**: Check workflow artifacts for detailed reports.
- **Code linting issues**: Review the HTML report in `public://test-reports/lint/`.

#### Workflow issues

- **`[skip ci]` not working**: Use correct syntax: `git commit -m "message [skip ci]"`.
- **Workflow still running**: Check commit message for typos or re-run the workflow.
- **Branch protection conflicts**: Temporarily adjust rules if needed, then re-enable.

### Getting help

1. **Check workflow logs**: Actions tab → Failed workflow → Specific theme job.
2. **Download test reports**: Available as artifacts with theme-specific names.
3. **Review PR comments**: Detailed breakdown of failures and next steps.

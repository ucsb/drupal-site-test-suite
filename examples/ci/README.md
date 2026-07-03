# CI examples

CI is **optional**: a runner needs Node 20+, Playwright, and PHP 8.1+ (with
Composer) to run the lanes; no Drush is required in CI. These are starting points,
none wired to run in this repo.

The suite's own steps are the same on every platform:

1. Install the toolchain (PHP via `composer install`, then the suite's Node deps
   + Playwright browsers via `cd tests && npm ci && npx playwright install
   --with-deps`).
2. Run the lanes directly against a reachable `BASE_URL`: the lint orchestrator
   (`node code-quality/lint-orchestrator.js`), the Playwright a11y specs
   (`npx playwright test accessibility/...`), and the PHPUnit runner
   (`node tests/phpunit/run.js`).
3. Save `tests/reports/` as a build artifact.

`drush utest:*` is the LOCAL entry point (it needs a bootstrapped local Drupal);
a CI runner testing a remote site has none, so it calls the packages directly.

The lanes expect the docroot at `web/` in the checkout. If your project uses a
different docroot (for example `docroot/`), commit a `web` symlink at the
project root (`ln -s docroot web && git add web`) so CI checkouts see the
expected layout.

Every accessibility lane uses the same gate: **critical and serious findings
fail the run; moderate and minor findings are advisory**. A lane that crawls 0
pages or errors on pages is **INCOMPLETE**, not a pass. Derive each lane's
result with `node accessibility/utils/lane-result.js <findingsDir>` (prints
`passed` / `findings` / `failed` / `incomplete`) so incomplete runs fail the
check instead of slipping through green. Lint and PHPUnit are report-only and
never fail the check.

## Provisioning the site under test is host-specific

The one part these examples can't standardize is **standing up the running
Drupal site** that `BASE_URL` points at. That step depends on your host and is
left as a placeholder in each example:

| Host | How the site under test is typically provided |
| --- | --- |
| Pantheon | Deploy a per-PR multidev (the `pantheon-systems/push-to-pantheon` action). See [`github-pantheon/`](github-pantheon/). |
| Acquia | Deploy to an on-demand / CD environment via Acquia Cloud or Pipelines. |
| Self-managed / other | Build and serve the site inside the CI job, or point `BASE_URL` at an existing staging URL. |

The `push-to-pantheon` action and multidev flow in `github-pantheon/` are
**Pantheon-specific** and will not work on Acquia or other hosts. Swap that part
for your platform's deploy step; the suite steps after it stay identical.

## Pantheon Build Tools pipelines (CircleCI)

Many Pantheon Drupal projects use the **Pantheon Build Tools** template, which
ships the same `.ci/` scripts and the `quay.io/pantheon-public/build-tools-ci`
image across CircleCI, GitLab CI, and Bitbucket Pipelines. In each, a deploy step
(`./.ci/deploy/pantheon/dev-multidev`) creates a per-PR multidev, and the
site-dependent jobs (Behat, visual regression) run after it against that
multidev. The suite plugs in the same way: a job that runs **after the deploy**,
pointed at the multidev URL (`https://$TERMINUS_ENV-$TERMINUS_SITE.pantheonsite.io`,
where `set-environment` exports `TERMINUS_ENV`).

Add a `utest` job to `.circleci/config.yml` alongside the existing Build Tools
jobs, and require the deploy:

```yaml
jobs:
  # ... existing configure_env_vars / static_tests / deploy_to_pantheon ...

  utest:
    # build-tools-ci has PHP / Composer / Terminus but NOT the Node 20 +
    # Playwright browsers the a11y lanes need. Run this job on an image with both
    # PHP and Node (cimg/php:8.3-node), install the browsers, and carry
    # TERMINUS_ENV / TERMINUS_SITE from the deploy job via the workspace.
    docker:
      - image: cimg/php:8.3-node
    steps:
      - checkout
      - attach_workspace:
          at: /tmp/workspace
      - run: composer install --no-interaction --prefer-dist
      - run: cd tests && npm ci
      - run: cd tests && sudo npx playwright install --with-deps
      - run:
          name: run the suite against the multidev
          command: |
            source /tmp/workspace/bash_env.txt   # provides TERMINUS_ENV / TERMINUS_SITE
            export BASE_URL="https://${TERMINUS_ENV}-${TERMINUS_SITE}.pantheonsite.io"
            # CI tests a remote site, so call the lanes directly (not `drush utest:*`).
            cd tests
            node code-quality/lint-orchestrator.js
            npx playwright test accessibility/alfa/alfa-full-site.spec.js
            npx playwright test accessibility/axe/axe-full-site.spec.ts --config=playwright.config.ts
            npx playwright test accessibility/reflow/reflow.spec.js
            npx playwright test accessibility/meta-viewport/meta-viewport.spec.js
            cd ..
            node tests/phpunit/run.js tests/reports/phpunit
      - store_artifacts:
          path: tests/reports/
          destination: test-reports

workflows:
  build_deploy_and_test:
    jobs:
      # ... existing jobs ...
      - utest:
          requires:
            - deploy_to_pantheon
```

The GitLab CI and Bitbucket Pipelines variants of Build Tools follow the same
shape: add a stage/step that runs after the deploy, install the suite toolchain,
set `BASE_URL` to the multidev, and run the lanes directly (lint orchestrator +
Playwright a11y specs + the PHPUnit runner).

## Examples

| Example | Use it for |
| --- | --- |
| [`github/`](github/) | Minimal generic GitHub Actions pipeline (bring your own `BASE_URL`). |
| [`gitlab/`](gitlab/) | Minimal generic GitLab CI pipeline. |
| [`circleci/`](circleci/) | Minimal generic CircleCI pipeline. |
| [`github-pantheon/`](github-pantheon/) | Full real-world reference: per-PR Pantheon multidev provisioning + reporting (single theme/site). |

Bitbucket Pipelines, Jenkins, etc. follow the same shape; adapt the syntax.

## Pick one provider per repo

Each CI provider runs independently from its own config file (`.github/workflows/`,
`.gitlab-ci.yml`, `.circleci/config.yml`). If a repo has configs for two
providers and both are connected, **both pipelines run on every push** in
parallel, producing duplicate checks. When migrating from one to another, keep a
single config (or disconnect the old provider in its app) so only one runs.

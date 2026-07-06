<?php

namespace Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;

/**
 * Collects artifacts and builds an HTML index under public://test-reports.
 */
class UTestReportCommands extends DrushCommands {

  /**
   * Helpers.
   */
  protected function ensureDir(FileSystemInterface $fs, string $uri): void {
    if (!$fs->prepareDirectory($uri, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new \RuntimeException("Cannot create dir: $uri");
    }
  }

  /**
   * Build the externally reachable URL for a public:// URI.
   */
  protected function extUrl(StreamWrapperManagerInterface $swm, string $uri, ?string $baseUrl = NULL): string {
    $sw = $swm->getViaUri($uri);
    $external = $sw ? $sw->getExternalUrl() : $uri;
    if ($baseUrl && $sw) {
      // Re-root the wrapper's external URL onto the provided base URL. The
      // wrapper path reflects the site's real public-files location
      // (multisite directories, a custom file_public_path), so nothing is
      // hardcoded here.
      $path = parse_url($external, PHP_URL_PATH);
      if (is_string($path) && $path !== '') {
        return rtrim($baseUrl, '/') . $path;
      }
    }
    return $external;
  }

  /**
   * Render the unified test-suite report from per-test findings files.
   *
   * Reads each test's
   * test-suite-findings.json (the stable contract in
   * tests/reports/_shell/findings.schema.json) and renders the
   * Accessibility, Security, and Code Quality Report via
   * tests/reports/_shell/index.template.html. The output is a single
   * self-contained HTML file the user can open over Valet, SFTP, or a
   * multidev URL - no asset path concerns.
   */
  #[CLI\Command(name: 'utest:report-render', aliases: ['utrender'])]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  #[CLI\Option(name: 'dest-uri', description: 'Where the rendered index.html lands (defaults to public://test-reports - sits alongside the per-test lane dirs).')]
  #[CLI\Option(name: 'src-uri', description: 'Where the per-test test-suite-findings.json files live (defaults to public://test-reports).')]
  #[CLI\Option(name: 'base-url', description: 'Optional public URL to log when done.')]
  public function renderUnifiedReport(
    array $options = [
      'dest-uri' => 'public://test-reports',
      'src-uri'  => 'public://test-reports',
      'base-url' => NULL,
    ],
  ) {
    $fs   = \Drupal::service('file_system');
    $repo = \Drupal::service('file.repository');
    $swm  = \Drupal::service('stream_wrapper_manager');

    $destBase = (string) $options['dest-uri'];
    $srcBase  = (string) $options['src-uri'];
    $this->ensureDir($fs, $destBase);

    // Compute relative href prefix so the "Detailed reports per tool"
    // links work whether dest-uri sits at src-uri (no prefix) or one
    // level deeper (e.g. legacy public://test-reports/local → "../").
    $destPathPart = preg_replace('#^[a-z]+://#i', '', rtrim($destBase, '/'));
    $srcPathPart  = preg_replace('#^[a-z]+://#i', '', rtrim($srcBase, '/'));
    $relPrefix    = ($destPathPart === $srcPathPart) ? '' : '../';

    // Per-test directory map. Order matches the shell's preferred display
    // grouping (a11y first, then lint). Tests not yet wired to emit
    // test-suite-findings.json are silently skipped - they'll show up once
    // their emitter lands.
    $tests = [
      'alfa-full',
      'alfa',
      'axe-watcher-full',
      'axe-watcher',
      'axe-full',
      'axe',
      'pa11y',
      'reflow',
      'meta-viewport',
      'lint',
      'phpunit',
    ];

    // Only these tests get a link in the shell's "Detailed reports per
    // tool" section. The key-pages variants (alfa, axe) are intentionally
    // excluded - they're dev-feedback subsets of the full-site runs and
    // their per-tool surfaces duplicate findings already aggregated in the
    // unified view. Findings still aggregate from every test in $tests
    // above; only the per-tool HTML link is suppressed for key-pages.
    $linkedTests = ['alfa-full', 'axe-full', 'pa11y', 'reflow', 'meta-viewport', 'lint', 'phpunit'];

    // Tests whose test-suite-findings.json is the canonical full-coverage
    // artifact for downstream automation (CI gates, accessibility agents).
    // The key-pages
    // a11y variants are intentionally excluded - they're a fast-feedback
    // subset of the full-site runs and would just produce duplicate JSON.
    $machineReadableTests = ['lint', 'alfa-full', 'axe-full', 'pa11y', 'reflow', 'meta-viewport', 'phpunit'];

    $aggregatedTests = [];
    $rawReports = [];
    $machineReadable = [];
    $surface = NULL;
    foreach ($tests as $name) {
      $findingsUri = rtrim($srcBase, '/') . '/' . $name . '/test-suite-findings.json';
      $findingsReal = $fs->realpath($findingsUri);
      if (!$findingsReal || !is_file($findingsReal)) {
        continue;
      }
      $raw = file_get_contents($findingsReal);
      $data = $raw ? json_decode($raw, TRUE) : NULL;
      if (!is_array($data) || ($data['schema_version'] ?? NULL) !== '1.0') {
        $this->logger()->warning("Skipping malformed or wrong-version test-suite-findings.json: $findingsUri");
        continue;
      }
      // First test's surface wins; all tests in a single run should share it.
      if ($surface === NULL && !empty($data['surface'])) {
        $surface = $data['surface'];
      }
      // Strip envelope-only fields so each entry matches the shell's per-test
      // shape (test/tool/profile/summary/findings/duration_ms).
      $entry = $data;
      unset($entry['schema_version'], $entry['surface'], $entry['raw_reports'], $entry['generated_at']);
      $aggregatedTests[] = $entry;

      // Surface test-suite-findings.json as a machine-readable artifact in
      // the unified shell so downstream consumers can locate it without
      // scraping HTML.
      // Only the canonical full-coverage tests are listed.
      if (in_array($name, $machineReadableTests, TRUE)) {
        $sizeBytes = filesize($findingsReal);
        $machineReadable[] = [
          'label'   => $name . '/test-suite-findings.json',
          'href'    => $relPrefix . $name . '/test-suite-findings.json',
          'size_kb' => $sizeBytes !== FALSE ? round($sizeBytes / 1024, 1) : NULL,
        ];
      }

      // Locate the per-test HTML report next to its
      // test-suite-findings.json so the shell's "Detailed reports per tool"
      // section can link to it. Path is
      // relative to the unified index.html. Skip tests excluded from
      // $linkedTests (today: axe variants).
      if (!in_array($name, $linkedTests, TRUE)) {
        continue;
      }
      $htmlCandidate = $this->findRawReportHtml($fs, rtrim($srcBase, '/') . '/' . $name);
      if ($htmlCandidate !== NULL) {
        $rawReports[] = [
          'label' => $this->testLabel($name),
          'href'  => $relPrefix . $name . '/' . $htmlCandidate,
        ];
      }
    }

    // Build the aggregated payload. Mirrors the shape sample-report-data.json
    // demonstrates and that index.template.html consumes.
    $payload = [
      'schema_version'   => '1.0',
      'generated_at'     => gmdate('c'),
      'surface'          => $surface ?: ['context' => 'local'],
      'raw_reports'      => $rawReports,
      'machine_readable' => $machineReadable,
      'tests'            => $aggregatedTests,
    ];

    // Load the shell template from the repo (lives outside web/).
    $projectRoot = dirname(__DIR__, 2);
    $templatePath = $projectRoot . '/tests/reports/_shell/index.template.html';
    if (!is_file($templatePath)) {
      throw new \RuntimeException("Shell template not found at $templatePath");
    }
    $template = file_get_contents($templatePath);

    // Substitute the JSON payload. Encode with JSON_UNESCAPED_SLASHES so URLs
    // stay readable; the placeholder lives inside
    // <script type="application/json"> so embedded HTML special chars
    // (`</script>`) are the only escape concern.
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === FALSE) {
      throw new \RuntimeException('Failed to encode payload: ' . json_last_error_msg());
    }
    // Defuse any literal </script> in tool messages so the inline JSON block
    // can't break out of its container.
    $json = str_replace('</', '<\\/', $json);

    $html = str_replace('{{REPORT_DATA_JSON}}', $json, $template);

    $outputUri = rtrim($destBase, '/') . '/index.html';
    $repo->writeData($html, $outputUri, FileExists::Replace);

    $count = count($aggregatedTests);
    $this->logger()->success(sprintf(
      'Rendered Test Report (%d test%s): %s',
      $count, $count === 1 ? '' : 's',
      $this->extUrl($swm, $outputUri, $options['base-url'])
    ));
  }

  /**
   * Find the canonical per-test HTML report inside its directory.
   *
   * Lets the unified shell link to it. Returns the basename relative to
   * the test's directory, or null if no recognized report exists.
   */
  protected function findRawReportHtml(FileSystemInterface $fs, string $dirUri): ?string {
    $real = $fs->realpath($dirUri);
    if (!$real || !is_dir($real)) {
      return NULL;
    }
    $candidates = [
      'alfa-full-report.html',
      'alfa-full-site-report.html',
      'alfa-report.html',
      'axe-full-report.html',
      'axe-report.html',
      'axe-watcher-report.html',
      'axe-watcher-full-report.html',
      'pa11y-report.html',
      'reflow-report.html',
      'meta-viewport-report.html',
      'lint-report.html',
      'phpunit-report.html',
      'index.html',
    ];
    foreach ($candidates as $candidate) {
      if (is_file($real . DIRECTORY_SEPARATOR . $candidate)) {
        return $candidate;
      }
    }
    return NULL;
  }

  /**
   * Human-readable label for a test identifier.
   *
   * Used in the shell's "Detailed reports per tool" section.
   */
  protected function testLabel(string $name): string {
    // The "(key pages)" qualifiers exist for any future surface that lists
    // every test, but the unified report's "Detailed reports per tool"
    // currently only links the canonical full-coverage tests, so users
    // see "Siteimprove Alfa" and "axe Developer Hub" without parentheticals.
    return [
      'alfa'             => 'Siteimprove Alfa (key pages)',
      'alfa-full'        => 'Siteimprove Alfa',
      'axe'              => 'axe-core (key pages)',
      'axe-full'         => 'axe-core (full site)',
      'axe-watcher'      => 'axe Developer Hub (key pages)',
      'axe-watcher-full' => 'axe Developer Hub',
      'pa11y'            => 'pa11y',
      'reflow'           => 'Reflow (WCAG 2.1 SC 1.4.10)',
      'meta-viewport'    => 'Meta-viewport (WCAG 2.0 SC 1.4.4)',
      'lint'             => 'Code quality (lint)',
      'phpunit'          => 'Functional / Regression (PHPUnit)',
    ][$name] ?? $name;
  }

}

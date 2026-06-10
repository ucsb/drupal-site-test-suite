<?php

namespace Drush\Commands;

use Twig\Environment;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;

/**
 * Collects artifacts and builds a simple HTML index under public://test-reports/<run-id>/
 */
class UTestReportCommands extends DrushCommands {

  /**
   * @command utest:report-index
   * @aliases utidx
   * @bootstrap full
   */
  public function buildIndex(
    array $options = [
      'dest-uri'       => 'public://test-reports',
      'run-id'         => 'local',
      'title'          => 'Test Reports',
      'base-url'       => NULL,
      'collect'        => TRUE,
      'lint-src'       => 'sites/default/files/test-reports/lint',
      'axe-src'        => 'sites/default/files/test-reports/axe',
      'alfa-src'       => 'sites/default/files/test-reports/alfa',
      'alfa-full-src'  => 'sites/default/files/test-reports/alfa-full',
      'pa11y-log-src'  => 'sites/default/files/test-reports/pa11y/pa11y.log',
      'latest-alias'   => FALSE,
      'lint-result'    => NULL,
      'axe-result'     => NULL,
      'alfa-result'    => NULL,
      'alfa-full-result' => NULL,
      'pa11y-result'   => NULL,
      'site-base-url'  => NULL,
      'site-sitemap'   => NULL,
      'site-profile'   => NULL,
      'site-paths'     => NULL,
    ],
  ) {
    $fs   = \Drupal::service('file_system');
    $repo = \Drupal::service('file.repository');
    $swm  = \Drupal::service('stream_wrapper_manager');
    $twig = \Drupal::service('twig');

    $destBase = (string) $options['dest-uri'];
    $runId    = trim((string) $options['run-id']) ?: 'local';
    $title    = (string) $options['title'] ?: 'Test Reports';

    $this->ensureDir($fs, $destBase);
    $runUri = rtrim($destBase, '/') . '/' . $runId;
    $this->ensureDir($fs, $runUri);

    if (!empty($options['collect'])) {
      // Copy lint reports.
      $lintSrc = $options['lint-src'] ?? 'sites/default/files/test-reports/lint';
      $this->logger()->notice("Checking lint source: $lintSrc");

      if (is_dir($lintSrc)) {
        $this->logger()->notice("Lint source is a directory, copying...");
        try {
          $this->copyDir($fs, $repo, $lintSrc, $runUri . '/lint');
          $this->logger()->notice("Lint directory copied successfully");
        }
        catch (\Exception $e) {
          $this->logger()->error("Failed to copy lint directory: " . $e->getMessage());
        }
      }
      else {
        $this->logger()->warning("Lint source not found: $lintSrc");
      }

      // Copy axe reports.
      $axeSrc = $options['axe-src'];
      $this->logger()->notice("Checking axe source: $axeSrc");

      if (is_dir($axeSrc)) {
        $this->logger()->notice("Axe source is a directory, copying...");
        try {
          $this->copyDir($fs, $repo, $axeSrc, $runUri . '/axe');
          $this->logger()->notice("Axe directory copied successfully");
        }
        catch (\Exception $e) {
          $this->logger()->error("Failed to copy axe directory: " . $e->getMessage());
        }
      }
      else {
        $this->logger()->warning("Axe source not found: $axeSrc");
      }

      // Copy alfa reports.
      $alfaSrc = $options['alfa-src'];
      $this->logger()->notice("Checking alfa source: $alfaSrc");

      if (is_dir($alfaSrc)) {
        $this->logger()->notice("Alfa source is a directory, copying...");
        try {
          $this->copyDir($fs, $repo, $alfaSrc, $runUri . '/alfa');
          $this->logger()->notice("Alfa directory copied successfully");
        }
        catch (\Exception $e) {
          $this->logger()->error("Failed to copy alfa directory: " . $e->getMessage());
        }
      }
      else {
        $this->logger()->warning("Alfa source not found: $alfaSrc");
      }

      // Copy alfa-full reports.
      $alfaFullSrc = $options['alfa-full-src'] ?? 'sites/default/files/test-reports/alfa-full';
      $this->logger()->notice("Checking alfa-full source: $alfaFullSrc");

      if (is_dir($alfaFullSrc)) {
        $this->logger()->notice("Alfa-full source is a directory, copying...");
        try {
          $this->copyDir($fs, $repo, $alfaFullSrc, $runUri . '/alfa-full');
          $this->logger()->notice("Alfa-full directory copied successfully");
        }
        catch (\Exception $e) {
          $this->logger()->error("Failed to copy alfa-full directory: " . $e->getMessage());
        }
      }
      else {
        $this->logger()->warning("Alfa-full source not found: $alfaFullSrc");
      }

      // pa11y.
      $pa11ySrc = $options['pa11y-log-src'];
      $pa11yDir = dirname($pa11ySrc);
      $this->logger()->notice("Checking pa11y source: $pa11ySrc (dir: $pa11yDir)");

      // Copy the full pa11y report directory if it exists (includes HTML + JSON + log)
      if (is_dir($pa11yDir)) {
        $this->logger()->notice("pa11y directory found, copying...");
        try {
          $this->copyDir($fs, $repo, $pa11yDir, $runUri . '/pa11y');
          $this->logger()->notice("pa11y directory copied successfully");
        }
        catch (\Exception $e) {
          $this->logger()->error("Failed to copy pa11y directory: " . $e->getMessage());
        }
      }
      elseif (is_file($pa11ySrc)) {
        // Fallback: wrap raw log as HTML.
        $this->logger()->notice("pa11y log file found, converting to HTML...");
        try {
          $log = file_get_contents($pa11ySrc) ?: '';
          $html = "<!doctype html><meta charset=\"utf-8\"><title>pa11y log</title><pre>" . htmlspecialchars($log, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</pre>";
          $repo->writeData($html, $runUri . '/pa11y.html', FileSystemInterface::EXISTS_REPLACE);
          $this->logger()->notice("pa11y HTML file created successfully");
        }
        catch (\Exception $e) {
          $this->logger()->error("Failed to create pa11y HTML: " . $e->getMessage());
        }
      }
      else {
        $this->logger()->warning("pa11y source not found: $pa11ySrc");
      }
    }

    // Check for different report types and their HTML files.
    $axeReportFile = $this->findAxeReport($fs, $runUri . '/axe');
    $alfaReportFile = $this->findAlfaReport($fs, $runUri . '/alfa');
    $alfaFullReportFile = $this->findFile($fs, $runUri . '/alfa-full', 'alfa-full-site-report.html');
    // Alfa quick report (5 key pages) is always in alfa/ dir.
    $alfaQuickReportFile = $alfaReportFile;

    // Get list of all files in the report directory.
    $allFiles = $this->getAllReportFiles($fs, $runUri);

    $vars = [
      'title'          => $title,
      'generated'      => \Drupal::service('date.formatter')->format(\Drupal::time()->getRequestTime(), 'custom', 'Y-m-d H:i:s'),
      'has_axe'        => !empty($axeReportFile),
      'has_alfa'       => !empty($alfaQuickReportFile),
      'has_alfa_full'  => !empty($alfaFullReportFile),
      'has_pa11y'      => $this->exists($fs, $runUri . '/pa11y/pa11y-report.html') || $this->exists($fs, $runUri . '/pa11y.html'),
      'has_lint'       => $this->exists($fs, $runUri . '/lint/lint-report.html'),
      'all_files'      => $allFiles,
      'paths'          => [
        'axe'        => $axeReportFile ?: 'axe/axe-report.html',
        'alfa'       => $alfaQuickReportFile ?: 'alfa/alfa-report.html',
        'alfa_full'  => $alfaFullReportFile ?: 'alfa/alfa-full-site-report.html',
        'pa11y'      => $this->exists($fs, $runUri . '/pa11y/pa11y-report.html') ? 'pa11y/pa11y-report.html' : 'pa11y.html',
        'lint'       => 'lint/lint-report.html',
      ],
      'results'        => [
        'lint'      => $options['lint-result'] ?? NULL,
        'axe'       => $options['axe-result'] ?? NULL,
        'alfa'      => $options['alfa-result'] ?? NULL,
        'alfa_full' => $options['alfa-full-result'] ?? NULL,
        'pa11y'     => $options['pa11y-result'] ?? NULL,
      ],
      'site' => [
        'base_url' => $options['site-base-url'] ?? $options['base-url'] ?? NULL,
        'sitemap'  => $options['site-sitemap'] ?? NULL,
        'profile'  => $this->resolveProfileName($options['site-profile'] ?? NULL),
        'paths'    => $options['site-paths'] ?? NULL,
      ],
    ];
    $index = $this->renderIndex($twig, $vars);
    $repo->writeData($index, $runUri . '/index.html', FileSystemInterface::EXISTS_REPLACE);

    // The 'latest-alias' option is retained for backward compatibility but
    // defaults off — the canonical Test Report now lives at
    // public://test-reports/index.html and there is no run-id to alias.
    $this->logger()->success('Index: ' . $this->extUrl($swm, $runUri . '/index.html', $options['base-url']));
  }

  /**
   * Helpers.
   */
  protected function ensureDir(FileSystemInterface $fs, string $uri): void {
    if (!$fs->prepareDirectory($uri, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new \RuntimeException("Cannot create dir: $uri");
    }
  }

  /**
   *
   */
  protected function copyDir(FileSystemInterface $fs, FileRepositoryInterface $repo, string $src, string $dest): void {
    $this->ensureDir($fs, $dest);

    // Get the real paths for source and destination.
    $srcReal = realpath($src);
    $destReal = $fs->realpath($dest);

    if (!$srcReal || !$destReal) {
      throw new \RuntimeException("Cannot resolve paths: src=$src, dest=$dest");
    }

    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcReal, \FilesystemIterator::SKIP_DOTS));

    foreach ($it as $file) {
      if ($file->isDir()) {
        continue;
      }

      // Calculate relative path from source.
      $relativePath = substr($file->getPathname(), strlen($srcReal) + 1);
      $destFilePath = $destReal . DIRECTORY_SEPARATOR . $relativePath;

      // Create directory structure if needed.
      $destFileDir = dirname($destFilePath);
      if (!is_dir($destFileDir)) {
        mkdir($destFileDir, 0777, TRUE);
      }

      // Copy file directly.
      copy($file->getPathname(), $destFilePath);
    }
  }

  /**
   *
   */
  protected function exists(FileSystemInterface $fs, string $uri): bool {
    $real = $fs->realpath($uri);
    return $real && is_file($real);
  }

  /**
   *
   */
  protected function hasHtml(FileSystemInterface $fs, string $uri): bool {
    $real = $fs->realpath($uri);
    if (!$real || !is_dir($real)) {
      return FALSE;
    }
    foreach (scandir($real) ?: [] as $f) {
      if (preg_match('/\\.html?$/i', $f)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Find the best axe report file in the given directory.
   *
   * @param \Drupal\Core\File\FileSystemInterface $fs
   *   The file system service.
   * @param string $uri
   *   The directory URI to search.
   *
   * @return string|null
   *   The relative path to the report file, or null if not found.
   */
  protected function findAxeReport(FileSystemInterface $fs, string $uri): ?string {
    $real = $fs->realpath($uri);
    if (!$real || !is_dir($real)) {
      return NULL;
    }

    // Priority order for axe report files.
    $candidates = [
      'axe-report.html',
      'axe-watcher-report.html',
      'axe-watcher-full-report.html',
      'index.html',
    ];

    foreach ($candidates as $candidate) {
      if (is_file($real . DIRECTORY_SEPARATOR . $candidate)) {
        return 'axe/' . $candidate;
      }
    }

    return NULL;
  }

  /**
   * Find the best alfa report file in the given directory.
   *
   * @param \Drupal\Core\File\FileSystemInterface $fs
   *   The file system service.
   * @param string $uri
   *   The directory URI to search.
   *
   * @return string|null
   *   The relative path to the report file, or null if not found.
   */
  protected function findAlfaReport(FileSystemInterface $fs, string $uri): ?string {
    $real = $fs->realpath($uri);
    if (!$real || !is_dir($real)) {
      return NULL;
    }

    // Priority order for alfa report files (prefer the quick/paths report)
    $candidates = [
      'alfa-report.html',
      'index.html',
    ];

    foreach ($candidates as $candidate) {
      if (is_file($real . DIRECTORY_SEPARATOR . $candidate)) {
        return 'alfa/' . $candidate;
      }
    }

    return NULL;
  }

  /**
   * Check if a specific file exists in a directory.
   */
  protected function findFile(FileSystemInterface $fs, string $uri, string $filename): ?string {
    $real = $fs->realpath($uri);
    if (!$real || !is_dir($real)) {
      return NULL;
    }
    if (is_file($real . DIRECTORY_SEPARATOR . $filename)) {
      // Return relative path from parent dir name.
      return basename($uri) . '/' . $filename;
    }
    return NULL;
  }

  /**
   *
   */
  protected function extUrl(StreamWrapperManagerInterface $swm, string $uri, ?string $baseUrl = NULL): string {
    if ($baseUrl) {
      // Use provided base URL to construct the external URL.
      $baseUrl = rtrim($baseUrl, '/');
      // Extract the path from the URI (e.g., "public://test-reports/local/index.html" -> "/sites/default/files/test-reports/local/index.html")
      if (strpos($uri, 'public://') === 0) {
        $path = '/sites/default/files/' . substr($uri, 9);
        return $baseUrl . $path;
      }
    }

    // Fallback to Drupal's stream wrapper.
    $sw = $swm->getViaUri($uri);
    return $sw ? $sw->getExternalUrl() : $uri;
  }

  /**
   *
   */
  protected function renderIndex(Environment $twig, array $v): string {
    $tpl = <<<'TWIG'
<!doctype html><meta charset="utf-8"><title>{{ title|e }} — Test Reports</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;padding:20px;background:#f8f9fa}
.container{max-width:1200px;margin:0 auto;background:white;padding:30px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.1)}
h1{margin:0 0 8px;color:#333;border-bottom:3px solid #007cba;padding-bottom:10px;font-size:1.8em}
.ts{color:#666;margin-bottom:24px;font-size:0.9em}
.site-info{background:#f0f6fb;border:1px solid #c8dde9;border-radius:6px;padding:16px 20px;margin-bottom:24px;font-size:0.9em;line-height:1.7}
.site-info strong{color:#333}
.site-info a{color:#007cba}
.section{margin:20px 0}
.section h2{color:#555;margin:15px 0 10px;font-size:1.1em;padding-left:0}
ul{line-height:1.8;list-style:none;padding:0}
li{margin:8px 0;padding:8px 12px;background:#f8f9fa;border-radius:4px}
li.empty{border-left-color:#6c757d;color:#666;font-style:italic}
li.unavailable{border-left-color:#dc3545}
a{color:#007cba;text-decoration:none;font-weight:500}
a:hover{text-decoration:underline}
.status{font-size:0.85em;color:#666;margin-left:10px}
.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:16px 0}
.result-badge{padding:14px;border-radius:8px;text-align:center;font-weight:600;font-size:0.95em}
.result-badge .label{font-size:0.8em;font-weight:400;opacity:0.85;display:block;margin-bottom:4px}
.result-badge.passed{background:#d4edda;color:#155724;border:1px solid #c3e6cb}
.result-badge.failed{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb}
.result-badge.skipped{background:#e2e3e5;color:#495057;border:1px solid #d6d8db}
.tag{display:inline-block;padding:2px 8px;border-radius:10px;font-size:0.75em;font-weight:600;vertical-align:middle;margin-right:4px}
.tag-lint{background:#17a2b8;color:#fff}
.tag-axe{background:#28a745;color:#fff}
.tag-alfa{background:#6f42c1;color:#fff}
.tag-pa11y{background:#fd7e14;color:#fff}
.tag-file{background:#6c757d;color:#fff}
.tool-desc{margin:8px 0;padding:10px 14px;background:#f8f9fa;border-radius:4px;border-left:4px solid #007cba;font-size:0.9em}
.report-desc{margin:4px 0 0;font-size:0.82em;color:#666;line-height:1.4}
.scope{font-size:0.8em;color:#888;font-weight:400}
code{background:#f0f0f0;padding:1px 5px;border-radius:3px;font-size:0.85em}
</style>
<div class="container">
<h1>{{ title|e }}</h1>
<div class="ts">Generated: {{ generated|e }}</div>

{% if site.base_url or site.profile %}
<div class="site-info">
  {% if site.base_url %}<strong>Base URL:</strong> <a href="{{ site.base_url|e }}" target="_blank" rel="noopener">{{ site.base_url|e }}</a><br>{% endif %}
  {% if site.sitemap %}<strong>Sitemap:</strong> <a href="{{ site.sitemap|e }}" target="_blank" rel="noopener">{{ site.sitemap|e }}</a><br>{% endif %}
  {% if site.profile %}<strong>Profile:</strong> {{ site.profile|e }}<br>{% endif %}
  {% if site.paths %}<strong>Key Paths:</strong> {{ site.paths|e }}{% endif %}
</div>
{% endif %}

{% if results.lint or results.axe or results.alfa or results.alfa_full or results.pa11y %}
<div class="section">
<h2>Test Results</h2>
<div class="summary-grid">
  {% for tool, result in results %}
    {% if result %}
      <div class="result-badge {{ result|lower == 'passed' ? 'passed' : 'failed' }}">
        <span class="label">{{ tool == 'alfa_full' ? 'Alfa Full' : tool|capitalize }}</span>
        {{ result|lower == 'passed' ? 'PASSED' : 'FAILED' }}
      </div>
    {% else %}
      <div class="result-badge skipped">
        <span class="label">{{ tool == 'alfa_full' ? 'Alfa Full' : tool|capitalize }}</span>
        Not run
      </div>
    {% endif %}
  {% endfor %}
</div>
</div>
{% endif %}

<div class="section">
<h2>Reports</h2>
<ul>
  {% if has_lint %}
    <li>
      <a href="{{ paths.lint|e }}" target="_blank" rel="noopener"><span class="tag tag-lint">Lint</span> Code Linting Report</a>
      <div class="report-desc">Static analysis of custom modules &amp; themes — PHP, YAML, Twig, JS, CSS/SCSS syntax and coding standards</div>
    </li>
  {% else %}
    <li class="unavailable"><span class="tag tag-lint">Lint</span> Report not available</li>
  {% endif %}

  {% if has_axe %}
    <li>
      <a href="{{ paths.axe|e }}" target="_blank" rel="noopener"><span class="tag tag-axe">Axe</span> Accessibility Report</a>
      <div class="report-desc">Quick check of key pages using axe-core — tests 5 representative paths for WCAG violations</div>
    </li>
  {% else %}
    <li class="unavailable"><span class="tag tag-axe">Axe</span> Report not available</li>
  {% endif %}

  {% if has_alfa %}
    <li>
      <a href="{{ paths.alfa|e }}" target="_blank" rel="noopener"><span class="tag tag-alfa">Alfa</span> Accessibility Report <span class="scope">(key pages)</span></a>
      <div class="report-desc">Siteimprove Alfa audit of 5 key paths — pass/fail results with console details</div>
    </li>
  {% endif %}

  {% if has_alfa_full %}
    <li>
      <a href="{{ paths.alfa_full|e }}" target="_blank" rel="noopener"><span class="tag tag-alfa">Alfa</span> Full Site Accessibility Report <span class="scope">(all pages)</span></a>
      <div class="report-desc">Comprehensive Siteimprove audit of every sitemap page — severity filtering, WCAG grouping, fix recommendations, and page-by-page drill-down</div>
    </li>
  {% else %}
    <li class="unavailable"><span class="tag tag-alfa">Alfa</span> Full site report not available — run <code>drush utest:a11y:alfa-full</code></li>
  {% endif %}

  {% if has_pa11y %}
    <li>
      <a href="{{ paths.pa11y|e }}" target="_blank" rel="noopener"><span class="tag tag-pa11y">Pa11y</span> Accessibility Report <span class="scope">(all pages)</span></a>
      <div class="report-desc">HTML_CodeSniffer audit of every sitemap page — issues grouped by WCAG rule with affected pages, filterable by severity</div>
    </li>
  {% else %}
    <li class="unavailable"><span class="tag tag-pa11y">Pa11y</span> Report not available</li>
  {% endif %}
</ul>
</div>

<div class="section">
<h2>Raw Data</h2>
<ul>
  {% for file in all_files %}
    {% if file.path matches '/\\.(json|log)$/i' %}
      {% set ext = file.path|split('.')|last|upper %}
      <li><a href="{{ file.path|e }}" target="_blank" rel="noopener"><span class="tag tag-file">{{ ext }}</span> {{ file.path|e }}</a> <span class="status">{{ (file.size / 1024)|round(1) }} KB</span></li>
    {% endif %}
  {% endfor %}
  {% if all_files is empty %}
    <li class="empty">No data files found</li>
  {% endif %}
</ul>
</div>
</div>
TWIG;
    return $twig->createTemplate($tpl)->render($v);
  }

  /**
   * Get all report files in the given directory.
   *
   * @param \Drupal\Core\File\FileSystemInterface $fs
   *   The file system service.
   * @param string $uri
   *   The directory URI to scan.
   *
   * @return array
   *   Array of file information with keys: name, path, size.
   */
  protected function getAllReportFiles(FileSystemInterface $fs, string $uri): array {
    $real = $fs->realpath($uri);
    if (!$real || !is_dir($real)) {
      return [];
    }

    $files = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($real, \RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
      if ($file->isFile()) {
        // Get relative path from the run directory.
        $relativePath = substr($file->getPathname(), strlen($real) + 1);
        $files[] = [
          'name' => basename($file->getPathname()),
          'path' => $relativePath,
          'size' => $file->getSize(),
        ];
      }
    }

    // Sort files by path for consistent ordering.
    usort($files, function ($a, $b) {
      return strcmp($a['path'], $b['path']);
    });

    return $files;
  }

  /**
   * Resolve profile key to human-readable name.
   */
  protected function resolveProfileName(?string $profile): ?string {
    if (!$profile) {
      return NULL;
    }
    $names = [
      'comprehensive' => 'Comprehensive Mode (All WCAG Levels + Best Practices)',
      'standard'      => 'Standard Mode (WCAG Level A + AA)',
      'strict'        => 'Strict Mode (WCAG Level A only)',
    ];
    return $names[$profile] ?? $profile;
  }

  /**
   *
   */
  protected function renderLatest(Environment $twig, array $v): string {
    $tpl = <<<'TWIG'
<!doctype html><meta charset="utf-8"><title>{{ title|e }} — Latest</title>
<meta http-equiv="refresh" content="0; url={{ href|e }}"><p>Redirecting to {{ run_id|e }}… <a href="{{ href|e }}">Continue</a></p>
TWIG;
    return $twig->createTemplate($tpl)->render($v);
  }

  // ─────────────────────────────────────────────────────────────────────────
  // utest:report-render — successor to utest:report-index. Reads each test's
  // test-suite-findings.json (the stable contract in tests/reports/_shell/findings.schema.json)
  // and renders the Accessibility, Security, and Code Quality Report via
  // tests/reports/_shell/index.template.html.
  // The output is a single self-contained HTML file the user can open over
  // Valet, SFTP, or a multidev URL — no asset path concerns.
  // ─────────────────────────────────────────────────────────────────────────

  /**
   * Render the unified test-suite report from per-test test-suite-findings.json files.
   *
   * @command utest:report-render
   * @aliases utrender
   * @bootstrap full
   *
   * @option dest-uri Where the rendered index.html lands (defaults to public://test-reports — sits alongside the per-test lane dirs).
   * @option src-uri  Where the per-test test-suite-findings.json files live (defaults to public://test-reports).
   * @option base-url Optional public URL to log when done.
   */
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
    // test-suite-findings.json are silently skipped — they'll show up once their
    // emitter lands.
    $tests = ['alfa-full', 'alfa', 'axe-watcher-full', 'axe-watcher', 'axe-full', 'axe', 'pa11y', 'reflow', 'meta-viewport', 'lint', 'phpunit'];

    // Only these tests get a link in the shell's "Detailed reports per
    // tool" section. The key-pages variants (alfa, axe) are intentionally
    // excluded — they're dev-feedback subsets of the full-site runs and
    // their per-tool surfaces duplicate findings already aggregated in the
    // unified view. Findings still aggregate from every test in $tests
    // above; only the per-tool HTML link is suppressed for key-pages.
    $linkedTests = ['alfa-full', 'axe-full', 'pa11y', 'reflow', 'meta-viewport', 'lint'];

    // Tests whose test-suite-findings.json is the canonical full-coverage artifact for
    // downstream automation (CI gates, accessibility agents). The key-pages
    // a11y variants are intentionally excluded — they're a fast-feedback
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

      // Surface test-suite-findings.json as a machine-readable artifact in the unified
      // shell so downstream consumers can locate it without scraping HTML.
      // Only the canonical full-coverage tests are listed.
      if (in_array($name, $machineReadableTests, TRUE)) {
        $sizeBytes = filesize($findingsReal);
        $machineReadable[] = [
          'label'   => $name . '/test-suite-findings.json',
          'href'    => $relPrefix . $name . '/test-suite-findings.json',
          'size_kb' => $sizeBytes !== FALSE ? round($sizeBytes / 1024, 1) : NULL,
        ];
      }

      // Locate the per-test HTML report next to its test-suite-findings.json so the
      // shell's "Detailed reports per tool" section can link to it. Path is
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
    // stay readable; the placeholder lives inside <script type="application/json">
    // so embedded HTML special chars (`</script>`) are the only escape concern.
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === FALSE) {
      throw new \RuntimeException('Failed to encode payload: ' . json_last_error_msg());
    }
    // Defuse any literal </script> in tool messages so the inline JSON block
    // can't break out of its container.
    $json = str_replace('</', '<\\/', $json);

    $html = str_replace('{{REPORT_DATA_JSON}}', $json, $template);

    $outputUri = rtrim($destBase, '/') . '/index.html';
    $repo->writeData($html, $outputUri, FileSystemInterface::EXISTS_REPLACE);

    $count = count($aggregatedTests);
    $this->logger()->success(sprintf(
      'Rendered Test Report (%d test%s): %s',
      $count, $count === 1 ? '' : 's',
      $this->extUrl($swm, $outputUri, $options['base-url'])
    ));
  }

  /**
   * Find the canonical per-test HTML report inside its directory so the
   * unified shell can link to it. Returns the basename relative to the
   * test's directory, or null if no recognized report exists.
   */
  protected function findRawReportHtml(FileSystemInterface $fs, string $dirUri): ?string {
    $real = $fs->realpath($dirUri);
    if (!$real || !is_dir($real)) {
      return NULL;
    }
    $candidates = [
      'alfa-full-site-report.html',
      'alfa-report.html',
      'axe-report.html',
      'axe-watcher-report.html',
      'axe-watcher-full-report.html',
      'pa11y-report.html',
      'lint-report.html',
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
   * Human-readable label for a test identifier, used in the shell's
   * "Detailed reports per tool" section.
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

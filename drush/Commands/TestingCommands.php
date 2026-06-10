<?php

namespace Drush\Commands;

use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Input\ArrayInput;
use Drush\Drush;
use Symfony\Component\Process\Process;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;

/**
 * Run JS-based accessibility & E2E tests through Drush.
 * Requires Node tooling installed in ./tests (npm i).
 */
class TestingCommands extends DrushCommands {

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The file URL generator service.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * The stream wrapper manager service.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected $streamWrapperManager;

  /**
   * Constructs a TestingCommands object.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator service.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrapper_manager
   *   The stream wrapper manager service.
   */
  public function __construct(
    ?FileSystemInterface $file_system = NULL,
    ?FileUrlGeneratorInterface $file_url_generator = NULL,
    ?StreamWrapperManagerInterface $stream_wrapper_manager = NULL,
  ) {
    $this->fileSystem = $file_system;
    $this->fileUrlGenerator = $file_url_generator;
    $this->streamWrapperManager = $stream_wrapper_manager;
  }

  /**
   * Install JS deps for the tests workspace.
   *
   * @command utest:js-install
   * @aliases utjsi
   */
  public function jsInstall() {
    $cmd = ['bash', '-lc', 'cd tests && npm i'];
    $this->runProcess($cmd, 1800, 'Installing JS dependencies (tests/)');
  }

  /**
   * Check and install specific npm package if missing.
   */
  protected function ensurePackage($packageName) {
    // Check if package is installed.
    $checkCmd = ['bash', '-lc', "cd tests && npm list $packageName --depth=0"];
    $process = new Process($checkCmd);
    $process->run();

    if (!$process->isSuccessful()) {
      $this->io()->text("Installing missing package: $packageName");
      $installCmd = ['bash', '-lc', "cd tests && npm install --save-dev $packageName"];
      $this->runProcess($installCmd, 600, "Installing $packageName");
    }
  }

  /**
   * Install Playwright browsers.
   *
   * @command utest:browsers
   * @aliases utbr
   */
  public function browsers() {
    $cmd = ['bash', '-lc', 'cd tests && npx playwright install --with-deps'];
    $this->runProcess($cmd, 1800, 'Installing Playwright browsers');
  }

  /**
   * Pre-flight check for the test suite.
   *
   * Validates a site's local environment before a test run. Each check
   * reports PASS / WARN / FAIL with one-line remediation when relevant.
   *
   * @command utest:check-config
   * @aliases utchk
   * @option base-url The base URL to validate reachability against.
   *   Defaults to the BASE_URL env var if set.
   * @usage drush utest:check-config
   *   Run all checks against the BASE_URL env var (or skip URL checks
   *   if BASE_URL is not set).
   * @usage drush utest:check-config --base-url=https://my-site.ddev.site
   *   Run all checks against an explicit URL.
   */
  public function checkConfig(array $options = ['base-url' => NULL]) {
    $baseUrl = $options['base-url'] ?? getenv('BASE_URL') ?: NULL;
    $repoRoot = $this->getRepoRoot();
    $results = [];

    // 1. Node version >= 20.
    $results[] = $this->checkNodeVersion();

    // 2. Test suite npm deps installed.
    $results[] = $this->checkNpmDeps($repoRoot);

    // 3. Playwright browsers installed.
    $results[] = $this->checkPlaywrightBrowsers($repoRoot);

    // 4. BASE_URL reachability.
    $results[] = $this->checkBaseUrl($baseUrl);

    // 5. Sitemap.xml reachability (warn-only).
    $results[] = $this->checkSitemap($baseUrl);

    // 6. Custom-paths.json globs resolve.
    $results[] = $this->checkCustomPathsConfig($repoRoot);

    $this->renderCheckResults($results);

    $hasFail = FALSE;
    foreach ($results as $result) {
      if ($result['status'] === 'FAIL') {
        $hasFail = TRUE;
        break;
      }
    }
    return $hasFail ? 1 : 0;
  }

  /**
   * Resolve repo root regardless of where drush is invoked from.
   */
  protected function getRepoRoot(): string {
    // Drush runs from somewhere inside the project; walk up to find
    // composer.json + a tests/ sibling. Cross-platform-safe: dirname()
    // returns the same path when there's nowhere left to go (e.g. "/"
    // on Unix, "C:\" on Windows), which is the loop terminator.
    $cwd = getcwd();
    while ($cwd) {
      if (file_exists($cwd . '/composer.json') && is_dir($cwd . '/tests')) {
        return $cwd;
      }
      $parent = dirname($cwd);
      if ($parent === $cwd) {
        break;
      }
      $cwd = $parent;
    }
    // Fallback: assume drush is invoked from repo root.
    return getcwd();
  }

  /**
   * Check Node version >= 20.
   */
  protected function checkNodeVersion(): array {
    $process = new Process(['node', '--version']);
    $process->run();
    if (!$process->isSuccessful()) {
      return [
        'name' => 'Node.js installed',
        'status' => 'FAIL',
        'message' => 'Node not found on PATH. Install Node 20+ (https://nodejs.org).',
      ];
    }
    $version = trim($process->getOutput());
    if (preg_match('/v(\d+)\./', $version, $m) && (int) $m[1] >= 20) {
      return [
        'name' => "Node.js >= 20 (found {$version})",
        'status' => 'PASS',
        'message' => '',
      ];
    }
    return [
      'name' => "Node.js >= 20 (found {$version})",
      'status' => 'FAIL',
      'message' => 'Node 20 or newer required. Update via your version manager (nvm install 20).',
    ];
  }

  /**
   * Check that npm install has run in tests/.
   */
  protected function checkNpmDeps(string $repoRoot): array {
    $nodeModules = $repoRoot . '/tests/node_modules';
    if (!is_dir($nodeModules)) {
      return [
        'name' => 'tests/node_modules present',
        'status' => 'FAIL',
        'message' => 'Run: drush utest:js-install',
      ];
    }
    return [
      'name' => 'tests/node_modules present',
      'status' => 'PASS',
      'message' => '',
    ];
  }

  /**
   * Check that Playwright browsers are installed.
   */
  protected function checkPlaywrightBrowsers(string $repoRoot): array {
    // Playwright caches browsers in ~/.cache/ms-playwright (Linux/macOS) or
    // ~/Library/Caches/ms-playwright (some macOS configs).
    $home = getenv('HOME') ?: '';
    $candidates = [
      $home . '/.cache/ms-playwright',
      $home . '/Library/Caches/ms-playwright',
    ];
    foreach ($candidates as $path) {
      if (is_dir($path)) {
        // Has at least one chromium-* sub-directory?
        $entries = glob($path . '/chromium-*');
        if (!empty($entries)) {
          return [
            'name' => 'Playwright browsers installed',
            'status' => 'PASS',
            'message' => '',
          ];
        }
      }
    }
    return [
      'name' => 'Playwright browsers installed',
      'status' => 'WARN',
      'message' => 'Could not detect Playwright browsers. If a11y tests fail, run: drush utest:browsers',
    ];
  }

  /**
   * Check BASE_URL reachability.
   */
  protected function checkBaseUrl(?string $baseUrl): array {
    if (!$baseUrl) {
      return [
        'name' => 'BASE_URL reachable',
        'status' => 'WARN',
        'message' => "BASE_URL not set. Copy and paste, replacing the URL with your local site:\n"
        . "         export BASE_URL=https://my-site.test\n"
        . "         drush utest:check-config\n"
        . "       Or pass once: drush utest:check-config --base-url=https://my-site.test",
      ];
    }
    $ch = curl_init($baseUrl);
    curl_setopt_array($ch, [
      CURLOPT_NOBODY => TRUE,
      CURLOPT_FOLLOWLOCATION => TRUE,
      CURLOPT_TIMEOUT => 10,
      CURLOPT_SSL_VERIFYPEER => FALSE,
      CURLOPT_RETURNTRANSFER => TRUE,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($code >= 200 && $code < 400) {
      return [
        'name' => "BASE_URL reachable ({$baseUrl})",
        'status' => 'PASS',
        'message' => '',
      ];
    }
    if ($err) {
      return [
        'name' => "BASE_URL reachable ({$baseUrl})",
        'status' => 'FAIL',
        'message' => "curl error: {$err}. Verify the URL is correct and your local site is running.",
      ];
    }
    return [
      'name' => "BASE_URL reachable ({$baseUrl})",
      'status' => 'FAIL',
      'message' => "HTTP {$code}. Check that your local site is serving content at this URL.",
    ];
  }

  /**
   * Check that sitemap.xml is reachable (warn-only).
   */
  protected function checkSitemap(?string $baseUrl): array {
    if (!$baseUrl) {
      return [
        'name' => 'sitemap.xml reachable',
        'status' => 'WARN',
        'message' => 'BASE_URL not set; cannot verify sitemap.',
      ];
    }
    $sitemapUrl = rtrim($baseUrl, '/') . '/sitemap.xml';
    $ch = curl_init($sitemapUrl);
    curl_setopt_array($ch, [
      CURLOPT_NOBODY => TRUE,
      CURLOPT_FOLLOWLOCATION => TRUE,
      CURLOPT_TIMEOUT => 10,
      CURLOPT_SSL_VERIFYPEER => FALSE,
      CURLOPT_RETURNTRANSFER => TRUE,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code >= 200 && $code < 400) {
      return [
        'name' => 'sitemap.xml reachable',
        'status' => 'PASS',
        'message' => '',
      ];
    }
    return [
      'name' => 'sitemap.xml reachable',
      'status' => 'WARN',
      'message' => 'Sitemap not available — full-site a11y crawls will need explicit --paths. Install simple_sitemap or pass paths manually.',
    ];
  }

  /**
   * Check that custom-paths.json exists and its globs resolve.
   */
  protected function checkCustomPathsConfig(string $repoRoot): array {
    $configPath = $repoRoot . '/tests/code-quality/config/custom-paths.json';
    if (!file_exists($configPath)) {
      return [
        'name' => 'tests/code-quality/config/custom-paths.json exists',
        'status' => 'WARN',
        'message' => 'Custom-paths config not found. Suite will rely on composer.json installer-paths + *.info.yml autodiscovery only.',
      ];
    }
    $raw = file_get_contents($configPath);
    $config = json_decode($raw, TRUE);
    if (!$config) {
      return [
        'name' => 'tests/code-quality/config/custom-paths.json valid',
        'status' => 'FAIL',
        'message' => 'JSON parse failed. Validate against the committed schema.',
      ];
    }
    // Check at least one glob from each non-empty extras list resolves.
    $unresolved = [];
    foreach (($config['extras'] ?? []) as $key => $globs) {
      if (!is_array($globs)) {
        continue;
      }
      foreach ($globs as $glob) {
        $matches = glob($repoRoot . '/' . $glob, GLOB_BRACE);
        if (empty($matches) && !str_contains($glob, '*')) {
          // Literal path that doesn't exist.
          $unresolved[] = "{$key}: {$glob}";
        }
      }
    }
    if (!empty($unresolved)) {
      return [
        'name' => 'tests/code-quality/config/custom-paths.json globs resolve',
        'status' => 'WARN',
        'message' => 'Some paths match zero files: ' . implode(', ', array_slice($unresolved, 0, 3)) . (count($unresolved) > 3 ? '...' : ''),
      ];
    }
    return [
      'name' => 'tests/code-quality/config/custom-paths.json globs resolve',
      'status' => 'PASS',
      'message' => '',
    ];
  }

  /**
   * Render a list of check results to the console.
   */
  protected function renderCheckResults(array $results): void {
    $this->io()->title('Test suite pre-flight check');
    foreach ($results as $result) {
      $icon = match ($result['status']) {
        'PASS' => '',
        'WARN' => '⚠',
        'FAIL' => '',
        default => '?',
      };
      $line = sprintf('%s [%s] %s', $icon, $result['status'], $result['name']);
      $this->io()->writeln($line);
      if (!empty($result['message'])) {
        $this->io()->writeln('       ' . $result['message']);
      }
    }
    $passes = array_filter($results, fn($r) => $r['status'] === 'PASS');
    $warns = array_filter($results, fn($r) => $r['status'] === 'WARN');
    $fails = array_filter($results, fn($r) => $r['status'] === 'FAIL');
    $this->io()->newLine();
    $this->io()->writeln(sprintf(
      'Summary: %d pass, %d warn, %d fail',
      count($passes),
      count($warns),
      count($fails)
    ));
  }

  /**
   * Resolve the path list for a Playwright-based a11y run.
   *
   * Tiered fallback:
   *   1. Explicit $explicitPaths (caller supplied --paths) → use as-is.
   *   2. BASE_URL/sitemap.xml reachable → fetch up to $maxPaths URLs.
   *   3. Fall back to "/" only and emit a warning so the contributor
   *      knows to supply --paths explicitly.
   *
   * Note: this PHP implementation handles a single <urlset> sitemap.
   * Sitemap-index files (<sitemapindex>) are not followed; in that
   * case the caller should pass --paths explicitly.
   *
   * @param string|null $explicitPaths
   *   Comma-separated path list passed via --paths, or null.
   * @param string $baseUrl
   *   The base URL the run targets.
   * @param int $maxPaths
   *   Cap on number of paths returned from sitemap (default 10).
   *
   * @return string
   *   Comma-separated path list ready to use as PLAYWRIGHT_PATHS.
   */
  protected function resolveA11yPaths(?string $explicitPaths, string $baseUrl, int $maxPaths = 10): string {
    if ($explicitPaths !== NULL && $explicitPaths !== '') {
      return $explicitPaths;
    }

    $sitemapPaths = $this->fetchSitemapPaths($baseUrl, $maxPaths);
    if (!empty($sitemapPaths)) {
      $this->io()->text(sprintf('Using %d paths from %s/sitemap.xml', count($sitemapPaths), rtrim($baseUrl, '/')));
      return implode(',', $sitemapPaths);
    }

    // Fallback: universal Drupal-core routes only.
    //   "/"           — every Drupal site serves something at the root.
    //   "/user/login" — the user module is mandatory; this route always
    //                   exists and exercises a form (a common a11y
    //                   failure surface) so it adds real coverage.
    // Pass --paths or set PLAYWRIGHT_PATHS for richer coverage; install
    // simple_sitemap so the orchestrator can auto-discover real pages.
    $this->io()->text('Sitemap not available — falling back to "/, /user/login" only. Pass --paths or install simple_sitemap for richer coverage.');
    return '/,/user/login';
  }

  /**
   * Fetch and parse paths from a site's sitemap.xml.
   *
   * Returns an empty array on any failure (network error, parse error,
   * non-200 response, sitemap index without urlset).
   */
  protected function fetchSitemapPaths(string $baseUrl, int $maxPaths): array {
    $sitemapUrl = rtrim($baseUrl, '/') . '/sitemap.xml';
    $ch = curl_init($sitemapUrl);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_FOLLOWLOCATION => TRUE,
      CURLOPT_TIMEOUT => 10,
      CURLOPT_SSL_VERIFYPEER => FALSE,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 400 || empty($body)) {
      return [];
    }

    // Suppress libxml warnings; we'll detect failure via the return value.
    $previousErrors = libxml_use_internal_errors(TRUE);
    $xml = simplexml_load_string($body);
    libxml_use_internal_errors($previousErrors);

    if ($xml === FALSE) {
      return [];
    }

    // Only handle <urlset>; sitemap indexes (<sitemapindex>) are not followed.
    if ($xml->getName() !== 'urlset') {
      return [];
    }

    $baseHost = parse_url($baseUrl, PHP_URL_HOST);
    $paths = [];
    foreach ($xml->url as $url) {
      $loc = (string) $url->loc;
      if (!$loc) {
        continue;
      }
      $parsed = parse_url($loc);
      // Skip URLs from a different host than the base.
      if (!empty($parsed['host']) && $parsed['host'] !== $baseHost) {
        continue;
      }
      $path = $parsed['path'] ?? '/';
      if (!in_array($path, $paths, TRUE)) {
        $paths[] = $path;
      }
      if (count($paths) >= $maxPaths) {
        break;
      }
    }
    return $paths;
  }

  /**
   * Run Playwright + axe on key paths.
   *
   * @command utest:a11y:axe
   * @aliases utaxe
   */
  public function axe(
    array $options = [
      'base-url' => NULL,
      'paths'    => NULL,
      'a11y-profile' => 'comprehensive',
      'a11y-custom-tags' => NULL,
      'a11y-severity-levels' => NULL,
    ],
  ) {
    // Ensure base-url exists.
    $baseUrl = $options['base-url'] ?: getenv('BASE_URL') ?: 'http://127.0.0.1:8888';
    $reportPaths = $this->getReportPaths('axe', $baseUrl);

    $env = [
      'BASE_URL' => (string) $baseUrl,
      'PLAYWRIGHT_PATHS' => $this->resolveA11yPaths($options['paths'] ?? NULL, (string) $baseUrl),
      'A11Y_PROFILE' => isset($options['a11y-profile']) ? (string) $options['a11y-profile'] : 'comprehensive',
      'A11Y_CUSTOM_TAGS' => isset($options['a11y-custom-tags']) && $options['a11y-custom-tags'] ? (string) $options['a11y-custom-tags'] : NULL,
      'A11Y_SEVERITY_LEVELS' => isset($options['a11y-severity-levels']) && $options['a11y-severity-levels'] ? (string) $options['a11y-severity-levels'] : NULL,
      'PLAYWRIGHT_REPORT_DIR' => $reportPaths['realPath'],
    ];
    $cmd = ['bash', '-lc', 'cd tests && npx playwright test accessibility/axe/a11y.spec.ts --config=playwright.config.ts'];

    try {
      $this->runProcess($cmd, 3600, 'Playwright + axe', $env);

      // Generate static HTML report after tests complete successfully.
      $this->generateStaticHtmlReport($reportPaths['realPath'], 'axe');
      $this->displayReportLocations('Playwright + axe testing completed!', $reportPaths, [], $baseUrl, 'axe');
    }
    catch (\RuntimeException $e) {
      // Generate HTML report even when tests fail (violations detected)
      $this->generateStaticHtmlReport($reportPaths['realPath'], 'axe');

      // Show report locations even when tests fail (violations detected)
      $this->displayReportLocations('Playwright + axe testing completed with violations!', $reportPaths, [
        'Console output above shows detailed violation information',
      ], $baseUrl, 'axe');

      // Provide more helpful error message for axe failures.
      $this->io()->text('Axe accessibility test failed. This could be due to:');
      $this->io()->text('  - Site accessibility violations detected (see detailed output above)');
      $this->io()->text('  - Network connectivity issues');
      $this->io()->text('  - Missing dependencies (check npm dependencies)');
      $this->io()->text('  - Configuration issues with accessibility profiles');
      $this->io()->text('Try running: cd tests && npm install to ensure all dependencies are installed');
      $this->io()->text('Detailed violation information should be visible in the test output above');
      // Re-throw to maintain original behavior.
      throw $e;
    }
  }

  /**
   * Run Playwright + axe with Developer Hub integration.
   *
   * @command utest:a11y:axe-watcher
   * @aliases utaxew
   */
  public function axeWatcher(
    array $options = [
      'base-url' => NULL,
      'paths'    => NULL,
      'axe-api-key' => NULL,
      'a11y-profile' => 'comprehensive',
      'a11y-custom-tags' => NULL,
      'a11y-severity-levels' => NULL,
    ],
  ) {
    // Ensure base-url exists.
    $baseUrl = $options['base-url'] ?: getenv('BASE_URL') ?: 'http://127.0.0.1:8888';

    // Check for API key.
    $apiKey = $options['axe-api-key']
      ?? $_ENV['AXE_API_KEY']
      ?? getenv('AXE_API_KEY')
      ?? NULL;

    if (!$apiKey) {
      $this->io()->text('No axe API key found. Set AXE_API_KEY environment variable or use --axe-api-key option.');
      $this->io()->text('Falling back to basic axe testing...');
      return $this->axe(['base-url' => $baseUrl, 'paths' => $options['paths'] ?? NULL]);
    }

    // Ensure required packages are installed.
    $this->ensurePackage('@axe-core/watcher');
    $this->ensurePackage('@types/node');

    $reportPaths = $this->getReportPaths('axe-watcher', $baseUrl);

    $env = [
      'BASE_URL' => (string) $baseUrl,
      'PLAYWRIGHT_PATHS' => $this->resolveA11yPaths($options['paths'] ?? NULL, (string) $baseUrl),
      'AXE_API_KEY' => $apiKey,
      'A11Y_PROFILE' => isset($options['a11y-profile']) ? (string) $options['a11y-profile'] : 'comprehensive',
      'A11Y_CUSTOM_TAGS' => isset($options['a11y-custom-tags']) && $options['a11y-custom-tags'] ? (string) $options['a11y-custom-tags'] : NULL,
      'A11Y_SEVERITY_LEVELS' => isset($options['a11y-severity-levels']) && $options['a11y-severity-levels'] ? (string) $options['a11y-severity-levels'] : NULL,
      'PLAYWRIGHT_REPORT_DIR' => $reportPaths['realPath'],
    ];

    $cmd = ['bash', '-lc', 'cd tests && npx playwright test accessibility/axe/a11y-watcher.spec.ts --config=playwright.config.ts'];

    try {
      $this->runProcess($cmd, 3600, 'Playwright + axe Developer Hub', $env);

      // Generate static HTML report after tests complete successfully.
      $this->generateStaticHtmlReport($reportPaths['realPath'], 'axe-watcher');
      $this->displayReportLocations('Playwright + axe Developer Hub testing completed!', $reportPaths, [
        'axe Developer Hub: Check your dashboard for detailed results',
      ], $baseUrl, 'axe-watcher');
    }
    catch (\RuntimeException $e) {
      // Generate HTML report even when tests fail (violations detected)
      $this->generateStaticHtmlReport($reportPaths['realPath'], 'axe-watcher');

      // Show report locations even when tests fail (violations detected)
      $this->displayReportLocations('Playwright + axe Developer Hub testing completed with violations!', $reportPaths, [
        'Console output above shows detailed violation information',
        'axe Developer Hub: Check your dashboard for detailed results',
      ], $baseUrl, 'axe-watcher');

      // Provide more helpful error message for axe failures.
      $this->io()->text('Axe Developer Hub accessibility test failed. This could be due to:');
      $this->io()->text('  - Site accessibility violations detected (see detailed output above)');
      $this->io()->text('  - Network connectivity issues');
      $this->io()->text('  - Missing axe Developer Hub API key or invalid key');
      $this->io()->text('  - Missing dependencies (check npm dependencies)');
      $this->io()->text('  - Configuration issues with accessibility profiles');
      $this->io()->text('Try running: cd tests && npm install to ensure all dependencies are installed');
      $this->io()->text('Detailed violation information should be visible in the test output above');
      // Re-throw to maintain original behavior.
      throw $e;
    }
  }

  /**
   * Run Playwright + axe Developer Hub full-site audit via sitemap.
   *
   * @command utest:a11y:axe-watcher-full
   * @aliases utaxewf
   */
  public function axeWatcherFull(
    array $options = [
      'base-url' => NULL,
      'sitemap-url' => NULL,
      'max-pages' => 50,
      'axe-api-key' => NULL,
      'a11y-profile' => 'comprehensive',
      'a11y-custom-tags' => NULL,
      'a11y-severity-levels' => NULL,
    ],
  ) {
    // Ensure base-url exists.
    $baseUrl = $options['base-url'] ?: getenv('BASE_URL') ?: 'http://127.0.0.1:8888';

    // Check for API key.
    $apiKey = $options['axe-api-key']
      ?? $_ENV['AXE_API_KEY']
      ?? getenv('AXE_API_KEY')
      ?? NULL;

    if (!$apiKey) {
      $this->io()->text('No axe API key found. Set AXE_API_KEY environment variable or use --axe-api-key option.');
      $this->io()->text('This command requires axe Developer Hub integration.');
      return;
    }

    // Ensure required packages are installed.
    $this->ensurePackage('@axe-core/watcher');
    $this->ensurePackage('@axe-core/playwright');
    $this->ensurePackage('@types/node');

    // Resolve sitemap URL.
    $sitemapUrl = $options['sitemap-url']
      ?? rtrim((string) $baseUrl, '/') . '/sitemap.xml';

    $reportPaths = $this->getReportPaths('axe-watcher-full', $baseUrl);

    // Resolve --max-pages: accept "all" or 0 as "no cap" sentinels.
    $maxPagesRaw = $options['max-pages'] ?? 50;
    $maxPagesEnv = $this->resolveMaxPagesEnv($maxPagesRaw);
    $this->announceMaxPages('axe Developer Hub Full Site Audit', $maxPagesRaw, 4.0);

    $env = [
      'BASE_URL' => (string) $baseUrl,
      'SITEMAP_URL' => $sitemapUrl,
      'AXE_MAX_PAGES' => $maxPagesEnv,
      'AXE_API_KEY' => $apiKey,
      'A11Y_PROFILE' => isset($options['a11y-profile']) ? (string) $options['a11y-profile'] : 'comprehensive',
      'A11Y_CUSTOM_TAGS' => isset($options['a11y-custom-tags']) && $options['a11y-custom-tags'] ? (string) $options['a11y-custom-tags'] : NULL,
    // Full-site always uses all severity levels.
      'A11Y_SEVERITY_LEVELS' => 'critical,serious,moderate,minor',
      'PLAYWRIGHT_REPORT_DIR' => $reportPaths['realPath'],
    ];

    $cmd = ['bash', '-lc', 'cd tests && npx playwright test accessibility/axe/axe-watcher-full-site.spec.ts --config=playwright.config.ts'];

    try {
      $this->runProcess($cmd, 7200, 'Playwright + axe Developer Hub Full Site', $env);

      // Generate static HTML report after tests complete successfully.
      $this->generateStaticHtmlReport($reportPaths['realPath'], 'axe-watcher-full');
      $this->displayReportLocations('axe Developer Hub full-site testing completed!', $reportPaths, [
        'axe Developer Hub: Check your dashboard for comprehensive results',
      ], $baseUrl, 'axe-watcher-full');
    }
    catch (\RuntimeException $e) {
      // Generate HTML report even when tests fail (violations detected)
      $this->generateStaticHtmlReport($reportPaths['realPath'], 'axe-watcher-full');

      // Show report locations even when tests fail (violations detected)
      $this->displayReportLocations('axe Developer Hub full-site testing completed with violations!', $reportPaths, [
        'Console output above shows detailed violation information',
        'axe Developer Hub: Check your dashboard for comprehensive results',
      ], $baseUrl, 'axe-watcher-full');

      // Provide more helpful error message for axe failures.
      $this->io()->text('Axe Developer Hub full-site accessibility test failed. This could be due to:');
      $this->io()->text('  - Site accessibility violations detected (see detailed output above)');
      $this->io()->text('  - Network connectivity issues');
      $this->io()->text('  - Missing axe Developer Hub API key or invalid key');
      $this->io()->text('  - Missing dependencies (check npm dependencies)');
      $this->io()->text('  - Configuration issues with accessibility profiles');
      $this->io()->text('Try running: cd tests && npm install to ensure all dependencies are installed');
      $this->io()->text('Detailed violation information should be visible in the test output above');
      // Re-throw to maintain original behavior.
      throw $e;
    }
  }

  /**
   * Run Siteimprove Alfa checks.
   *
   * @command utest:a11y:alfa
   * @aliases utalfa
   */
  public function alfa(
    array $options = [
      'base-url' => NULL,
      'paths'    => NULL,
      'a11y-profile' => 'comprehensive',
      'a11y-custom-tags' => NULL,
      'a11y-severity-levels' => NULL,
    ],
  ) {
    // Ensure base-url exists.
    $baseUrl = $options['base-url'] ?: getenv('BASE_URL') ?: 'http://127.0.0.1:8888';

    // Ensure required packages are installed.
    $this->ensurePackage('@siteimprove/alfa-playwright');
    $this->ensurePackage('@siteimprove/alfa-test-utils');

    $reportPaths = $this->getReportPaths('alfa', $baseUrl);

    $env = [
      'BASE_URL' => (string) $baseUrl,
      'PLAYWRIGHT_PATHS' => $this->resolveA11yPaths($options['paths'] ?? NULL, (string) $baseUrl),
      'A11Y_PROFILE' => isset($options['a11y-profile']) ? (string) $options['a11y-profile'] : 'comprehensive',
      'A11Y_CUSTOM_TAGS' => isset($options['a11y-custom-tags']) && $options['a11y-custom-tags'] ? (string) $options['a11y-custom-tags'] : NULL,
      'A11Y_SEVERITY_LEVELS' => isset($options['a11y-severity-levels']) && $options['a11y-severity-levels'] ? (string) $options['a11y-severity-levels'] : NULL,
      // Disable network-dependent features that might cause failures.
      'ALFA_DISABLE_NETWORK_FEATURES' => 'true',
      'PLAYWRIGHT_REPORT_DIR' => $reportPaths['realPath'],
    ];

    // Use default Playwright config which includes both HTML and line reporters.
    $cmd = ['bash', '-lc', 'cd tests && npx playwright test accessibility/alfa/alfa-accessibility.spec.js --config=playwright.config.ts'];

    try {
      $this->runProcess($cmd, 3600, 'Alfa (Playwright)', $env);

      // Generate static HTML report after tests complete successfully.
      $this->generateStaticHtmlReport($reportPaths['realPath'], 'alfa');
      $this->displayReportLocations('Siteimprove Alfa testing completed!', $reportPaths, [], $baseUrl, 'alfa');
    }
    catch (\RuntimeException $e) {
      // Generate HTML report even when tests fail (violations detected)
      $this->generateStaticHtmlReport($reportPaths['realPath'], 'alfa');

      // Show report locations even when tests fail (violations detected)
      $this->displayReportLocations('Siteimprove Alfa testing completed with violations!', $reportPaths, [
        'Console output above shows detailed violation information',
      ], $baseUrl, 'alfa');

      // Provide more helpful error message for Alfa failures.
      $this->io()->text('Alfa accessibility test failed. This could be due to:');
      $this->io()->text('  - Site accessibility violations detected (see detailed output above)');
      $this->io()->text('  - Network connectivity issues (Alfa tries to fetch rule metadata)');
      $this->io()->text('  - Missing Siteimprove packages (check npm dependencies)');
      $this->io()->text('  - Configuration issues with accessibility profiles');
      $this->io()->text('Try running: cd tests && npm install to ensure all dependencies are installed');
      $this->io()->text('Detailed violation information should be visible in the test output above');
      // Re-throw to maintain original behavior.
      throw $e;
    }
  }

  /**
   * Run Siteimprove Alfa full-site audit via sitemap.
   *
   * @command utest:a11y:alfa-full
   * @aliases utalfaf
   */
  public function alfaFull(
    array $options = [
      'base-url' => NULL,
      'sitemap-url' => NULL,
      'max-pages' => 50,
      'a11y-profile' => 'comprehensive',
      'a11y-custom-tags' => NULL,
      'a11y-severity-levels' => NULL,
    ],
  ) {
    // Ensure base-url exists.
    $baseUrl = $options['base-url'] ?: getenv('BASE_URL') ?: 'http://127.0.0.1:8888';

    // Resolve sitemap URL.
    $sitemapUrl = $options['sitemap-url']
      ?? rtrim((string) $baseUrl, '/') . '/sitemap.xml';

    $reportPaths = $this->getReportPaths('alfa-full', $baseUrl);

    // Resolve --max-pages: accept "all" or 0 as "no cap" sentinels so
    // contributors can opt into a full-sitemap sweep without hard-coding a
    // huge integer. Surface the resolved value in stdout so the time cost
    // is visible at launch.
    $maxPagesRaw = $options['max-pages'] ?? 50;
    $maxPagesEnv = $this->resolveMaxPagesEnv($maxPagesRaw);
    $this->announceMaxPages('Alfa Full Site Audit', $maxPagesRaw, 8.0);

    $env = [
      'BASE_URL' => (string) $baseUrl,
      'SITEMAP_URL' => $sitemapUrl,
      'ALFA_MAX_PAGES' => $maxPagesEnv,
      'A11Y_PROFILE' => isset($options['a11y-profile']) ? (string) $options['a11y-profile'] : 'comprehensive',
      'A11Y_CUSTOM_TAGS' => isset($options['a11y-custom-tags']) && $options['a11y-custom-tags'] ? (string) $options['a11y-custom-tags'] : NULL,
      'A11Y_SEVERITY_LEVELS' => isset($options['a11y-severity-levels']) && $options['a11y-severity-levels'] ? (string) $options['a11y-severity-levels'] : NULL,
    // Pass resolved path to test.
      'ALFA_OUTPUT_DIR' => $reportPaths['realPath'],
    ];

    // Use default Playwright config which includes both HTML and line reporters.
    $cmd = ['bash', '-lc', 'cd tests && npx playwright test accessibility/alfa/alfa-full-site.spec.js --config=playwright.config.ts'];

    try {
      $this->runProcess($cmd, 7200, 'Alfa Full Site Audit', $env);

      // For alfa-full, the spec generates its own custom HTML+JSON reports.
      // Only generate the Playwright HTML report if the custom ones are missing.
      if (!file_exists($reportPaths['realPath'] . '/alfa-full-site-report.html')) {
        $this->generateStaticHtmlReport($reportPaths['realPath'], 'alfa-full');
      }
      $this->displayAlfaReportLocations('Alfa full-site audit completed!', $reportPaths, $baseUrl);
    }
    catch (\RuntimeException $e) {
      if (!file_exists($reportPaths['realPath'] . '/alfa-full-site-report.html')) {
        $this->generateStaticHtmlReport($reportPaths['realPath'], 'alfa-full');
      }

      // Show report locations even when tests fail (violations detected)
      $this->displayAlfaReportLocations('Alfa full-site audit completed with violations!', $reportPaths, $baseUrl);

      // Provide more helpful error message for Alfa failures.
      $this->io()->text('Alfa full-site accessibility test failed. This could be due to:');
      $this->io()->text('  - Site accessibility violations detected (see detailed output above)');
      $this->io()->text('  - Network connectivity issues (Alfa tries to fetch rule metadata)');
      $this->io()->text('  - Missing Siteimprove packages (check npm dependencies)');
      $this->io()->text('  - Configuration issues with accessibility profiles');
      $this->io()->text('Try running: cd tests && npm install to ensure all dependencies are installed');
      $this->io()->text('Detailed violation information should be visible in the test output above');
      // Re-throw to maintain original behavior.
      throw $e;
    }
  }

  /**
   * Run free axe-core full-site audit via sitemap.
   *
   * Uses the open-source axe-core engine via @axe-core/playwright. No API
   * key required. Pair with `utest:a11y:axe-watcher-full` if you also want
   * the paid Deque Developer Hub dashboard.
   *
   * @command utest:a11y:axe-full
   * @aliases utaxef
   *
   * @option base-url The site base URL. Falls back to BASE_URL env var, then http://127.0.0.1:8888.
   * @option sitemap-url Optional explicit sitemap URL. Defaults to <base-url>/sitemap.xml.
   * @option max-pages Cap on sitemap pages to audit; pass "all" or 0 for no cap. Default 50.
   */
  public function axeFull(
    array $options = [
      'base-url' => NULL,
      'sitemap-url' => NULL,
      'max-pages' => 50,
      'a11y-profile' => 'comprehensive',
      'a11y-custom-tags' => NULL,
      'a11y-severity-levels' => NULL,
    ],
  ) {
    $baseUrl = $options['base-url'] ?: getenv('BASE_URL') ?: 'http://127.0.0.1:8888';
    $sitemapUrl = $options['sitemap-url']
      ?? rtrim((string) $baseUrl, '/') . '/sitemap.xml';

    $reportPaths = $this->getReportPaths('axe-full', $baseUrl);

    $maxPagesRaw = $options['max-pages'] ?? 50;
    $maxPagesEnv = $this->resolveMaxPagesEnv($maxPagesRaw);
    $this->announceMaxPages('axe Full Site Audit', $maxPagesRaw, 4.0);

    $env = [
      'BASE_URL' => (string) $baseUrl,
      'SITEMAP_URL' => $sitemapUrl,
      'AXE_MAX_PAGES' => $maxPagesEnv,
      'A11Y_PROFILE' => isset($options['a11y-profile']) ? (string) $options['a11y-profile'] : 'comprehensive',
      'A11Y_CUSTOM_TAGS' => isset($options['a11y-custom-tags']) && $options['a11y-custom-tags'] ? (string) $options['a11y-custom-tags'] : NULL,
      'A11Y_SEVERITY_LEVELS' => isset($options['a11y-severity-levels']) && $options['a11y-severity-levels'] ? (string) $options['a11y-severity-levels'] : NULL,
      'AXE_OUTPUT_DIR' => $reportPaths['realPath'],
    ];

    $cmd = ['bash', '-lc', 'cd tests && npx playwright test accessibility/axe/axe-full-site.spec.ts --config=playwright.config.ts'];

    try {
      $this->runProcess($cmd, 7200, 'axe Full Site Audit', $env);
      $this->displayAxeFullLocations('axe full-site audit completed!', $reportPaths, $baseUrl);
    }
    catch (\RuntimeException $e) {
      $this->displayAxeFullLocations('axe full-site audit completed with violations!', $reportPaths, $baseUrl);
      throw $e;
    }
  }

  /**
   * Per-test post-run message for axe-full. Mirrors displayReflowLocations:
   * no per-tool HTML report (the spec only emits JSON), so we point users at
   * the unified report and test-suite-findings.json.
   */
  protected function displayAxeFullLocations(string $message, array $reportPaths, ?string $baseUrl): void {
    $this->io()->text($message);
    $this->io()->text('');

    $jsonLocal    = $reportPaths['realPath'] . '/test-suite-findings.json';
    $jsonWeb      = isset($reportPaths['webUrl']) ? $reportPaths['webUrl'] . '/test-suite-findings.json' : NULL;
    $unifiedLocal = preg_replace('#/axe-full$#', '/index.html', $reportPaths['realPath']);
    $unifiedWeb   = isset($reportPaths['webUrl']) ? preg_replace('#/axe-full$#', '/index.html', $reportPaths['webUrl']) : NULL;

    $this->io()->text('Findings (machine-readable):');
    $this->io()->text('  - ' . $jsonLocal);
    if ($jsonWeb) {
      $this->io()->text('  - ' . $jsonWeb);
    }
    $this->io()->text('');
    $this->io()->text('axe full-site findings appear in the unified report:');
    $this->io()->text('  - ' . $unifiedLocal);
    if ($unifiedWeb) {
      $this->io()->text('  - ' . $unifiedWeb);
    }
    $this->io()->text('  (run `drush utest:report-render` after a standalone axe-full run to refresh the unified report)');
  }

  /**
   * Run a reflow audit at the WCAG 2.1 SC 1.4.10 viewport (320 CSS px).
   *
   * Neither axe-core, Siteimprove Alfa, nor pa11y check reflow natively —
   * they don't render at the target viewport. This runner does, and emits
   * a normalized test-suite-findings.json the unified report aggregates
   * alongside the other a11y engines.
   *
   * @command utest:a11y:reflow
   * @aliases utreflow
   *
   * @option base-url The site base URL. Falls back to the BASE_URL env var if unset, then to http://127.0.0.1:8888.
   * @option sitemap-url Optional explicit sitemap URL. Defaults to <base-url>/sitemap.xml.
   * @option max-pages Cap on sitemap pages to audit; pass "all" or 0 for no cap. Default 50.
   */
  public function reflow(
    array $options = [
      'base-url' => NULL,
      'sitemap-url' => NULL,
      'max-pages' => 50,
    ],
  ) {
    $baseUrl = $options['base-url'] ?: getenv('BASE_URL') ?: 'http://127.0.0.1:8888';
    $sitemapUrl = $options['sitemap-url']
      ?? rtrim((string) $baseUrl, '/') . '/sitemap.xml';

    $reportPaths = $this->getReportPaths('reflow', $baseUrl);

    $maxPagesRaw = $options['max-pages'] ?? 50;
    $maxPagesEnv = $this->resolveMaxPagesEnv($maxPagesRaw);
    $this->announceMaxPages('Reflow Audit', $maxPagesRaw, 1.5);

    $env = [
      'BASE_URL' => (string) $baseUrl,
      'SITEMAP_URL' => $sitemapUrl,
      'REFLOW_MAX_PAGES' => $maxPagesEnv,
      'REFLOW_OUTPUT_DIR' => $reportPaths['realPath'],
    ];

    $cmd = ['bash', '-lc', 'cd tests && npx playwright test accessibility/reflow/reflow.spec.js --reporter=list'];

    try {
      $this->runProcess($cmd, 3600, 'Reflow Audit', $env);
      $this->displayReflowLocations('Reflow audit completed!', $reportPaths, $baseUrl);
    }
    catch (\RuntimeException $e) {
      $this->displayReflowLocations('Reflow audit completed with violations!', $reportPaths, $baseUrl);
      throw $e;
    }
  }

  /**
   * Run a meta-viewport audit (WCAG 2.0 SC 1.4.4 — Resize Text).
   *
   * Static DOM check: reads `<meta name="viewport">` on each sitemap page
   * and flags zoom-blocking directives (`user-scalable=no`,
   * `maximum-scale<2`). Static a11y tools don't natively check this;
   * findings emit alongside the other a11y engines via the unified report.
   *
   * @command utest:a11y:meta-viewport
   * @aliases utmetaviewport
   *
   * @option base-url The site base URL. Falls back to the BASE_URL env var if unset, then to http://127.0.0.1:8888.
   * @option sitemap-url Optional explicit sitemap URL. Defaults to <base-url>/sitemap.xml.
   * @option max-pages Cap on sitemap pages to audit; pass "all" or 0 for no cap. Default 50.
   */
  public function metaViewport(
    array $options = [
      'base-url' => NULL,
      'sitemap-url' => NULL,
      'max-pages' => 50,
    ],
  ) {
    $baseUrl = $options['base-url'] ?: getenv('BASE_URL') ?: 'http://127.0.0.1:8888';
    $sitemapUrl = $options['sitemap-url']
      ?? rtrim((string) $baseUrl, '/') . '/sitemap.xml';

    $reportPaths = $this->getReportPaths('meta-viewport', $baseUrl);

    $maxPagesRaw = $options['max-pages'] ?? 50;
    $maxPagesEnv = $this->resolveMaxPagesEnv($maxPagesRaw);
    $this->announceMaxPages('Meta-viewport Audit', $maxPagesRaw, 0.5);

    $env = [
      'BASE_URL' => (string) $baseUrl,
      'SITEMAP_URL' => $sitemapUrl,
      'META_VIEWPORT_MAX_PAGES' => $maxPagesEnv,
      'META_VIEWPORT_OUTPUT_DIR' => $reportPaths['realPath'],
    ];

    $cmd = ['bash', '-lc', 'cd tests && npx playwright test accessibility/meta-viewport/meta-viewport.spec.js --reporter=list'];

    try {
      $this->runProcess($cmd, 3600, 'Meta-viewport Audit', $env);
      $this->displayMetaViewportLocations('Meta-viewport audit completed!', $reportPaths, $baseUrl);
    }
    catch (\RuntimeException $e) {
      $this->displayMetaViewportLocations('Meta-viewport audit completed with violations!', $reportPaths, $baseUrl);
      throw $e;
    }
  }

  /**
   * Per-test post-run message for meta-viewport. Mirrors displayReflowLocations:
   * static check with no per-tool HTML report; point users at the unified
   * report and test-suite-findings.json.
   */
  protected function displayMetaViewportLocations(string $message, array $reportPaths, ?string $baseUrl): void {
    $this->io()->text($message);
    $this->io()->text('');

    $jsonLocal    = $reportPaths['realPath'] . '/test-suite-findings.json';
    $jsonWeb      = isset($reportPaths['webUrl']) ? $reportPaths['webUrl'] . '/test-suite-findings.json' : NULL;
    $unifiedLocal = preg_replace('#/meta-viewport$#', '/index.html', $reportPaths['realPath']);
    $unifiedWeb   = isset($reportPaths['webUrl']) ? preg_replace('#/meta-viewport$#', '/index.html', $reportPaths['webUrl']) : NULL;

    $this->io()->text('Findings (machine-readable):');
    $this->io()->text('  - ' . $jsonLocal);
    if ($jsonWeb) {
      $this->io()->text('  - ' . $jsonWeb);
    }
    $this->io()->text('');
    $this->io()->text('Meta-viewport findings appear in the unified report under Low Vision / WCAG 1.4.4:');
    $this->io()->text('  - ' . $unifiedLocal);
    if ($unifiedWeb) {
      $this->io()->text('  - ' . $unifiedWeb);
    }
    $this->io()->text('  (run `drush utest:report-render` after a standalone meta-viewport run to refresh the unified report)');
  }

  /**
   * Per-test post-run message for reflow. Reflow has no per-tool HTML
   * report (single-rule scan) so we point users at the unified report
   * for the visual surface and the test-suite-findings.json for
   * programmatic consumption.
   */
  protected function displayReflowLocations(string $message, array $reportPaths, ?string $baseUrl): void {
    $this->io()->text($message);
    $this->io()->text('');

    $jsonLocal    = $reportPaths['realPath'] . '/test-suite-findings.json';
    $jsonWeb      = isset($reportPaths['webUrl']) ? $reportPaths['webUrl'] . '/test-suite-findings.json' : NULL;
    $unifiedLocal = preg_replace('#/reflow$#', '/index.html', $reportPaths['realPath']);
    $unifiedWeb   = isset($reportPaths['webUrl']) ? preg_replace('#/reflow$#', '/index.html', $reportPaths['webUrl']) : NULL;

    $this->io()->text('Findings (machine-readable):');
    $this->io()->text('  - ' . $jsonLocal);
    if ($jsonWeb) {
      $this->io()->text('  - ' . $jsonWeb);
    }
    $this->io()->text('');
    $this->io()->text('Reflow findings appear in the unified report under Low Vision / WCAG 1.4.10:');
    $this->io()->text('  - ' . $unifiedLocal);
    if ($unifiedWeb) {
      $this->io()->text('  - ' . $unifiedWeb);
    }
    $this->io()->text('  (run `drush utest:report-render` after a standalone reflow run to refresh the unified report)');
  }

  /**
   * Run pa11y-ci over the sitemap derived from --base-url.
   *
   * @command utest:a11y:pa11y
   * @aliases utpa11y
   * @option base-url The site base URL. Falls back to the BASE_URL env var if unset, then to http://127.0.0.1:8888.
   */
  public function pa11y(
    array $options = [
      'base-url' => NULL,
    ],
  ) {
    // Ensure pa11y-ci is installed.
    $this->ensurePackage('pa11y-ci');

    // Resolve base URL dynamically.
    $base = $options['base-url'] ?: getenv('BASE_URL') ?: 'http://127.0.0.1:8888';
    $base = rtrim($base, '/');

    // Build absolute sitemap URL.
    $sitemap = $base . '/sitemap.xml';

    $this->io()->text("Using sitemap URL: $sitemap");

    // Validate sitemap accessibility.
    $this->io()->text('Validating sitemap accessibility...');
    $sitemapContent = @file_get_contents($sitemap);
    if (!$sitemapContent) {
      throw new \RuntimeException("Sitemap not accessible: $sitemap. Please ensure the site is running and the sitemap exists.");
    }

    // Parse sitemap to count URLs.
    $urlCount = 0;
    if (strpos($sitemapContent, '<loc>') !== FALSE) {
      $urlCount = substr_count($sitemapContent, '<loc>');
    }

    if ($urlCount === 0) {
      throw new \RuntimeException("Sitemap contains no URLs: $sitemap. Please check if the sitemap is properly generated.");
    }

    $this->io()->text("Sitemap validated: found $urlCount URLs to test");

    // Get report paths for consistent reporting.
    $reportPaths = $this->getReportPaths('pa11y', $base);

    // Ensure reports directory exists.
    if (!is_dir($reportPaths['realPath'])) {
      mkdir($reportPaths['realPath'], 0777, TRUE);
    }

    // Generate runtime pa11y-ci config at tests/.pa11yci.json.
    $baseCfgPath = 'tests/accessibility/pa11y/.pa11yci.base.json';
    $localCfgPath = 'tests/.pa11yci.json';
    $cfg = [
      'defaults' => [
        'standard' => 'WCAG2AA',
        'includeWarnings' => TRUE,
        'timeout' => 90000,
        'userAgent' => 'pa11y-ci accessibility testing',
        'chromeLaunchConfig' => [
          'args' => ['--ignore-certificate-errors'],
        ],
      ],
      'concurrency' => 4,
    ];

    // Load base configuration if it exists.
    if (is_file($baseCfgPath)) {
      $raw = file_get_contents($baseCfgPath);
      $json = json_decode($raw, TRUE);
      if (is_array($json)) {
        $cfg = array_replace_recursive($cfg, $json);
      }
    }

    // Write config without sitemap (pa11y-ci v4 requires --sitemap as CLI flag)
    file_put_contents($localCfgPath, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $this->io()->text("Generated pa11y configuration with sitemap: $sitemap");

    try {
      // Run pa11y-ci with JSON output for report generation
      // --json outputs JSON to stdout, progress messages go to stderr
      // --sitemap must be a CLI flag (config file sitemap key is not used in v4)
      $this->io()->section('pa11y-ci accessibility testing');
      $jsonPath = $reportPaths['realPath'] . '/pa11y-report.json';
      $logPath = $reportPaths['realPath'] . '/pa11y.log';
      $escapedSitemap = escapeshellarg($sitemap);
      $escapedJsonPath = escapeshellarg($jsonPath);
      // Write JSON directly to file; stderr goes to log file.
      $cmd = ['bash', '-lc', "cd tests && npx pa11y-ci --sitemap $escapedSitemap --json > $escapedJsonPath 2>'$logPath'"];

      $process = new Process($cmd);
      $process->setTimeout(7200);
      $process->setEnv(array_merge($_ENV, $_SERVER));
      $process->run();

      // Read JSON from the file that pa11y-ci wrote directly.
      $jsonData = NULL;
      if (file_exists($jsonPath) && filesize($jsonPath) > 0) {
        $jsonData = json_decode(file_get_contents($jsonPath), TRUE);
      }
      if ($jsonData) {
        // Re-write with pretty formatting.
        file_put_contents($jsonPath, json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->generatePa11yHtmlReport($reportPaths['realPath'], $jsonData);

        // Emit unified test-suite-findings.json so the test-suite renderer can
        // aggregate pa11y alongside alfa / axe / lint. Failure here is
        // informational — the legacy per-test report still works.
        $emitterScript = 'tests/accessibility/pa11y/emit-findings.js';
        if (is_file($emitterScript)) {
          $emit = new Process(['node', $emitterScript, $reportPaths['realPath']]);
          $emit->setTimeout(60);
          $emit->run();
          if (!$emit->isSuccessful()) {
            $this->io()->text('Could not emit unified test-suite-findings.json: ' . trim($emit->getErrorOutput() ?: $emit->getOutput()));
          }
        }
      }

      if (!$process->isSuccessful()) {
        // pa11y found issues — still generate reports, then throw.
        $this->displayPa11yReportLocations('pa11y accessibility testing completed with issues!', $reportPaths);
        throw new \RuntimeException('pa11y-ci accessibility testing failed with exit code ' . $process->getExitCode());
      }

      $this->displayPa11yReportLocations('pa11y accessibility testing completed!', $reportPaths);

    }
    catch (\RuntimeException $e) {
      // Provide helpful error information even when tests fail.
      $this->io()->text('pa11y accessibility testing failed. This could be due to:');
      $this->io()->text('  - Accessibility violations detected on the tested pages');
      $this->io()->text('  - Network connectivity issues accessing the sitemap or pages');
      $this->io()->text('  - pa11y-ci configuration issues');
      $this->io()->text('  - Site pages returning errors (404, 500, etc.)');

      if (file_exists($reportPaths['realPath'] . '/pa11y.log')) {
        $this->io()->text('Check the log file for detailed error information: ' . $reportPaths['realPath'] . '/pa11y.log');
      }

      // Re-throw to maintain original behavior.
      throw $e;
    }
  }

  /**
   * Run custom-module PHPUnit (Unit + Kernel) tests — the Functional / Regression
   * lane. Report-only: failing tests are flagged in the unified report but never
   * fail the build. Scoped to web/modules/custom + web/profiles/custom; core and
   * contrib tests are never run.
   *
   * @command utest:phpunit
   * @aliases utphpunit
   * @option base-url The site base URL. Falls back to the BASE_URL env var.
   */
  public function phpunit(array $options = ['base-url' => NULL]) {
    $this->io()->section('Custom-module PHPUnit tests (Functional / Regression)');

    // DRUPAL_ROOT is the docroot (web/); its parent is the project root.
    $root = defined('DRUPAL_ROOT') ? dirname(DRUPAL_ROOT) : dirname(getcwd());
    $baseUrl = $options['base-url'] ?: getenv('BASE_URL') ?: 'http://127.0.0.1:8888';
    $reportPaths = $this->getReportPaths('phpunit', $baseUrl);

    // The standalone runner needs no Drupal bootstrap (so the same script powers
    // CI), handles its own fail-soft preflight (dev deps, pdo_sqlite), runs the
    // custom Unit + Kernel tests, and writes the unified findings.json.
    $runner = $root . '/tests/phpunit/run.js';
    if (!is_file($runner)) {
      $this->io()->warning("PHPUnit runner not found ($runner). Skipping.");
      return;
    }
    $process = new Process(['node', $runner, $reportPaths['realPath']], $root, NULL, NULL, 600);
    $process->run(function ($type, $buffer) {
      $this->output()->write($buffer);
    });
    $this->io()->success('PHPUnit lane complete (report-only). See the unified report under Functional / Regression.');
  }

  /**
   * Run linting checks on custom modules, themes, and profiles.
   *
   * @command utest:lint
   * @aliases utlint
   * @option base-url The site base URL. Falls back to the BASE_URL env var if unset, then to http://127.0.0.1:8888.
   * @option module Specific module name to lint (optional - lints all if not specified)
   * @option theme Specific theme name to lint (optional - lints all if not specified)
   * @option profiles Deprecated; profiles are now scanned by default. Kept as a no-op for backward compatibility.
   */
  public function lint(
    array $options = [
      'base-url' => NULL,
      'module' => NULL,
      'theme' => NULL,
      'profiles' => FALSE,
    ],
  ) {
    $this->io()->section('Running site linting');

    // Ensure JS deps are installed.
    try {
      $this->jsInstall();
    }
    catch (\Exception $e) {
      $this->io()->text('Failed to install JS dependencies: ' . $e->getMessage());
      return;
    }

    $baseUrl = $options['base-url'] ?: getenv('BASE_URL') ?: 'http://127.0.0.1:8888';
    $reportPaths = $this->getReportPaths('lint', $baseUrl);

    // Build command arguments.
    $args = [];
    if (!empty($options['module'])) {
      $args[] = '--module=' . escapeshellarg($options['module']);
    }
    if (!empty($options['theme'])) {
      $args[] = '--theme=' . escapeshellarg($options['theme']);
    }
    if (!empty($options['profiles'])) {
      $args[] = '--profiles';
    }

    $env = [
      'BASE_URL' => (string) $options['base-url'],
    ];

    try {
      // Run the linting script.
      $cmd = ['bash', '-lc', 'cd tests && node code-quality/lint-orchestrator.js ' . implode(' ', $args)];
      $this->runProcess($cmd, 300, 'Linting Tests', $env);

      $this->displayLintReportLocations('Linting Tests completed successfully!', $reportPaths);
      return TRUE;

    }
    catch (\RuntimeException $e) {
      // Linting found issues (expected behavior)
      $this->displayLintReportLocations('Linting Tests completed with issues found!', $reportPaths);
      $this->io()->text('Linting issues were detected. Please review the report above for details.');
      // Don't re-throw - linting finding issues is not a command failure.
      return FALSE;
    }
  }

  /**
   * Run all tests and (optionally) build the Drupal report index.
   *
   * @command utest:all
   * @aliases utall
   */
  public function all(
    array $options = [
      'base-url'    => NULL,
      'paths'       => NULL,
      'sitemap-url' => NULL,
      'run-id'      => 'local',
      'index'       => TRUE,
      'title'       => 'Upstream Test Reports',
      'a11y-profile' => 'comprehensive',
      'a11y-custom-tags' => NULL,
      'a11y-severity-levels' => NULL,
    ],
  ) {
    // Resolve base-url with precedence: --base-url flag > BASE_URL env > localhost.
    $options['base-url'] = $options['base-url'] ?: getenv('BASE_URL') ?: 'http://127.0.0.1:8888';

    // Resolve sitemap URL from base-url if not provided.
    if (empty($options['sitemap-url'])) {
      $options['sitemap-url'] = rtrim((string) $options['base-url'], '/') . '/sitemap.xml';
    }
    $testResults = [];

    // Ensure JS deps and browsers exist.
    try {
      $this->jsInstall();
      $this->browsers();
    }
    catch (\Exception $e) {
      $this->io()->text('Failed to install dependencies: ' . $e->getMessage());
      return;
    }

    // Run test suites with individual error handling.
    $this->io()->section('Running accessibility test suite');

    // Run linting first.
    try {
      $lintClean = $this->lint(['base-url' => $options['base-url']]);
      $testResults['lint'] = $lintClean ? 'PASSED' : 'FAILED';
      $this->io()->text('✅ Linting Tests executed');
    }
    catch (\Exception $e) {
      $testResults['lint'] = 'FAILED';
      $this->io()->text('❌ Linting Tests failed: ' . $e->getMessage());
    }

    // Run custom-module PHPUnit (Functional / Regression) before the
    // accessibility crawl. Report-only — it never fails the run.
    try {
      $this->phpunit(['base-url' => $options['base-url']]);
      $testResults['phpunit'] = 'EXECUTED';
      $this->io()->text('✅ PHPUnit (Functional / Regression) executed');
    }
    catch (\Exception $e) {
      $testResults['phpunit'] = 'SKIPPED';
      $this->io()->text('⚠️  PHPUnit lane skipped: ' . $e->getMessage());
    }

    // utest:all runs the full a11y suite — sitemap-wide Alfa + pa11y +
    // axe-full + reflow + meta-viewport. The standalone key-pages variants
    // (drush utest:a11y:alfa, drush utest:a11y:axe) remain available as
    // fast dev-feedback overlays when developers don't want to wait for
    // the full crawl.
    // Run Alfa full-site audit.
    try {
      $this->alfaFull([
        'base-url' => $options['base-url'],
        'sitemap-url' => $options['sitemap-url'],
        'a11y-profile' => $options['a11y-profile'] ?? 'comprehensive',
        'a11y-custom-tags' => $options['a11y-custom-tags'] ?? NULL,
        'a11y-severity-levels' => $options['a11y-severity-levels'] ?? NULL,
      ]);
      $testResults['alfa-full'] = 'PASSED';
      $this->io()->text('✅ Alfa full-site audit executed');
    }
    catch (\Exception $e) {
      $testResults['alfa-full'] = 'FAILED';
      $this->io()->text('❌ Alfa full-site audit failed: ' . $e->getMessage());
      $this->io()->text('This is often due to accessibility violations being detected on the site');
    }

    // Run pa11y tests.
    try {
      $this->pa11y([
        'base-url' => $options['base-url'],
      ]);
      $testResults['pa11y'] = 'PASSED';
      $this->io()->text('✅ Pa11y tests executed');
    }
    catch (\Exception $e) {
      $testResults['pa11y'] = 'FAILED';
      $this->io()->text('❌ Pa11y tests failed: ' . $e->getMessage());
    }

    // Run axe-core full-site (free) so the unified report includes the
    // `best-practice` rule chip — that filter only appears when an axe
    // pass ran (Alfa's tag filter doesn't recognize `best-practice`).
    // Sitemap-wide coverage parallel to alfa-full / pa11y, no API key
    // required.
    try {
      $this->axeFull([
        'base-url' => $options['base-url'],
        'sitemap-url' => $options['sitemap-url'],
        'a11y-profile' => $options['a11y-profile'] ?? 'comprehensive',
        'a11y-custom-tags' => $options['a11y-custom-tags'] ?? NULL,
        'a11y-severity-levels' => $options['a11y-severity-levels'] ?? NULL,
      ]);
      $testResults['axe-full'] = 'PASSED';
      $this->io()->text('✅ axe full-site tests executed');
    }
    catch (\Exception $e) {
      $testResults['axe-full'] = 'FAILED';
      $this->io()->text('❌ axe full-site tests failed: ' . $e->getMessage());
    }

    // Run reflow audit (WCAG 2.1 SC 1.4.10). No rule engine — Playwright
    // sets viewport to 320px and measures horizontal overflow. None of the
    // other a11y engines check this natively.
    try {
      $this->reflow([
        'base-url' => $options['base-url'],
        'sitemap-url' => $options['sitemap-url'],
      ]);
      $testResults['reflow'] = 'PASSED';
      $this->io()->text('✅ Reflow audit executed');
    }
    catch (\Exception $e) {
      $testResults['reflow'] = 'FAILED';
      $this->io()->text('❌ Reflow audit failed: ' . $e->getMessage());
    }

    // Run meta-viewport audit (WCAG 2.0 SC 1.4.4). Static DOM check for
    // zoom-blocking `user-scalable=no` / `maximum-scale<2` directives.
    try {
      $this->metaViewport([
        'base-url' => $options['base-url'],
        'sitemap-url' => $options['sitemap-url'],
      ]);
      $testResults['meta-viewport'] = 'PASSED';
      $this->io()->text('✅ Meta-viewport audit executed');
    }
    catch (\Exception $e) {
      $testResults['meta-viewport'] = 'FAILED';
      $this->io()->text('❌ Meta-viewport audit failed: ' . $e->getMessage());
    }

    // Render the unified Test Report if requested. The legacy
    // utest:report-index landing page is no longer built here — utest:report-render
    // is the canonical single-page report. Run `drush utest:report-index`
    // directly if you still need the legacy landing.
    if (!empty($options['index'])) {
      // Accessibility, Security, and Code Quality Report — the canonical
      // single-page view. Reads each test's test-suite-findings.json (the contract in
      // tests/reports/_shell/findings.schema.json) and renders the unified
      // shell at public://test-reports/index.html, alongside the per-test
      // lane dirs.
      try {
        $this->io()->section('Building Test Report');
        $renderApp = Drush::getApplication();
        $renderCmd = $renderApp->find('utest:report-render');
        $renderInput = new ArrayInput([
          'command'    => 'utest:report-render',
          '--dest-uri' => 'public://test-reports',
          '--src-uri'  => 'public://test-reports',
          '--base-url' => (string) $options['base-url'],
        ]);
        $renderOutput = new NullOutput();
        $renderExit = $renderCmd->run($renderInput, $renderOutput);
        if ($renderExit === 0) {
          $testResults['unified'] = 'PASSED';
          $reportUrl = $options['base-url']
            ? rtrim((string) $options['base-url'], '/') . '/sites/default/files/test-reports/index.html'
            : 'public://test-reports/index.html';
          $this->io()->text('✅ Test Report rendered: ' . $reportUrl);
        }
        else {
          $testResults['unified'] = 'FAILED';
          $this->io()->text('⚠️  Test Report render exited with code: ' . $renderExit);
        }
      }
      catch (\Exception $e) {
        $testResults['unified'] = 'FAILED';
        $this->io()->text('⚠️  Test Report render failed: ' . $e->getMessage());
      }
    }

  }

  // ---- helpers ----

  /**
   * Get report paths for a given test type.
   *
   * @param string $testType
   *   The test type (e.g., 'playwright-axe', 'alfa', 'cypress').
   * @param string $baseUrl
   *   The base URL for fallback web URL generation.
   *
   * @return array
   *   Array with 'realPath' and 'webUrl' keys.
   */
  protected function getReportPaths($testType, $baseUrl) {
    $realPath = '';
    $webUrl = '';

    // Try to use Drupal's file system if services are available.
    if ($this->fileSystem && $this->fileUrlGenerator) {
      try {
        // Create the directory using Drupal's public:// stream.
        $publicDir = "public://test-reports/$testType";
        $this->fileSystem->prepareDirectory($publicDir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

        // Get the real path for the test framework to use.
        $realPath = $this->fileSystem->realpath($publicDir);

        // Generate web-accessible URL.
        $webUrl = $this->fileUrlGenerator->generateAbsoluteString($publicDir);

        $this->io()->text('Using Drupal file system for report storage.');
      }
      catch (\Exception $e) {
        $this->io()->text('Drupal file system not available: ' . $e->getMessage());
        $realPath = '';
        $webUrl = '';
      }
    }

    // Fallback to manual path construction if Drupal services aren't available.
    if (empty($realPath)) {
      // Use absolute path to ensure it's in the correct web directory.
      $realPath = getcwd() . "/web/sites/default/files/test-reports/$testType";
      $baseUrlTrimmed = rtrim((string) $baseUrl, '/');
      $webUrl = "$baseUrlTrimmed/sites/default/files/test-reports/$testType";

      // Ensure directory exists.
      if (!is_dir($realPath)) {
        mkdir($realPath, 0777, TRUE);
      }

      $this->io()->text('Using fallback file system for report storage.');
    }

    return [
      'realPath' => $realPath,
      'webUrl' => $webUrl,
    ];
  }

  /**
   * Generate static HTML report without starting a server.
   *
   * @param string $reportPath
   *   The path where HTML reports should be generated.
   * @param string $testType
   *   The test type (e.g., 'axe', 'alfa', 'axe-watcher') for descriptive naming.
   */
  protected function generateStaticHtmlReport($reportPath, $testType = 'playwright') {
    try {
      // Get descriptive report name.
      $descriptiveName = $this->getDescriptiveReportName($testType);
      $descriptivePath = $reportPath . '/' . $descriptiveName;

      // Try multiple approaches to generate HTML report.
      $reportGenerated = FALSE;

      // Method 1: Check if Playwright already generated an HTML report in the target directory.
      if (file_exists($reportPath . '/index.html')) {
        if ($descriptiveName !== 'index.html') {
          if (rename($reportPath . '/index.html', $descriptivePath)) {
            $reportGenerated = TRUE;
            $this->io()->text("Renamed existing HTML report to: $descriptiveName");
          }
        }
        else {
          $reportGenerated = TRUE;
          $this->io()->text('HTML report already exists.');
        }
      }

      // Method 2: Use playwright show-report to generate HTML if not already present.
      if (!$reportGenerated) {
        // Check if there are any test results to generate a report from.
        if (!is_dir('tests/test-results') || empty(glob('tests/test-results/*'))) {
          $this->io()->text('No test results found, skipping HTML report generation.');
          return;
        }

        // Ensure target directory exists.
        if (!is_dir($reportPath)) {
          mkdir($reportPath, 0777, TRUE);
        }

        $env = [
          'PLAYWRIGHT_HTML_REPORT' => $reportPath,
        ];

        $cmd = ['bash', '-lc', 'cd tests && npx playwright show-report --host=none 2>/dev/null || echo "HTML report generation completed"'];

        $process = new Process($cmd);
        $process->setTimeout(60);
        $process->setEnv(array_merge($_ENV, $_SERVER, $env));
        $process->run();

        // Check if index.html was generated and rename it to descriptive name.
        if (file_exists($reportPath . '/index.html')) {
          if ($descriptiveName !== 'index.html') {
            if (rename($reportPath . '/index.html', $descriptivePath)) {
              $reportGenerated = TRUE;
              $this->io()->text("HTML report generated as: $descriptiveName");
            }
          }
          else {
            $reportGenerated = TRUE;
            $this->io()->text('HTML report generated successfully.');
          }
        }
      }

      // Method 3: Fallback - copy from default playwright-report location.
      if (!$reportGenerated && is_dir('tests/playwright-report') && file_exists('tests/playwright-report/index.html')) {
        // Ensure target directory exists.
        if (!is_dir($reportPath)) {
          mkdir($reportPath, 0777, TRUE);
        }

        $this->copyDirectory('tests/playwright-report', $reportPath);

        if (file_exists($reportPath . '/index.html')) {
          if ($descriptiveName !== 'index.html') {
            if (rename($reportPath . '/index.html', $descriptivePath)) {
              $reportGenerated = TRUE;
              $this->io()->text("HTML report copied and renamed to: $descriptiveName");
            }
          }
          else {
            $reportGenerated = TRUE;
            $this->io()->text('HTML report copied from default location.');
          }
        }
      }

      // Method 4: Generate minimal HTML report if all else fails.
      if (!$reportGenerated) {
        $this->generateMinimalHtmlReport($descriptivePath, $testType);
        $this->io()->text("Generated minimal HTML report as: $descriptiveName");
      }

    }
    catch (\Exception $e) {
      $this->io()->text('HTML report generation failed: ' . $e->getMessage());
      // Generate minimal report as final fallback.
      try {
        $descriptiveName = $this->getDescriptiveReportName($testType);
        $descriptivePath = $reportPath . '/' . $descriptiveName;
        $this->generateMinimalHtmlReport($descriptivePath, $testType);
        $this->io()->text("Generated fallback HTML report as: $descriptiveName");
      }
      catch (\Exception $fallbackError) {
        $this->io()->text('Fallback HTML report generation also failed: ' . $fallbackError->getMessage());
      }
    }
  }

  /**
   * Clear old HTML reports to ensure fresh generation.
   *
   * @param string $reportPath
   *   The path where HTML reports should be generated.
   */
  protected function clearOldReports($reportPath) {
    try {
      // Clear target report directory.
      if (is_dir($reportPath)) {
        $this->removeDirectory($reportPath);
        $this->io()->text('Cleared old HTML reports from target directory.');
      }

      // Clear default playwright-report directory to prevent stale fallback copies.
      if (is_dir('tests/playwright-report')) {
        $this->removeDirectory('tests/playwright-report');
        $this->io()->text('Cleared cached playwright-report directory.');
      }

      // Ensure target directory exists for new reports.
      if (!is_dir($reportPath)) {
        mkdir($reportPath, 0777, TRUE);
      }
    }
    catch (\Exception $e) {
      $this->io()->text('Failed to clear old reports: ' . $e->getMessage());
      // Don't throw exception - this is not critical, just log the warning.
    }
  }

  /**
   * Remove directory and all its contents recursively.
   *
   * @param string $directory
   *   Directory path to remove.
   */
  protected function removeDirectory($directory) {
    if (!is_dir($directory)) {
      return;
    }

    $files = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $file) {
      if ($file->isDir()) {
        rmdir($file->getPathname());
      }
      else {
        unlink($file->getPathname());
      }
    }

    rmdir($directory);
  }

  /**
   * Copy directory contents recursively.
   *
   * @param string $source
   *   Source directory path.
   * @param string $destination
   *   Destination directory path.
   */
  protected function copyDirectory($source, $destination) {
    if (!is_dir($destination)) {
      mkdir($destination, 0777, TRUE);
    }

    $files = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($files as $file) {
      $relativePath = substr($file->getPathname(), strlen($source) + 1);
      $destPath = $destination . DIRECTORY_SEPARATOR . $relativePath;

      if ($file->isDir()) {
        if (!is_dir($destPath)) {
          mkdir($destPath, 0777, TRUE);
        }
      }
      else {
        copy($file->getPathname(), $destPath);
      }
    }
  }

  /**
   * Get descriptive report name based on test type.
   *
   * @param string $testType
   *   The test type (e.g., 'axe', 'alfa', 'axe-watcher').
   *
   * @return string
   *   The descriptive report filename.
   */

  /**
   * Translate a `--max-pages` option value into the env-var string the
   * Playwright spec expects. Accepts integers as well as the sentinels
   * "all" or 0, which both mean "no cap".
   *
   * @param mixed $raw
   *   The value passed via --max-pages (or the default).
   *
   * @return string
   *   "0" when no cap is requested, otherwise the integer as a string.
   */
  protected function resolveMaxPagesEnv($raw): string {
    if (is_string($raw) && strtolower(trim($raw)) === 'all') {
      return '0';
    }
    $n = (int) $raw;
    return $n <= 0 ? '0' : (string) $n;
  }

  /**
   * Print a friendly heads-up about the upcoming scan size + estimated time
   * so contributors see the cost before the run starts.
   *
   * @param string $label
   *   Display name of the lane (e.g. "Alfa Full Site Audit").
   * @param mixed $raw
   *   The raw --max-pages value (string/int) for echo context.
   * @param float $perPageSeconds
   *   Rough per-page time estimate for the lane (Alfa ~8s, axe ~4s).
   */
  protected function announceMaxPages(string $label, $raw, float $perPageSeconds): void {
    $isAll = (is_string($raw) && strtolower(trim($raw)) === 'all') || (int) $raw <= 0;
    if ($isAll) {
      $this->io()->text(sprintf(
        '%s: --max-pages=all → scanning the full sitemap. Estimated cost: ~%.0fs per page (full run depends on sitemap size).',
        $label,
        $perPageSeconds
      ));
      return;
    }
    $n = (int) $raw;
    $estMin = max(1, (int) round(($n * $perPageSeconds) / 60));
    $this->io()->text(sprintf(
      '%s: scanning up to %d page%s (~%dm). Use --max-pages=all for full-sitemap coverage.',
      $label,
      $n,
      $n === 1 ? '' : 's',
      $estMin
    ));
  }

  /**
   *
   */
  protected function getDescriptiveReportName($testType) {
    $reportNames = [
      'axe' => 'axe-report.html',
      'axe-watcher' => 'axe-watcher-report.html',
      'axe-watcher-full' => 'axe-watcher-full-report.html',
      'alfa' => 'alfa-report.html',
      'alfa-full' => 'alfa-full-report.html',
      'playwright' => 'index.html',
    ];

    return $reportNames[$testType] ?? 'index.html';
  }

  /**
   * Generate a minimal HTML report as a fallback when Playwright report generation fails.
   *
   * @param string $reportPath
   *   The full path where the HTML report should be created.
   * @param string $testType
   *   The test type for display purposes.
   */
  protected function generateMinimalHtmlReport($reportPath, $testType) {
    $testTypeDisplay = ucfirst(str_replace('-', ' ', $testType));
    $timestamp = date('Y-m-d H:i:s');

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>$testTypeDisplay Test Report</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 20px; background: #f8f9fa; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 3px solid #007cba; padding-bottom: 10px; font-size: 1.8em; }
        .info { background: #e7f3ff; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #007cba; }
        .warning { background: #fff3cd; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ffc107; }
        .timestamp { color: #666; font-size: 0.9em; }
        .note { margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>$testTypeDisplay Test Report</h1>
        
        <div class="info">
            <strong>Report Status:</strong> Minimal report generated<br>
            <strong>Generated:</strong> <span class="timestamp">$timestamp</span><br>
            <strong>Test Type:</strong> $testTypeDisplay
        </div>
        
        <div class="warning">
            <strong>Notice:</strong> This is a minimal fallback report. The full Playwright HTML report could not be generated.
        </div>
        
        <div class="note">
            <h3>What happened?</h3>
            <p>The test execution completed, but the standard Playwright HTML report generation encountered an issue. This minimal report was created to ensure you have access to basic test information.</p>
            
            <h3>Next steps:</h3>
            <ul>
                <li>Check the console output from the test run for detailed results</li>
                <li>Verify that test results exist in the <code>tests/test-results</code> directory</li>
                <li>Try running the test again to see if the issue resolves</li>
                <li>Check that all npm dependencies are properly installed</li>
            </ul>
            
            <h3>For more information:</h3>
            <p>Consult the test execution logs or run the test command manually to see detailed output.</p>
        </div>
    </div>
</body>
</html>
HTML;

    // Ensure the directory exists.
    $dir = dirname($reportPath);
    if (!is_dir($dir)) {
      mkdir($dir, 0777, TRUE);
    }

    // Write the minimal HTML report.
    file_put_contents($reportPath, $html);
  }

  /**
   * Display report locations in a consistent format.
   *
   * @param string $successMessage
   *   The success message to display.
   * @param array $reportPaths
   *   Array with 'realPath' and 'webUrl' keys.
   * @param array $additionalReports
   *   Optional array of additional report descriptions.
   * @param string $baseUrl
   *   Optional base URL (unused, kept for backward compatibility).
   * @param string $testType
   *   Optional test type for descriptive report naming.
   */
  protected function displayReportLocations($successMessage, array $reportPaths, array $additionalReports = [], $baseUrl = NULL, $testType = 'playwright') {
    $this->io()->text($successMessage);
    $this->io()->text('Reports generated:');

    // Show only the descriptive report (simplified approach)
    $descriptiveName = $this->getDescriptiveReportName($testType);
    $this->io()->text('  - HTML (local): ' . $reportPaths['realPath'] . '/' . $descriptiveName);
    $this->io()->text('  - HTML (web): ' . $reportPaths['webUrl'] . '/' . $descriptiveName);

    // Display any additional reports.
    foreach ($additionalReports as $report) {
      $this->io()->text('  - ' . $report);
    }
  }

  /**
   * Display Alfa-specific report locations with both standard and custom HTML reports.
   *
   * @param string $successMessage
   *   The success message to display.
   * @param array $reportPaths
   *   Array with 'realPath' and 'webUrl' keys.
   * @param string $baseUrl
   *   The base URL for web access.
   */
  protected function displayAlfaReportLocations($successMessage, array $reportPaths, $baseUrl) {
    $this->io()->text($successMessage);
    $this->io()->text('Multiple report formats generated:');

    // Check if custom Alfa HTML report exists.
    $customHtmlExists = file_exists($reportPaths['realPath'] . '/alfa-full-site-report.html');
    $jsonExists = file_exists($reportPaths['realPath'] . '/alfa-full-site-report.json');
    $standardHtmlExists = file_exists($reportPaths['realPath'] . '/index.html');

    $this->io()->text('');
    $this->io()->text('CUSTOM ALFA VISUAL REPORT (Recommended):');
    if ($customHtmlExists) {
      $this->io()->text('  ✅ HTML (local): ' . $reportPaths['realPath'] . '/alfa-full-site-report.html');
      $this->io()->text('  ✅ HTML (web): ' . $reportPaths['webUrl'] . '/alfa-full-site-report.html');
      $this->io()->text('     → Interactive visual report with severity filtering, WCAG grouping, and detailed fix recommendations');
    }
    else {
      $this->io()->text('  ❌ Custom HTML report not found - check test execution logs');
    }

    $this->io()->text('');
    $this->io()->text('STANDARD PLAYWRIGHT REPORT:');
    if ($standardHtmlExists) {
      $this->io()->text('  ✅ HTML (local): ' . $reportPaths['realPath'] . '/index.html');
      $this->io()->text('  ✅ HTML (web): ' . $reportPaths['webUrl'] . '/index.html');
      $this->io()->text('     → Standard Playwright test results and execution details');
    }
    else {
      $this->io()->text('  ℹ️  Standard HTML report not generated (Playwright only creates detailed HTML reports when needed)');
      $this->io()->text('     → Console output above contains all test execution details');
    }

    $this->io()->text('');
    $this->io()->text('JSON DATA:');
    if ($jsonExists) {
      $this->io()->text('  ✅ JSON (local): ' . $reportPaths['realPath'] . '/alfa-full-site-report.json');
      $this->io()->text('  ✅ JSON (web): ' . $reportPaths['webUrl'] . '/alfa-full-site-report.json');
      $this->io()->text('     → Raw data for programmatic analysis and integration');
    }
    else {
      $this->io()->text('  ❌ JSON report not found - check test execution logs');
    }

    $this->io()->text('');
    if ($customHtmlExists) {
      $this->io()->text('TIP: Open the Custom Alfa Visual Report for the best accessibility analysis experience!');
      $this->io()->text('   Features: High-priority issue highlighting, interactive violation cards, WCAG criteria grouping');
    }
    else {
      $this->io()->text('⚠️  Custom HTML report generation may have failed. Check the test output above for errors.');
    }
  }

  /**
   * Display linting report locations.
   *
   * @param string $successMessage
   *   The success message to display.
   * @param array $reportPaths
   *   Array with 'realPath' and 'webUrl' keys.
   */
  protected function displayLintReportLocations($successMessage, array $reportPaths) {
    $this->io()->text($successMessage);
    $this->io()->text('Linting reports generated:');

    $htmlExists = file_exists($reportPaths['realPath'] . '/lint-report.html');

    if ($htmlExists) {
      $this->io()->text('  ✅ HTML (local): ' . $reportPaths['realPath'] . '/lint-report.html');
      $this->io()->text('  ✅ HTML (web): ' . $reportPaths['webUrl'] . '/lint-report.html');
      $this->io()->text('     → Interactive report with detailed linting results and issue breakdown');
    }
    else {
      $this->io()->text('  ❌ HTML report not found - check linting execution logs');
    }

    $this->io()->text('');
    $this->io()->text('TIP: Open the HTML report for a comprehensive view of all linting issues!');
  }

  /**
   * Display pa11y report locations.
   */
  protected function displayPa11yReportLocations($successMessage, array $reportPaths) {
    $this->io()->text($successMessage);
    $this->io()->text('Reports generated:');

    $htmlExists = file_exists($reportPaths['realPath'] . '/pa11y-report.html');
    $jsonExists = file_exists($reportPaths['realPath'] . '/pa11y-report.json');
    $logExists = file_exists($reportPaths['realPath'] . '/pa11y.log');

    if ($htmlExists) {
      $this->io()->text('  ✅ HTML: ' . $reportPaths['webUrl'] . '/pa11y-report.html');
    }
    if ($jsonExists) {
      $this->io()->text('  ✅ JSON: ' . $reportPaths['webUrl'] . '/pa11y-report.json');
    }
    if ($logExists) {
      $this->io()->text('  ✅ Log: ' . $reportPaths['webUrl'] . '/pa11y.log');
    }
  }

  /**
   * Generate HTML report from pa11y-ci JSON output.
   */
  protected function generatePa11yHtmlReport($reportPath, array $jsonData) {
    $timestamp = date('Y-m-d H:i:s');
    $results = $jsonData['results'] ?? $jsonData;

    // Collect stats and group issues by rule code.
    $totalUrls = 0;
    $totalErrors = 0;
    $totalWarnings = 0;
    $totalNotices = 0;
    // Code => { message, type, pages => [url => [selectors]] }.
    $issuesByCode = [];
    // Url => { errors, warnings, notices }.
    $pageStats = [];

    foreach ($results as $url => $issues) {
      if (!is_array($issues)) {
        continue;
      }
      $totalUrls++;
      $pageErrors = 0;
      $pageWarnings = 0;
      $pageNotices = 0;

      foreach ($issues as $issue) {
        if (!is_array($issue)) {
          continue;
        }
        $type = $issue['type'] ?? 'unknown';
        $message = $issue['message'] ?? '';
        $selector = $issue['selector'] ?? '';
        $code = $issue['code'] ?? 'unknown';

        if ($type === 'error') {
          $pageErrors++;
          $totalErrors++;
        }
        elseif ($type === 'warning') {
          $pageWarnings++;
          $totalWarnings++;
        }
        else {
          $pageNotices++;
          $totalNotices++;
        }

        if (!isset($issuesByCode[$code])) {
          $issuesByCode[$code] = [
            'message' => $message,
            'type' => $type,
            'pages' => [],
            'totalOccurrences' => 0,
          ];
        }
        if (!isset($issuesByCode[$code]['pages'][$url])) {
          $issuesByCode[$code]['pages'][$url] = [];
        }
        $issuesByCode[$code]['pages'][$url][] = $selector;
        $issuesByCode[$code]['totalOccurrences']++;
      }

      $pageStats[$url] = [
        'errors' => $pageErrors,
        'warnings' => $pageWarnings,
        'notices' => $pageNotices,
      ];
    }

    // Sort issues: errors first, then by occurrence count descending.
    uasort($issuesByCode, function ($a, $b) {
      $typeOrder = ['error' => 0, 'warning' => 1, 'notice' => 2];
      $aOrder = $typeOrder[$a['type']] ?? 3;
      $bOrder = $typeOrder[$b['type']] ?? 3;
      if ($aOrder !== $bOrder) {
        return $aOrder - $bOrder;
      }
      return $b['totalOccurrences'] - $a['totalOccurrences'];
    });

    // Build issue cards (grouped by rule)
    $issueCards = '';
    $issueIndex = 0;
    foreach ($issuesByCode as $code => $info) {
      $issueIndex++;
      $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
      $safeMessage = htmlspecialchars($info['message'], ENT_QUOTES, 'UTF-8');
      $type = htmlspecialchars($info['type'], ENT_QUOTES, 'UTF-8');
      $pageCount = count($info['pages']);
      $occurrences = $info['totalOccurrences'];
      $typeClass = $type === 'error' ? 'issue-error' : ($type === 'warning' ? 'issue-warning' : 'issue-notice');
      $typeLabel = strtoupper($type);
      $cardId = 'issue-' . $issueIndex;

      // Build page list for this issue.
      $pageList = '';
      foreach ($info['pages'] as $pageUrl => $selectors) {
        $safePageUrl = htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8');
        $selectorCount = count($selectors);
        $uniqueSelectors = array_unique($selectors);
        $selectorItems = '';
        foreach (array_slice($uniqueSelectors, 0, 3) as $sel) {
          $safeSel = htmlspecialchars($sel, ENT_QUOTES, 'UTF-8');
          $selectorItems .= "<code class='selector'>$safeSel</code> ";
        }
        $moreCount = count($uniqueSelectors) - 3;
        if ($moreCount > 0) {
          $selectorItems .= "<span class='more'>+$moreCount more</span>";
        }
        $pageList .= "<li><a href='$safePageUrl' target='_blank'>$safePageUrl</a> <span class='count'>($selectorCount)</span><div class='selectors'>$selectorItems</div></li>";
      }

      $issueCards .= <<<CARD
<div class="issue-card $typeClass" data-type="$type" id="$cardId">
  <div class="issue-header" onclick="toggleDetail('$cardId')">
    <span class="type-badge $typeClass">$typeLabel</span>
    <div class="issue-title">
      <strong>$safeCode</strong>
      <p class="issue-message">$safeMessage</p>
    </div>
    <div class="issue-meta">
      <span class="meta-stat">$occurrences occurrences</span>
      <span class="meta-stat">$pageCount pages</span>
      <span class="expand-icon">▸</span>
    </div>
  </div>
  <div class="issue-detail" id="detail-$cardId">
    <h4>Affected Pages:</h4>
    <ul class="page-list">$pageList</ul>
  </div>
</div>
CARD;
    }

    // Build page summary table.
    $pageSummaryRows = '';
    // Sort pages: most errors first.
    uasort($pageStats, function ($a, $b) {
      return $b['errors'] - $a['errors'] ?: $b['warnings'] - $a['warnings'];
    });
    foreach ($pageStats as $url => $stats) {
      $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
      $rowClass = $stats['errors'] > 0 ? 'row-error' : ($stats['warnings'] > 0 ? 'row-warning' : 'row-clear');
      $pageSummaryRows .= "<tr class='$rowClass'><td><a href='$safeUrl' target='_blank'>$safeUrl</a></td><td>{$stats['errors']}</td><td>{$stats['warnings']}</td><td>{$stats['notices']}</td></tr>";
    }

    $totalIssueTypes = count($issuesByCode);
    $overallStatus = $totalErrors > 0 ? 'Issues Found' : 'All Clear';
    $overallClass = $totalErrors > 0 ? 'status-error' : 'status-clear';

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>pa11y Accessibility Report — $overallStatus</title>
<style>
* { box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 20px; background: #f8f9fa; color: #212529; }
.container { max-width: 1200px; margin: 0 auto; }

/* Header */
.header { background: white; padding: 25px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-bottom: 3px solid #007cba; }
h1 { color: #333; margin: 0 0 10px 0; font-size: 1.8em; }
.status-badge { display: inline-block; padding: 4px 12px; border-radius: 4px; font-size: 0.6em; vertical-align: middle; margin-left: 10px; }
.status-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
.status-clear { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
.meta { color: #666; font-size: 0.9em; margin-bottom: 15px; }
.summary { display: flex; gap: 15px; flex-wrap: wrap; margin: 15px 0; }
.stat { background: #f8f9fa; padding: 12px 20px; border-radius: 6px; border-left: 4px solid #007cba; min-width: 120px; }
.stat.errors { border-color: #dc3545; }
.stat.warnings { border-color: #ffc107; }
.stat.notices { border-color: #17a2b8; }
.stat.unique { border-color: #6f42c1; }
.stat strong { font-size: 1.4em; display: block; }

/* Tabs */
.tabs { display: flex; gap: 0; margin-bottom: 0; }
.tab { padding: 12px 24px; background: #e9ecef; border: none; cursor: pointer; font-size: 1em; font-weight: 600; color: #495057; border-radius: 8px 8px 0 0; }
.tab.active { background: white; color: #007cba; box-shadow: 0 -2px 8px rgba(0,0,0,0.05); }
.tab-content { display: none; background: white; padding: 20px; border-radius: 0 8px 8px 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.tab-content.active { display: block; }

/* Filters */
.filters { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; align-items: center; }
.filter-btn { padding: 6px 16px; border: 2px solid #dee2e6; border-radius: 20px; background: white; cursor: pointer; font-size: 0.85em; font-weight: 600; transition: all 0.2s; }
.filter-btn.active { border-color: #007cba; background: #e7f3ff; color: #00538a; }
.filter-btn:hover { border-color: #007cba; }
.filter-label { font-weight: 600; color: #666; font-size: 0.9em; }
.search-box { padding: 8px 14px; border: 2px solid #dee2e6; border-radius: 20px; font-size: 0.9em; width: 250px; }
.search-box:focus { outline: none; border-color: #007cba; }

/* Issue cards */
.issue-card { border: 1px solid #e9ecef; border-radius: 8px; margin-bottom: 10px; overflow: hidden; transition: all 0.2s; }
.issue-card.hidden { display: none; }
.issue-header { display: flex; align-items: center; gap: 15px; padding: 14px 18px; cursor: pointer; transition: background 0.2s; }
.issue-header:hover { background: #f8f9fa; }
.type-badge { padding: 3px 10px; border-radius: 4px; font-size: 0.75em; font-weight: 700; white-space: nowrap; min-width: 65px; text-align: center; }
.issue-error .type-badge, .type-badge.issue-error { background: #f8d7da; color: #721c24; }
.issue-warning .type-badge, .type-badge.issue-warning { background: #fff3cd; color: #856404; }
.issue-notice .type-badge, .type-badge.issue-notice { background: #d1ecf1; color: #0c5460; }
.issue-card.issue-error { border-left: 4px solid #dc3545; }
.issue-card.issue-warning { border-left: 4px solid #ffc107; }
.issue-card.issue-notice { border-left: 4px solid #17a2b8; }
.issue-title { flex: 1; }
.issue-title strong { font-size: 0.95em; color: #333; }
.issue-message { margin: 4px 0 0; font-size: 0.85em; color: #666; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.issue-meta { display: flex; gap: 12px; align-items: center; flex-shrink: 0; }
.meta-stat { background: #f0f0f0; padding: 3px 10px; border-radius: 12px; font-size: 0.8em; font-weight: 600; color: #555; white-space: nowrap; }
.expand-icon { font-size: 1.2em; color: #999; transition: transform 0.2s; }
.issue-card.open .expand-icon { transform: rotate(90deg); }
.issue-detail { display: none; padding: 0 18px 18px; border-top: 1px solid #eee; }
.issue-card.open .issue-detail { display: block; }
.issue-detail h4 { margin: 14px 0 8px; color: #555; font-size: 0.9em; }
.page-list { list-style: none; padding: 0; margin: 0; }
.page-list li { padding: 8px 12px; border-bottom: 1px solid #f0f0f0; font-size: 0.85em; }
.page-list li:last-child { border-bottom: none; }
.page-list a { color: #007cba; word-break: break-all; }
.page-list .count { color: #999; font-size: 0.85em; }
.selectors { margin-top: 4px; }
.selector { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; font-size: 0.8em; word-break: break-all; display: inline-block; margin: 2px 0; }
.more { color: #999; font-size: 0.8em; font-style: italic; }

/* Page table */
.page-table { width: 100%; border-collapse: collapse; }
.page-table th { background: #f8f9fa; padding: 10px 14px; text-align: left; border-bottom: 2px solid #dee2e6; font-size: 0.85em; color: #555; text-transform: uppercase; cursor: pointer; }
.page-table th:hover { background: #e9ecef; }
.page-table td { padding: 10px 14px; border-bottom: 1px solid #eee; font-size: 0.9em; }
.page-table a { color: #007cba; word-break: break-all; }
.row-error td:first-child { border-left: 3px solid #dc3545; }
.row-warning td:first-child { border-left: 3px solid #ffc107; }
.row-clear td:first-child { border-left: 3px solid #28a745; }
.no-results { text-align: center; padding: 40px; color: #555; font-size: 1.1em; }
.expand-icon { color: #555 !important; }
.more { color: #555 !important; }
.page-list .count { color: #555 !important; }
.meta-stat { color: #333 !important; }
</style>
</head>
<body>
<a href="#main" class="visually-hidden" style="position:absolute;left:-9999px;">Skip to results</a>
<main id="main" class="container">
<header class="header">
  <h1>pa11y Accessibility Report <span class="status-badge $overallClass">$overallStatus</span></h1>
  <p class="meta">Generated: $timestamp | Standard: WCAG 2.1 AA</p>
  <div class="summary" role="group" aria-label="Results summary">
    <div class="stat"><strong>$totalUrls</strong> Pages</div>
    <div class="stat errors"><strong>$totalErrors</strong> Errors</div>
    <div class="stat warnings"><strong>$totalWarnings</strong> Warnings</div>
    <div class="stat notices"><strong>$totalNotices</strong> Notices</div>
    <div class="stat unique"><strong>$totalIssueTypes</strong> Unique Issues</div>
  </div>
</header>

<div class="tabs" role="tablist">
  <button class="tab active" role="tab" onclick="switchTab('issues')" id="tab-issues" aria-selected="true" aria-controls="panel-issues">Issues by Rule ($totalIssueTypes)</button>
  <button class="tab" role="tab" onclick="switchTab('pages')" id="tab-pages" aria-selected="false" aria-controls="panel-pages">Issues by Page ($totalUrls)</button>
</div>

<div class="tab-content active" id="panel-issues" role="tabpanel" aria-labelledby="tab-issues">
  <div class="filters">
    <span class="filter-label">Filter:</span>
    <button class="filter-btn active" onclick="filterIssues('all')">All ($totalIssueTypes)</button>
    <button class="filter-btn" onclick="filterIssues('error')">Errors</button>
    <button class="filter-btn" onclick="filterIssues('warning')">Warnings</button>
    <button class="filter-btn" onclick="filterIssues('notice')">Notices</button>
    <input type="search" class="search-box" placeholder="Search issues..." oninput="searchIssues(this.value)" aria-label="Search issues">
  </div>
  <div id="issue-list">
    $issueCards
  </div>
  <div class="no-results" id="no-results" style="display:none">No issues match your filter.</div>
</div>

<div class="tab-content" id="panel-pages" role="tabpanel" aria-labelledby="tab-pages">
  <table class="page-table" id="page-table">
    <thead>
      <tr>
        <th onclick="sortTable(0)" scope="col">Page URL ↕</th>
        <th onclick="sortTable(1)" scope="col">Errors ↕</th>
        <th onclick="sortTable(2)" scope="col">Warnings ↕</th>
        <th onclick="sortTable(3)" scope="col">Notices ↕</th>
      </tr>
    </thead>
    <tbody>$pageSummaryRows</tbody>
  </table>
</div>
</main>

<script>
function switchTab(tab) {
  document.querySelectorAll('.tab').forEach(t => { t.classList.remove('active'); t.setAttribute('aria-selected', 'false'); });
  document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
  document.getElementById('tab-' + tab).classList.add('active');
  document.getElementById('tab-' + tab).setAttribute('aria-selected', 'true');
  document.getElementById('panel-' + tab).classList.add('active');
}

function toggleDetail(id) {
  document.getElementById(id).classList.toggle('open');
}

function filterIssues(type) {
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
  event.target.classList.add('active');
  let visible = 0;
  document.querySelectorAll('.issue-card').forEach(card => {
    const match = type === 'all' || card.dataset.type === type;
    card.classList.toggle('hidden', !match);
    if (match) visible++;
  });
  document.getElementById('no-results').style.display = visible === 0 ? 'block' : 'none';
}

function searchIssues(query) {
  const q = query.toLowerCase();
  let visible = 0;
  document.querySelectorAll('.issue-card').forEach(card => {
    const text = card.textContent.toLowerCase();
    const match = !q || text.includes(q);
    card.classList.toggle('hidden', !match);
    if (match) visible++;
  });
  document.getElementById('no-results').style.display = visible === 0 ? 'block' : 'none';
}

let sortDir = [1, 1, 1, 1];
function sortTable(col) {
  const table = document.getElementById('page-table');
  const rows = Array.from(table.tBodies[0].rows);
  sortDir[col] *= -1;
  rows.sort((a, b) => {
    let va = a.cells[col].textContent.trim();
    let vb = b.cells[col].textContent.trim();
    if (col > 0) { va = parseInt(va) || 0; vb = parseInt(vb) || 0; return (va - vb) * sortDir[col]; }
    return va.localeCompare(vb) * sortDir[col];
  });
  rows.forEach(r => table.tBodies[0].appendChild(r));
}
</script>
</body>
</html>
HTML;

    $htmlPath = $reportPath . '/pa11y-report.html';
    file_put_contents($htmlPath, $html);
    $this->io()->text('Generated pa11y HTML report: ' . $htmlPath);
  }

  /**
   *
   */
  protected function runProcess(array $cmd, int $timeout, string $label, array $extraEnv = []) {
    $this->io()->section($label);
    $env = array_merge($_ENV, $_SERVER, $extraEnv);
    $process = new Process($cmd);
    $process->setTimeout($timeout);
    $process->setEnv($env);
    $process->run(function ($type, $buffer) {
      print $buffer;
    });
    if (!$process->isSuccessful()) {
      throw new \RuntimeException("$label failed with exit code " . $process->getExitCode());
    }
  }

}

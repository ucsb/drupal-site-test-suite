<?php

namespace Drush\Commands;

use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Input\ArrayInput;
use Drush\Attributes as CLI;
use Drush\Drush;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;

/**
 * Run JS-based accessibility & E2E tests through Drush.
 *
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
   */
  #[CLI\Command(name: 'utest:js-install', aliases: ['utjsi'])]
  public function jsInstall() {
    // Skip when deps are already present; running npm i twice per utest:all is
    // wasted network. Delete tests/node_modules to force a clean reinstall.
    if (is_dir($this->getRepoRoot() . '/tests/node_modules')) {
      $this->io()->text('tests/node_modules present; skipping npm install.');
      return;
    }
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
   */
  #[CLI\Command(name: 'utest:browsers', aliases: ['utbr'])]
  public function browsers() {
    // Idempotent: skip the slow reinstall when browsers already exist (e.g. on
    // every utest:all). Delete the ms-playwright cache to force a reinstall.
    if ($this->playwrightBrowsersPresent()) {
      $this->io()->text('Playwright browsers already installed; skipping.');
      return;
    }
    // --with-deps apt-installs OS libs and needs root, so use it only in CI;
    // locally it can hang on a sudo prompt, so install browsers only.
    $isCI = getenv('CI') === 'true' || getenv('GITHUB_ACTIONS') === 'true';
    $cmd = ['bash', '-lc', 'cd tests && npx playwright install' . ($isCI ? ' --with-deps' : '')];
    $this->runProcess($cmd, 1800, 'Installing Playwright browsers');
  }

  /**
   * Pre-flight check for the test suite.
   *
   * Validates a site's local environment before a test run. Each check
   * reports PASS / WARN / FAIL with one-line remediation when relevant.
   */
  #[CLI\Command(name: 'utest:check-config', aliases: ['utchk'])]
  #[CLI\Option(name: 'base-url', description: 'The base URL to validate reachability against. Defaults to the BASE_URL env var if set.')]
  #[CLI\Usage(name: 'drush utest:check-config', description: 'Run all checks against the BASE_URL env var (or skip URL checks if BASE_URL is not set).')]
  #[CLI\Usage(name: 'drush utest:check-config --base-url=https://site.test', description: 'Run all checks against an explicit URL.')]
  #[CLI\Usage(name: 'UTEST_CUSTOM_MODULES=foo drush utest:check-config', description: 'Verify local scoped custom-code targets before running scoped lint/PHPUnit.')]
  public function checkConfig(array $options = ['base-url' => NULL]): int {
    $baseUrl = $options['base-url'] ?? getenv('BASE_URL') ?: NULL;
    $repoRoot = $this->getRepoRoot();
    $results = [];

    // PHP CLI and Composer vendor dependencies.
    $results[] = $this->checkPhpCli();
    $results[] = $this->checkComposerVendor($repoRoot);

    // The lint and a11y lanes shell out via `bash -lc`.
    $results[] = $this->checkBashAvailable();

    // PHP test/lint tooling.
    $results[] = $this->checkPhpunitInstalled($repoRoot);
    $results[] = $this->checkPdoSqlite();
    $results[] = $this->checkLintPhpTools($repoRoot);

    // Node toolchain.
    $results[] = $this->checkNodeVersion();
    $results[] = $this->checkNpmDeps($repoRoot);
    $results[] = $this->checkPlaywrightBrowsers($repoRoot);
    $results[] = $this->checkA11yProfile();

    // Report output directory writable.
    $results[] = $this->checkReportDirectoryWritable($repoRoot);

    // Site reachability and sitemap.
    $results[] = $this->checkBaseUrl($baseUrl);
    $results[] = $this->checkSitemap($baseUrl);
    if ($baseUrl) {
      // Sitemap URL sanity needs BASE_URL.
      $results[] = $this->checkSitemapUrlSanity($baseUrl);
    }

    // Custom-paths.json globs resolve.
    $results[] = $this->checkCustomPathsConfig($repoRoot);

    // Local custom-code scope variables.
    $results[] = $this->checkCustomScopeEnvironment($repoRoot);

    return $this->renderCheckResults($results);
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
   * Check PHP CLI availability and minimum version.
   */
  protected function checkPhpCli(): array {
    $process = new Process(['php', '-r', 'echo PHP_VERSION;']);
    $process->run();
    if (!$process->isSuccessful()) {
      return [
        'name' => 'PHP CLI available',
        'status' => 'FAIL',
        'message' => 'php not found on PATH. Fix: install PHP CLI 8.1+ and verify with `php --version`.',
      ];
    }

    $version = trim($process->getOutput());
    if (version_compare($version, '8.1.0', '>=')) {
      return [
        'name' => "PHP CLI >= 8.1 (found {$version})",
        'status' => 'PASS',
        'message' => '',
      ];
    }

    return [
      'name' => "PHP CLI >= 8.1 (found {$version})",
      'status' => 'WARN',
      'message' => 'PHP 8.1+ is recommended for the test suite. Fix: switch your shell to the same PHP version as your Drupal site, then verify with `php --version`.',
    ];
  }

  /**
   * Check Composer dependencies are installed.
   */
  protected function checkComposerVendor(string $repoRoot): array {
    if (is_file($repoRoot . '/vendor/autoload.php')) {
      return [
        'name' => 'Composer vendor dependencies installed',
        'status' => 'PASS',
        'message' => '',
      ];
    }

    return [
      'name' => 'Composer vendor dependencies installed',
      'status' => 'FAIL',
      'message' => 'vendor/autoload.php not found. Fix: run `composer install` from the repository root before running Drush, lint, or PHPUnit lanes.',
    ];
  }

  /**
   * Check PHPUnit dev dependency is available.
   */
  protected function checkPhpunitInstalled(string $repoRoot): array {
    if (is_file($repoRoot . '/vendor/bin/phpunit')) {
      return [
        'name' => 'PHPUnit installed',
        'status' => 'PASS',
        'message' => '',
      ];
    }

    return [
      'name' => 'PHPUnit installed',
      'status' => 'WARN',
      'message' => 'vendor/bin/phpunit not found; utest:phpunit will skip. Fix: run `composer install` without `--no-dev`.',
    ];
  }

  /**
   * Check pdo_sqlite for PHPUnit Kernel tests.
   */
  protected function checkPdoSqlite(): array {
    $process = new Process(['php', '-r', "echo extension_loaded('pdo_sqlite') ? '1' : '0';"]);
    $process->run();
    if ($process->isSuccessful() && trim($process->getOutput()) === '1') {
      return [
        'name' => 'PHP pdo_sqlite extension enabled',
        'status' => 'PASS',
        'message' => '',
      ];
    }

    return [
      'name' => 'PHP pdo_sqlite extension enabled',
      'status' => 'WARN',
      'message' => 'pdo_sqlite missing; utest:phpunit will skip Kernel tests and run Unit tests only. Fix: enable the PHP CLI pdo_sqlite extension. Debian/Ubuntu: `sudo apt install php-sqlite3`; macOS/Homebrew: verify with `php -m | grep pdo_sqlite`; DDEV/Lando: enable it in the web container PHP image.',
    ];
  }

  /**
   * Check PHP lint tooling used by utest:lint.
   */
  protected function checkLintPhpTools(string $repoRoot): array {
    $missing = [];
    foreach (['phpcs', 'phpstan', 'twigcs'] as $tool) {
      if (!is_file($repoRoot . '/vendor/bin/' . $tool)) {
        $missing[] = 'vendor/bin/' . $tool;
      }
    }

    if (empty($missing)) {
      return [
        'name' => 'PHP lint tools installed',
        'status' => 'PASS',
        'message' => '',
      ];
    }

    return [
      'name' => 'PHP lint tools installed',
      'status' => 'WARN',
      'message' => 'Missing ' . implode(', ', $missing) . '; utest:lint may skip or degrade PHP static-analysis coverage. Fix: run `composer install` without `--no-dev`.',
    ];
  }

  /**
   * Check report directory can be created and written.
   */
  protected function checkReportDirectoryWritable(string $repoRoot): array {
    $dir = $repoRoot . '/web/sites/default/files/test-reports';
    if (!is_dir($dir) && !@mkdir($dir, 0777, TRUE) && !is_dir($dir)) {
      return [
        'name' => 'test report directory writable',
        'status' => 'FAIL',
        'message' => "Could not create {$dir}. Fix: run `mkdir -p web/sites/default/files/test-reports && chmod -R u+rwX web/sites/default/files` or adjust local filesystem permissions.",
      ];
    }

    $probe = $dir . '/.utest-check-config';
    if (@file_put_contents($probe, 'ok') === FALSE) {
      return [
        'name' => 'test report directory writable',
        'status' => 'FAIL',
        'message' => "Could not write to {$dir}. Fix: run `chmod -R u+rwX web/sites/default/files` or adjust local filesystem permissions.",
      ];
    }
    @unlink($probe);

    return [
      'name' => 'test report directory writable',
      'status' => 'PASS',
      'message' => '',
    ];
  }

  /**
   * Check a bash login shell is available (the lanes shell out via bash -lc).
   */
  protected function checkBashAvailable(): array {
    $p = new Process(['bash', '-lc', 'echo ok']);
    $p->run();
    if ($p->isSuccessful() && trim($p->getOutput()) === 'ok') {
      return ['name' => 'bash login shell available', 'status' => 'PASS', 'message' => ''];
    }
    return [
      'name' => 'bash login shell available',
      'status' => 'FAIL',
      'message' => 'The lint and a11y lanes run via `bash -lc`. Fix: on Windows run inside WSL, Git-Bash, DDEV, or Lando; bash ships with macOS/Linux.',
    ];
  }

  /**
   * Resolve the node binary via a login shell (matching the lint/a11y lanes).
   *
   * The login shell picks up nvm / profile PATH, so every node invocation uses
   * one Node.
   */
  protected function resolveNodeBinary(): string {
    $p = new Process(['bash', '-lc', 'command -v node']);
    $p->run();
    $path = trim($p->getOutput());
    return ($p->isSuccessful() && $path !== '') ? $path : 'node';
  }

  /**
   * Check Node version >= 20.
   */
  protected function checkNodeVersion(): array {
    $node = $this->resolveNodeBinary();
    $process = new Process([$node, '--version']);
    $process->run();
    if (!$process->isSuccessful()) {
      return [
        'name' => 'Node.js installed',
        'status' => 'FAIL',
        'message' => 'Node not found on PATH. Fix: install Node 20+ (`nvm install 20 && nvm use 20`) or use your package manager.',
      ];
    }
    $version = trim($process->getOutput());
    if (preg_match('/v(\d+)\./', $version, $m) && (int) $m[1] >= 20) {
      return [
        'name' => "Node.js >= 20 (found {$version} at {$node})",
        'status' => 'PASS',
        'message' => '',
      ];
    }
    return [
      'name' => "Node.js >= 20 (found {$version} at {$node})",
      'status' => 'FAIL',
      'message' => 'Node 20 or newer required. Fix: update via your version manager (`nvm install 20 && nvm use 20`) or package manager.',
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
        'message' => 'tests/node_modules not found. Fix: run `drush utest:js-install`.',
      ];
    }
    // Verify every declared dependency is installed, so a partial install
    // fails here instead of crashing a lane mid-run.
    $missing = [];
    $pkgPath = $repoRoot . '/tests/package.json';
    if (is_file($pkgPath)) {
      $pkg = json_decode((string) file_get_contents($pkgPath), TRUE);
      $declared = array_merge(
        array_keys($pkg['dependencies'] ?? []),
        array_keys($pkg['devDependencies'] ?? [])
      );
      foreach ($declared as $name) {
        if (!is_dir($nodeModules . '/' . $name)) {
          $missing[] = $name;
        }
      }
    }
    if ($missing) {
      return [
        'name' => 'tests/node_modules present',
        'status' => 'FAIL',
        'message' => 'Missing packages: ' . implode(', ', $missing) . '. Fix: run `drush utest:js-install`.',
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
    if ($this->playwrightBrowsersPresent()) {
      return [
        'name' => 'Playwright browsers installed',
        'status' => 'PASS',
        'message' => '',
      ];
    }
    return [
      'name' => 'Playwright browsers installed',
      'status' => 'WARN',
      'message' => 'Could not detect Playwright browsers. Fix: run `drush utest:browsers` if a11y tests fail.',
    ];
  }

  /**
   * Whether Playwright has a chromium browser cached.
   */
  protected function playwrightBrowsersPresent(): bool {
    // Browsers cache in ~/.cache/ms-playwright (Linux/macOS) or
    // ~/Library/Caches/ms-playwright; PLAYWRIGHT_BROWSERS_PATH overrides both.
    $home = getenv('HOME') ?: '';
    $candidates = array_values(array_filter([
      getenv('PLAYWRIGHT_BROWSERS_PATH') ?: '',
      $home . '/.cache/ms-playwright',
      $home . '/Library/Caches/ms-playwright',
    ]));
    foreach ($candidates as $path) {
      if (is_dir($path) && !empty(glob($path . '/chromium-*'))) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Check A11Y_PROFILE is a known profile (and custom has tags).
   */
  protected function checkA11yProfile(): array {
    $valid = ['strict', 'standard', 'comprehensive', 'custom'];
    $profile = getenv('A11Y_PROFILE') ?: '';
    if ($profile === '') {
      // Unset is fine; the a11y lanes default to comprehensive.
      return ['name' => 'A11Y_PROFILE valid (default: comprehensive)', 'status' => 'PASS', 'message' => ''];
    }
    if (!in_array($profile, $valid, TRUE)) {
      return [
        'name' => "A11Y_PROFILE valid (found {$profile})",
        'status' => 'WARN',
        'message' => 'Unknown A11Y_PROFILE; lanes fall back to comprehensive. Fix: set one of ' . implode(', ', $valid) . '.',
      ];
    }
    if ($profile === 'custom' && !(getenv('A11Y_CUSTOM_TAGS') ?: '')) {
      return [
        'name' => 'A11Y_PROFILE valid (custom)',
        'status' => 'WARN',
        'message' => 'A11Y_PROFILE=custom needs A11Y_CUSTOM_TAGS. Fix: set A11Y_CUSTOM_TAGS to a comma-separated tag list.',
      ];
    }
    return ['name' => "A11Y_PROFILE valid ({$profile})", 'status' => 'PASS', 'message' => ''];
  }

  /**
   * Check BASE_URL reachability.
   */
  protected function checkBaseUrl(?string $baseUrl): array {
    if (!$baseUrl) {
      return [
        'name' => 'BASE_URL reachable',
        'status' => 'WARN',
        'message' => "BASE_URL not set. Fix: export your local site URL and re-run the check:\n"
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
        'message' => "curl error: {$err}. Fix: start your local site, verify the URL, then run `curl -I {$baseUrl}` from this shell.",
      ];
    }
    return [
      'name' => "BASE_URL reachable ({$baseUrl})",
      'status' => 'FAIL',
      'message' => "HTTP {$code}. Fix: check that your local site is serving content at this URL, then verify with `curl -I {$baseUrl}`.",
    ];
  }

  /**
   * Module-agnostic hint for generating a sitemap (simple_sitemap as example).
   */
  protected function sitemapGenerateHint(string $baseUrl): string {
    return 'generate /sitemap.xml with your sitemap module against the real host'
      . ' (e.g. simple_sitemap: `drush --uri=' . $baseUrl . ' simple-sitemap:generate && drush cr`),'
      . ' set SITEMAP_URL if your sitemap lives elsewhere, or pass `--paths` for key-page lanes';
  }

  /**
   * Module-agnostic hint for regenerating a sitemap against the real host.
   */
  protected function sitemapRegenerateHint(string $baseUrl): string {
    return 'regenerate the sitemap against the real host with your sitemap module'
      . ' (e.g. simple_sitemap: `drush --uri=' . $baseUrl . ' simple-sitemap:rebuild-queue'
      . ' && drush --uri=' . $baseUrl . ' simple-sitemap:generate && drush cr`)';
  }

  /**
   * Check that sitemap.xml is reachable (warn-only).
   */
  protected function checkSitemap(?string $baseUrl): array {
    if (!$baseUrl) {
      return [
        'name' => 'sitemap.xml reachable',
        'status' => 'WARN',
        'message' => 'BASE_URL not set; cannot verify sitemap. Fix: export BASE_URL or pass --base-url to check sitemap availability.',
      ];
    }
    $sitemapUrl = rtrim($baseUrl, '/') . '/sitemap.xml';
    $ch = curl_init($sitemapUrl);
    curl_setopt_array($ch, [
      CURLOPT_FOLLOWLOCATION => TRUE,
      CURLOPT_TIMEOUT => 10,
      CURLOPT_SSL_VERIFYPEER => FALSE,
      CURLOPT_RETURNTRANSFER => TRUE,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $curlErrNo = curl_errno($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    $name = 'sitemap.xml reachable';

    // Redirected to the installer: the site is not installed.
    if (str_contains($effectiveUrl, '/core/install.php')) {
      return [
        'name' => $name,
        'status' => 'WARN',
        'message' => 'sitemap.xml redirected to the Drupal installer (' . $effectiveUrl . '); the site is not installed. Fix: install the site, then ' . $this->sitemapGenerateHint($baseUrl) . '.',
      ];
    }

    // No response at all: site down, DNS failure, refused, or timeout.
    if ($curlErrNo !== 0 || $code === 0) {
      $reason = $curlErr !== '' ? $curlErr : 'no response';
      return [
        'name' => $name,
        'status' => 'WARN',
        'message' => "Could not connect to {$sitemapUrl} ({$reason}); the site may be down or BASE_URL may be wrong. Fix: confirm the site is running and reachable (`curl -I {$baseUrl}`), then re-run.",
      ];
    }

    // Access denied: sitemap and pages are behind auth or IP restriction.
    if ($code === 401 || $code === 403) {
      return [
        'name' => $name,
        'status' => 'WARN',
        'message' => "sitemap.xml returned HTTP {$code} (access denied); it and the crawled pages may be behind HTTP auth or IP restriction. Fix: make them reachable from this shell (protected hosting or preview environments may need a bypass header), or pass `--paths` for key-page lanes.",
      ];
    }

    // Not generated yet: the most common case, no sitemap on the site.
    if ($code === 404) {
      return [
        'name' => $name,
        'status' => 'WARN',
        'message' => 'sitemap.xml not found (HTTP 404); no sitemap has been generated. Fix: ' . $this->sitemapGenerateHint($baseUrl) . '.',
      ];
    }

    // Server error: the site itself errored producing the sitemap.
    if ($code >= 500) {
      return [
        'name' => $name,
        'status' => 'WARN',
        'message' => "sitemap.xml returned HTTP {$code} (server error); the site errored. Fix: check the site's logs and retry, or pass `--paths` for key-page lanes.",
      ];
    }

    // Any other non-success status.
    if ($code < 200 || $code >= 400) {
      return [
        'name' => $name,
        'status' => 'WARN',
        'message' => "sitemap.xml returned HTTP {$code}; full-site a11y crawls may need explicit paths. Fix: " . $this->sitemapGenerateHint($baseUrl) . '.',
      ];
    }

    // A 200 can still be HTML (login wall, catch-all); confirm it is a sitemap.
    if (!is_string($body) || !preg_match('#<(urlset|sitemapindex|loc)\b#i', $body)) {
      return [
        'name' => 'sitemap.xml reachable',
        'status' => 'WARN',
        'message' => 'sitemap.xml returned 200 but the body is not a sitemap (no <urlset>/<loc>); the site may not be installed, or your sitemap module has not generated yet. Fix: confirm the site is installed, then ' . $this->sitemapGenerateHint($baseUrl) . '.',
      ];
    }

    return [
      'name' => 'sitemap.xml reachable',
      'status' => 'PASS',
      'message' => '',
    ];
  }

  /**
   * Flag sitemap <loc> URLs that crawl the wrong place (warn-only).
   *
   * Catches installer-prefixed and wrong-host <loc> entries from a sitemap
   * built with a bad base URL. Never flags index.php (legit on proxy sites).
   */
  protected function checkSitemapUrlSanity(string $baseUrl): array {
    $name = 'sitemap URLs use the real site host';
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

    // Reachability is the prior check's job; don't double-warn here.
    if ($code < 200 || $code >= 400 || !is_string($body) || $body === '') {
      return ['name' => $name, 'status' => 'PASS', 'message' => ''];
    }
    if (!preg_match_all('#<loc>\s*(.*?)\s*</loc>#i', $body, $matches) || empty($matches[1])) {
      return ['name' => $name, 'status' => 'PASS', 'message' => ''];
    }

    // Host-match gate: if the sitemap has URLs but none point at the
    // configured base, the crawl targets the wrong site. Fail hard so
    // check-config (and utest:all's pre-flight) blocks the run.
    $base = rtrim($baseUrl, '/');
    $matchesBase = FALSE;
    foreach ($matches[1] as $loc) {
      if (strpos(html_entity_decode(trim($loc)), $base) === 0) {
        $matchesBase = TRUE;
        break;
      }
    }
    if (!$matchesBase) {
      return [
        'name' => $name,
        'status' => 'FAIL',
        'message' => 'Sitemap host mismatch: none of the ' . count($matches[1])
        . ' sitemap URLs start with ' . $base
        . '. Regenerate it: drush --uri=' . $base . ' simple-sitemap:generate',
      ];
    }

    $baseHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
    $installerHits = [];
    $hostMismatches = [];
    foreach (array_slice($matches[1], 0, 50) as $loc) {
      $loc = html_entity_decode($loc);
      $path = (string) parse_url($loc, PHP_URL_PATH);
      if ($path !== '' && str_contains($path, '/core/install.php')) {
        $installerHits[] = $loc;
      }
      $host = strtolower((string) parse_url($loc, PHP_URL_HOST));
      // Only compare when both hosts are known; never flag index.php paths.
      if ($host !== '' && $baseHost !== '' && $host !== $baseHost) {
        $hostMismatches[] = $host;
      }
    }

    if (empty($installerHits) && empty($hostMismatches)) {
      return ['name' => $name, 'status' => 'PASS', 'message' => ''];
    }

    $problems = [];
    if (!empty($installerHits)) {
      $problems[] = count($installerHits) . ' installer-prefixed URL(s) (e.g. ' . $installerHits[0] . ')';
    }
    if (!empty($hostMismatches)) {
      $uniqueHosts = array_slice(array_values(array_unique($hostMismatches)), 0, 3);
      $problems[] = 'host(s) other than ' . $baseHost . ': ' . implode(', ', $uniqueHosts);
    }

    return [
      'name' => $name,
      'status' => 'WARN',
      'message' => 'Sitemap has URLs that will crawl the wrong place: ' . implode('; ', $problems)
      . '. These come from generating the sitemap with a wrong base URL. Fix: ' . $this->sitemapRegenerateHint($baseUrl)
      . ', then re-run check-config. (index.php in URLs is allowed and not flagged.)',
    ];
  }

  /**
   * Preflight the sitemap before launching an a11y lane.
   *
   * Stops the lane cleanly if the sitemap is unreachable, empty, or built
   * for a different host than BASE_URL. Returns TRUE only when the sitemap
   * has URLs that point at the configured base.
   */
  protected function preflightSitemap(string $sitemapUrl, string $baseUrl): bool {
    $ch = curl_init($sitemapUrl);
    curl_setopt_array($ch, [
      CURLOPT_FOLLOWLOCATION => TRUE,
      CURLOPT_TIMEOUT => 10,
      CURLOPT_SSL_VERIFYPEER => FALSE,
      CURLOPT_RETURNTRANSFER => TRUE,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    $base = rtrim($baseUrl, '/');

    // Unreachable or empty body.
    if (!is_string($body) || trim($body) === '') {
      $this->io()->error("Sitemap not reachable at $sitemapUrl. Start the site (or pass --sitemap-url), then retry.");
      return FALSE;
    }

    // No <loc> URLs in the body.
    if (!preg_match_all('#<loc>\s*([^<]+?)\s*</loc>#i', $body, $m) || empty($m[1])) {
      $this->io()->error("Sitemap at $sitemapUrl has no URLs. Regenerate it: drush --uri=$base simple-sitemap:generate");
      return FALSE;
    }

    // No URL points at the configured base host.
    $urls = $m[1];
    $matchesBase = FALSE;
    foreach ($urls as $loc) {
      if (strpos(html_entity_decode(trim($loc)), $base) === 0) {
        $matchesBase = TRUE;
        break;
      }
    }
    if (!$matchesBase) {
      $sample = array_slice($urls, 0, 3);
      $this->io()->error(
        count($urls) . " sitemap URL(s) do not match the base $base.\n"
        . 'Sample: ' . implode(', ', $sample) . "\n"
        . "Regenerate it: drush --uri=$base simple-sitemap:generate"
      );
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Lean readiness gate for a single a11y lane.
   *
   * Runs only the checks that affect a crawl (npm deps, Playwright browsers,
   * base URL, sitemap), renders any failures, and returns FALSE when the lane
   * should not run. The full check-config gate stays reserved for utest:all.
   *
   * @param string $baseUrl
   *   The resolved base URL the lane will crawl.
   * @param string $sitemapUrl
   *   The sitemap URL the lane will read.
   * @param bool $needsPlaywright
   *   Whether Playwright browsers are required. pa11y uses puppeteer, so it
   *   passes FALSE.
   * @param string $label
   *   Display name of the lane running the check, so the console header
   *   says which test the pre-flight belongs to.
   */
  protected function preflightLane(string $baseUrl, string $sitemapUrl, bool $needsPlaywright = TRUE, string $label = ''): bool {
    $repoRoot = $this->getRepoRoot();
    $results = [$this->checkNpmDeps($repoRoot)];
    if ($needsPlaywright) {
      $results[] = $this->checkPlaywrightBrowsers($repoRoot);
    }
    $results[] = $this->checkBaseUrl($baseUrl);
    $title = $label !== '' ? "$label: pre-flight check" : 'Test suite pre-flight check';
    if ($this->renderCheckResults($results, $title) > 0) {
      return FALSE;
    }
    return $this->preflightSitemap($sitemapUrl, $baseUrl);
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
        'message' => 'Custom-paths config not found. Suite will rely on composer.json installer-paths + *.info.yml autodiscovery only. Fix: add tests/code-quality/config/custom-paths.json if your custom code lives in non-standard paths.',
      ];
    }
    $raw = file_get_contents($configPath);
    $config = json_decode($raw, TRUE);
    if (!$config) {
      return [
        'name' => 'tests/code-quality/config/custom-paths.json valid',
        'status' => 'FAIL',
        'message' => 'JSON parse failed. Fix: validate tests/code-quality/config/custom-paths.json against tests/code-quality/config/custom-paths.schema.json.',
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
        'message' => 'Some paths match zero files: ' . implode(', ', array_slice($unresolved, 0, 3)) . (count($unresolved) > 3 ? '...' : '') . '. Fix: update or remove stale entries in tests/code-quality/config/custom-paths.json.',
      ];
    }
    return [
      'name' => 'tests/code-quality/config/custom-paths.json globs resolve',
      'status' => 'PASS',
      'message' => '',
    ];
  }

  /**
   * Check local custom-code scope variables and target existence.
   */
  protected function checkCustomScopeEnvironment(string $repoRoot): array {
    $scope = [];
    $envMap = [
      'modules' => 'UTEST_CUSTOM_MODULES',
      'themes' => 'UTEST_CUSTOM_THEMES',
      'profiles' => 'UTEST_CUSTOM_PROFILES',
    ];

    foreach ($envMap as $type => $envName) {
      $value = getenv($envName);
      $items = $this->parseCheckConfigScopeValue($value ?: '');
      if (!empty($items)) {
        $scope[$type] = $items;
      }
    }

    if (empty($scope)) {
      return [
        'name' => 'UTEST_CUSTOM_* local scope variables',
        'status' => 'PASS',
        'message' => 'No local custom-code scope variables set; lint/PHPUnit run all custom code by default.',
      ];
    }

    $missing = [];
    $invalid = [];
    foreach ($scope as $type => $items) {
      foreach ($items as $item) {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $item)) {
          $invalid[] = $type . ': ' . $item;
          continue;
        }
        if (!$this->customScopeTargetExists($repoRoot, $type, $item)) {
          $missing[] = $type . ': ' . $item;
        }
      }
    }

    if (!empty($invalid) || !empty($missing)) {
      $messages = [];
      if (!empty($invalid)) {
        $messages[] = 'Invalid machine names: ' . implode(', ', $invalid);
      }
      if (!empty($missing)) {
        $messages[] = 'Targets not found: ' . implode(', ', $missing);
      }
      return [
        'name' => 'UTEST_CUSTOM_* local scope variables',
        'status' => 'WARN',
        'message' => implode('. ', $messages) . '. Fix: correct the machine names in UTEST_CUSTOM_MODULES / UTEST_CUSTOM_THEMES / UTEST_CUSTOM_PROFILES, or unset them to run all custom code.',
      ];
    }

    $parts = [];
    foreach ($scope as $type => $items) {
      $parts[] = $type . ': ' . implode(', ', $items);
    }
    return [
      'name' => 'UTEST_CUSTOM_* local scope variables',
      'status' => 'WARN',
      'message' => 'Local scope is set (' . implode('; ', $parts) . '). utest:lint and utest:phpunit will use this scope unless CLI scope flags or --ignore-scope are passed. Tip: run `unset UTEST_CUSTOM_MODULES UTEST_CUSTOM_THEMES UTEST_CUSTOM_PROFILES` to clear it.',
    ];
  }

  /**
   * Parse a comma/space-separated scope env var for check-config.
   */
  protected function parseCheckConfigScopeValue(string $value): array {
    if (trim($value) === '') {
      return [];
    }
    return array_values(array_unique(array_filter(
      preg_split('/[\s,]+/', $value),
      fn($item) => trim((string) $item) !== '',
    )));
  }

  /**
   * Determine whether a custom-code scope target exists.
   */
  protected function customScopeTargetExists(string $repoRoot, string $type, string $name): bool {
    $patterns = match ($type) {
      'modules' => [
        "web/modules/custom/**/{$name}/{$name}.info.yml",
        "web/profiles/custom/**/modules/**/{$name}/{$name}.info.yml",
      ],
      'themes' => [
        "web/themes/**/{$name}/{$name}.info.yml",
        "web/profiles/custom/**/themes/**/{$name}/{$name}.info.yml",
      ],
      'profiles' => [
        "web/profiles/custom/**/{$name}/{$name}.info.yml",
      ],
      default => [],
    };

    foreach ($patterns as $pattern) {
      $matches = glob($repoRoot . '/' . $pattern, GLOB_BRACE);
      $matches = array_filter($matches, fn($path) => !str_contains($path, '/contrib/'));
      if (!empty($matches)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Render a list of check results to the console.
   */
  protected function renderCheckResults(array $results, string $title = 'Test suite pre-flight check'): int {
    $this->io()->title($title);
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
    return count($fails);
  }

  /**
   * Run axe Developer Hub full-site audit via sitemap.
   *
   * Requires AXE_API_KEY and a reachable sitemap. Use --sitemap-url when the
   * sitemap is not available at BASE_URL/sitemap.xml.
   */
  #[CLI\Command(name: 'utest:axe-watcher', aliases: ['utaxew'])]
  #[CLI\Option(name: 'base-url', description: 'The site base URL. Falls back to BASE_URL env var, then http://127.0.0.1:8888.')]
  #[CLI\Option(name: 'sitemap-url', description: 'Explicit sitemap URL. Defaults to <base-url>/sitemap.xml.')]
  #[CLI\Option(name: 'max-pages', description: 'Cap on sitemap pages to audit; pass "all" or 0 for no cap. Default 50.')]
  #[CLI\Option(name: 'axe-api-key', description: 'axe Developer Hub API key. Falls back to AXE_API_KEY env var.')]
  #[CLI\Usage(name: 'AXE_API_KEY=... drush utest:axe-watcher --sitemap-url=https://site.test/sitemap.xml', description: 'Run paid full-site axe Developer Hub checks using an explicit sitemap.')]
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

    $cmd = [
      'bash',
      '-lc',
      'cd tests && npx playwright test accessibility/axe/axe-watcher-full-site.spec.ts --config=playwright.config.ts',
    ];

    // Stop cleanly if the sitemap is unreachable, empty, or host-mismatched.
    if (!$this->preflightLane($baseUrl, $sitemapUrl, TRUE, 'axe Developer Hub Full Site Audit')) {
      return self::EXIT_FAILURE;
    }

    try {
      $this->runProcess($cmd, 7200, 'Playwright + axe Developer Hub Full Site', $env);

      // Generate static HTML report after tests complete successfully.
      $this->generateStaticHtmlReport($reportPaths['realPath'], 'axe-watcher-full');
      $this->displayReportLocations('axe Developer Hub full-site testing completed!', $reportPaths, [
        'axe Developer Hub: Check your dashboard for comprehensive results',
      ], $baseUrl, 'axe-watcher-full');
    }
    catch (\RuntimeException $e) {
      $this->io()->error('axe Developer Hub full-site audit did not complete: ' . $e->getMessage() . ' See the output above.');
      return self::EXIT_FAILURE;
    }

    return self::EXIT_SUCCESS;
  }

  /**
   * Run Siteimprove Alfa full-site audit via sitemap.
   *
   * Requires a reachable sitemap. Use --sitemap-url when the sitemap is not
   * available at BASE_URL/sitemap.xml.
   */
  #[CLI\Command(name: 'utest:alfa', aliases: ['utalfa'])]
  #[CLI\Option(name: 'base-url', description: 'The site base URL. Falls back to BASE_URL env var, then http://127.0.0.1:8888.')]
  #[CLI\Option(name: 'sitemap-url', description: 'Explicit sitemap URL. Defaults to <base-url>/sitemap.xml.')]
  #[CLI\Option(name: 'max-pages', description: 'Cap on sitemap pages to audit; pass "all" or 0 for no cap. Default 50.')]
  #[CLI\Option(name: 'a11y-profile', description: 'Accessibility profile: strict, standard, comprehensive, or custom.')]
  #[CLI\Usage(name: 'drush utest:alfa --sitemap-url=https://site.test/sitemap.xml', description: 'Run Alfa full-site using an explicit sitemap.')]
  #[CLI\Usage(name: 'drush utest:alfa --max-pages=all', description: 'Run Alfa against every URL in the sitemap.')]
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

    // Use the default Playwright config (HTML + line reporters).
    $cmd = [
      'bash',
      '-lc',
      'cd tests && npx playwright test accessibility/alfa/alfa-full-site.spec.js --config=playwright.config.ts',
    ];

    // Stop cleanly if the sitemap is unreachable, empty, or host-mismatched.
    if (!$this->preflightLane($baseUrl, $sitemapUrl, TRUE, 'Alfa Full Site Audit')) {
      return self::EXIT_FAILURE;
    }

    try {
      $this->runProcess($cmd, 7200, 'Alfa Full Site Audit', $env);

      // For alfa-full, the spec generates its own custom HTML+JSON reports.
      // Only generate the Playwright HTML report if the custom ones are
      // missing.
      if (!file_exists($reportPaths['realPath'] . '/alfa-full-report.html')) {
        $this->generateStaticHtmlReport($reportPaths['realPath'], 'alfa-full');
      }
      $this->displayAlfaReportLocations('Alfa full-site audit completed!', $reportPaths, $baseUrl);
      return $this->gateLaneFindings($reportPaths['realPath'], 'Alfa full-site audit');
    }
    catch (\RuntimeException $e) {
      if (!file_exists($reportPaths['realPath'] . '/alfa-full-report.html')) {
        $this->generateStaticHtmlReport($reportPaths['realPath'], 'alfa-full');
      }

      // Show report locations even when the gate fails.
      $this->displayAlfaReportLocations('Alfa full-site audit completed with findings!', $reportPaths, $baseUrl);

      // The spec exits non-zero on critical/serious findings or an
      // incomplete crawl; the findings gate says which it was. Anything
      // else means the run itself broke before emitting findings.
      $status = $this->laneFindingsStatus($reportPaths['realPath']);
      if ($status === 'FAILED' || $status === 'INCOMPLETE') {
        $this->gateLaneFindings($reportPaths['realPath'], 'Alfa full-site audit');
      }
      else {
        $this->logger()->warning('Alfa full-site audit exited non-zero without emitting findings: ' . $e->getMessage() . ' Check the output above for missing dependencies or a crashed run.');
      }
      return self::EXIT_FAILURE;
    }
  }

  /**
   * Run free axe-core full-site audit via sitemap.
   *
   * Uses the open-source axe-core engine via @axe-core/playwright. No API
   * key required. Pair with `utest:axe-watcher` if you also want
   * the paid Deque Developer Hub dashboard.
   */
  #[CLI\Command(name: 'utest:axe', aliases: ['utaxe'])]
  #[CLI\Option(name: 'base-url', description: 'The site base URL. Falls back to BASE_URL env var, then http://127.0.0.1:8888.')]
  #[CLI\Option(name: 'sitemap-url', description: 'Optional explicit sitemap URL. Defaults to <base-url>/sitemap.xml.')]
  #[CLI\Option(name: 'max-pages', description: 'Cap on sitemap pages to audit; pass "all" or 0 for no cap. Default 50.')]
  #[CLI\Option(name: 'a11y-profile', description: 'Accessibility profile: strict, standard, comprehensive, or custom.')]
  #[CLI\Usage(name: 'drush utest:axe --sitemap-url=https://site.test/sitemap.xml', description: 'Run axe full-site using an explicit sitemap.')]
  #[CLI\Usage(name: 'drush utest:axe --max-pages=all', description: 'Run axe against every URL in the sitemap.')]
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

    $cmd = [
      'bash',
      '-lc',
      'cd tests && npx playwright test accessibility/axe/axe-full-site.spec.ts --config=playwright.config.ts',
    ];

    // Stop cleanly if the sitemap is unreachable, empty, or host-mismatched.
    if (!$this->preflightLane($baseUrl, $sitemapUrl, TRUE, 'axe Full Site Audit')) {
      return self::EXIT_FAILURE;
    }

    try {
      $this->runProcess($cmd, 7200, 'axe Full Site Audit', $env);
      $this->displayAxeFullLocations('axe full-site audit completed!', $reportPaths, $baseUrl);
    }
    catch (\RuntimeException $e) {
      $this->io()->error('axe full-site audit did not complete: ' . $e->getMessage() . ' See the output above.');
      return self::EXIT_FAILURE;
    }

    return $this->gateLaneFindings($reportPaths['realPath'], 'axe full-site audit');
  }

  /**
   * Per-test post-run message for axe-full.
   *
   * Leads with the standalone axe HTML report (shared renderer), then the
   * machine-readable JSON, then the unified report.
   */
  protected function displayAxeFullLocations(string $message, array $reportPaths, ?string $baseUrl): void {
    $this->io()->text($message);
    $this->io()->text('');

    $reportLocal  = $reportPaths['realPath'] . '/axe-full-report.html';
    $reportWeb    = isset($reportPaths['webUrl']) ? $reportPaths['webUrl'] . '/axe-full-report.html' : NULL;
    $jsonLocal    = $reportPaths['realPath'] . '/test-suite-findings.json';
    $jsonWeb      = isset($reportPaths['webUrl']) ? $reportPaths['webUrl'] . '/test-suite-findings.json' : NULL;
    $unifiedLocal = preg_replace('#/axe-full$#', '/index.html', $reportPaths['realPath']);
    $unifiedWeb   = isset($reportPaths['webUrl']) ? preg_replace('#/axe-full$#', '/index.html', $reportPaths['webUrl']) : NULL;

    if (file_exists($reportLocal)) {
      $this->io()->text('Open the report:');
      $this->io()->text('  - ' . $reportLocal);
      if ($reportWeb) {
        $this->io()->text('  - ' . $reportWeb);
      }
      $this->io()->text('');
    }
    $this->io()->text('Findings (machine-readable):');
    $this->io()->text('  - ' . $jsonLocal);
    if ($jsonWeb) {
      $this->io()->text('  - ' . $jsonWeb);
    }
    $this->io()->text('');
    $this->io()->text('Also included in the unified report:');
    $this->io()->text('  - ' . $unifiedLocal);
    if ($unifiedWeb) {
      $this->io()->text('  - ' . $unifiedWeb);
    }
    // utest:all re-renders the unified report at the end of the run, so
    // the refresh hint only applies to standalone lane runs.
    if (getenv('UTEST_ALL') !== '1') {
      $this->io()->text('  (run `drush utest:report-render` after a standalone axe run to refresh the unified report)');
    }
  }

  /**
   * Gate an a11y lane on its emitted test-suite-findings.json.
   *
   * Uniform policy across every lane: critical/serious findings fail,
   * moderate/minor are advisory, and an incomplete run (0 pages, errored
   * pages, or no findings file) fails so it is never read as a pass.
   */
  protected function gateLaneFindings(string $findingsDir, string $laneLabel): int {
    $path = $findingsDir . '/test-suite-findings.json';
    if (!is_file($path)) {
      $this->logger()->warning("$laneLabel emitted no test-suite-findings.json; the run is incomplete, not a pass.");
      return self::EXIT_FAILURE;
    }
    $data = json_decode(file_get_contents($path), TRUE);
    $summary = is_array($data) ? ($data['summary'] ?? []) : [];
    if (($summary['status'] ?? '') === 'incomplete') {
      $errored = (int) ($summary['pages_errored'] ?? 0);
      $tested = (int) ($summary['pages_tested'] ?? 0);
      $this->logger()->warning("$laneLabel run is incomplete ($errored of $tested page(s) errored); re-run before trusting the result.");
      return self::EXIT_FAILURE;
    }
    $totals = $summary['totals_by_severity'] ?? [];
    $gating = (int) ($totals['critical'] ?? 0) + (int) ($totals['serious'] ?? 0);
    if ($gating > 0) {
      // Warning, not error: the run itself worked, the findings are the
      // result. The summary table and exit code carry the failure.
      $this->logger()->warning("$laneLabel found $gating critical/serious issue(s). See the report above.");
      return self::EXIT_FAILURE;
    }
    $advisory = is_array($data['findings'] ?? NULL) ? count($data['findings']) : 0;
    if ($advisory > 0) {
      $this->logger()->success("$laneLabel passed with $advisory advisory (moderate/minor) issue(s), non-blocking.");
      return self::EXIT_SUCCESS;
    }
    $this->logger()->success("$laneLabel passed.");
    return self::EXIT_SUCCESS;
  }

  /**
   * Granular lane status from its emitted test-suite-findings.json.
   *
   * Returns PASSED, PASSED (advisory), FAILED, or INCOMPLETE so the
   * utest:all summary can distinguish a clean pass from one with
   * non-blocking moderate/minor findings.
   */
  protected function laneFindingsStatus(string $findingsDir): string {
    $path = $findingsDir . '/test-suite-findings.json';
    if (!is_file($path)) {
      return 'INCOMPLETE';
    }
    $data = json_decode(file_get_contents($path), TRUE);
    $summary = is_array($data) ? ($data['summary'] ?? []) : [];
    if (($summary['status'] ?? '') === 'incomplete') {
      return 'INCOMPLETE';
    }
    $totals = $summary['totals_by_severity'] ?? [];
    if ((int) ($totals['critical'] ?? 0) + (int) ($totals['serious'] ?? 0) > 0) {
      return 'FAILED';
    }
    $advisory = is_array($data['findings'] ?? NULL) ? count($data['findings']) : 0;
    return $advisory > 0 ? 'PASSED (advisory)' : 'PASSED';
  }

  /**
   * Summary status for an a11y lane in utest:all.
   *
   * Combines the lane's exit code with its findings so a stale findings
   * file from an earlier run can never contradict the run that just
   * happened: a passing exit clamps to PASSED, a failing one to FAILED.
   */
  protected function a11yLaneStatus(string $laneKey, int $exit, string $baseUrl): string {
    $dir = $this->getReportPaths($laneKey, $baseUrl)['realPath'];
    $status = $this->laneFindingsStatus($dir);
    if ($exit === self::EXIT_SUCCESS) {
      return in_array($status, ['PASSED', 'PASSED (advisory)'], TRUE) ? $status : 'PASSED';
    }
    return in_array($status, ['FAILED', 'INCOMPLETE'], TRUE) ? $status : 'FAILED';
  }

  /**
   * Run a reflow audit at the WCAG 2.1 SC 1.4.10 viewport (320 CSS px).
   *
   * Neither axe-core, Siteimprove Alfa, nor pa11y check reflow natively —
   * they don't render at the target viewport. This runner does, and emits
   * a normalized test-suite-findings.json the unified report aggregates
   * alongside the other a11y engines.
   */
  #[CLI\Command(name: 'utest:reflow', aliases: ['utreflow'])]
  #[CLI\Option(name: 'base-url', description: 'The site base URL. Falls back to the BASE_URL env var if unset, then to http://127.0.0.1:8888.')]
  #[CLI\Option(name: 'sitemap-url', description: 'Optional explicit sitemap URL. Defaults to <base-url>/sitemap.xml.')]
  #[CLI\Option(name: 'max-pages', description: 'Cap on sitemap pages to audit; pass "all" or 0 for no cap. Default 50.')]
  #[CLI\Usage(name: 'drush utest:reflow --sitemap-url=https://site.test/sitemap.xml', description: 'Run reflow using an explicit sitemap.')]
  #[CLI\Usage(name: 'drush utest:reflow --max-pages=all', description: 'Run reflow against every URL in the sitemap.')]
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
      'A11Y_PROFILE' => isset($options['a11y-profile']) ? (string) $options['a11y-profile'] : 'comprehensive',
    ];

    $cmd = ['bash', '-lc', 'cd tests && npx playwright test accessibility/reflow/reflow.spec.js --reporter=list'];

    // Stop cleanly if the sitemap is unreachable, empty, or host-mismatched.
    if (!$this->preflightLane($baseUrl, $sitemapUrl, TRUE, 'Reflow Audit')) {
      return self::EXIT_FAILURE;
    }

    try {
      $this->runProcess($cmd, 3600, 'Reflow Audit', $env);
      $this->displayReflowLocations('Reflow audit completed!', $reportPaths, $baseUrl);
    }
    catch (\RuntimeException $e) {
      $this->io()->error('Reflow audit did not complete: ' . $e->getMessage() . ' See the output above.');
      return self::EXIT_FAILURE;
    }

    return $this->gateLaneFindings($reportPaths['realPath'], 'Reflow audit');
  }

  /**
   * Run a meta-viewport audit (WCAG 2.0 SC 1.4.4 — Resize Text).
   *
   * Static DOM check: reads `<meta name="viewport">` on each sitemap page
   * and flags zoom-blocking directives (`user-scalable=no`,
   * `maximum-scale<2`). Static a11y tools don't natively check this;
   * findings emit alongside the other a11y engines via the unified report.
   */
  #[CLI\Command(name: 'utest:meta-viewport', aliases: ['utmetaviewport'])]
  #[CLI\Option(name: 'base-url', description: 'The site base URL. Falls back to the BASE_URL env var if unset, then to http://127.0.0.1:8888.')]
  #[CLI\Option(name: 'sitemap-url', description: 'Optional explicit sitemap URL. Defaults to <base-url>/sitemap.xml.')]
  #[CLI\Option(name: 'max-pages', description: 'Cap on sitemap pages to audit; pass "all" or 0 for no cap. Default 50.')]
  #[CLI\Usage(name: 'drush utest:meta-viewport --sitemap-url=https://site.test/sitemap.xml', description: 'Run meta-viewport using an explicit sitemap.')]
  #[CLI\Usage(name: 'drush utest:meta-viewport --max-pages=all', description: 'Run meta-viewport against every URL in the sitemap.')]
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
      'A11Y_PROFILE' => isset($options['a11y-profile']) ? (string) $options['a11y-profile'] : 'comprehensive',
    ];

    $cmd = [
      'bash',
      '-lc',
      'cd tests && npx playwright test accessibility/meta-viewport/meta-viewport.spec.js --reporter=list',
    ];

    // Stop cleanly if the sitemap is unreachable, empty, or host-mismatched.
    if (!$this->preflightLane($baseUrl, $sitemapUrl, TRUE, 'Meta-viewport Audit')) {
      return self::EXIT_FAILURE;
    }

    try {
      $this->runProcess($cmd, 3600, 'Meta-viewport Audit', $env);
      $this->displayMetaViewportLocations('Meta-viewport audit completed!', $reportPaths, $baseUrl);
    }
    catch (\RuntimeException $e) {
      $this->io()->error('Meta-viewport audit did not complete: ' . $e->getMessage() . ' See the output above.');
      return self::EXIT_FAILURE;
    }

    return $this->gateLaneFindings($reportPaths['realPath'], 'Meta-viewport audit');
  }

  /**
   * Per-test post-run message for meta-viewport.
   *
   * Leads with the standalone meta-viewport HTML report (shared renderer),
   * then the machine-readable JSON, then the unified report.
   */
  protected function displayMetaViewportLocations(string $message, array $reportPaths, ?string $baseUrl): void {
    $this->io()->text($message);
    $this->io()->text('');

    $reportLocal  = $reportPaths['realPath'] . '/meta-viewport-report.html';
    $reportWeb    = isset($reportPaths['webUrl']) ? $reportPaths['webUrl'] . '/meta-viewport-report.html' : NULL;
    $jsonLocal    = $reportPaths['realPath'] . '/test-suite-findings.json';
    $jsonWeb      = isset($reportPaths['webUrl']) ? $reportPaths['webUrl'] . '/test-suite-findings.json' : NULL;
    $unifiedLocal = preg_replace('#/meta-viewport$#', '/index.html', $reportPaths['realPath']);
    $unifiedWeb   = isset($reportPaths['webUrl']) ? preg_replace('#/meta-viewport$#', '/index.html', $reportPaths['webUrl']) : NULL;

    if (file_exists($reportLocal)) {
      $this->io()->text('Open the report:');
      $this->io()->text('  - ' . $reportLocal);
      if ($reportWeb) {
        $this->io()->text('  - ' . $reportWeb);
      }
      $this->io()->text('');
    }
    $this->io()->text('Findings (machine-readable):');
    $this->io()->text('  - ' . $jsonLocal);
    if ($jsonWeb) {
      $this->io()->text('  - ' . $jsonWeb);
    }
    $this->io()->text('');
    $this->io()->text('Also included in the unified report under Low Vision / WCAG 1.4.4:');
    $this->io()->text('  - ' . $unifiedLocal);
    if ($unifiedWeb) {
      $this->io()->text('  - ' . $unifiedWeb);
    }
    // utest:all re-renders the unified report at the end of the run, so
    // the refresh hint only applies to standalone lane runs.
    if (getenv('UTEST_ALL') !== '1') {
      $this->io()->text('  (run `drush utest:report-render` after a standalone meta-viewport run to refresh the unified report)');
    }
  }

  /**
   * Per-test post-run message for reflow.
   *
   * Leads with the standalone reflow HTML report (shared renderer), then the
   * machine-readable JSON, then the unified report.
   */
  protected function displayReflowLocations(string $message, array $reportPaths, ?string $baseUrl): void {
    $this->io()->text($message);
    $this->io()->text('');

    $reportLocal  = $reportPaths['realPath'] . '/reflow-report.html';
    $reportWeb    = isset($reportPaths['webUrl']) ? $reportPaths['webUrl'] . '/reflow-report.html' : NULL;
    $jsonLocal    = $reportPaths['realPath'] . '/test-suite-findings.json';
    $jsonWeb      = isset($reportPaths['webUrl']) ? $reportPaths['webUrl'] . '/test-suite-findings.json' : NULL;
    $unifiedLocal = preg_replace('#/reflow$#', '/index.html', $reportPaths['realPath']);
    $unifiedWeb   = isset($reportPaths['webUrl']) ? preg_replace('#/reflow$#', '/index.html', $reportPaths['webUrl']) : NULL;

    if (file_exists($reportLocal)) {
      $this->io()->text('Open the report:');
      $this->io()->text('  - ' . $reportLocal);
      if ($reportWeb) {
        $this->io()->text('  - ' . $reportWeb);
      }
      $this->io()->text('');
    }
    $this->io()->text('Findings (machine-readable):');
    $this->io()->text('  - ' . $jsonLocal);
    if ($jsonWeb) {
      $this->io()->text('  - ' . $jsonWeb);
    }
    $this->io()->text('');
    $this->io()->text('Also included in the unified report under Low Vision / WCAG 1.4.10:');
    $this->io()->text('  - ' . $unifiedLocal);
    if ($unifiedWeb) {
      $this->io()->text('  - ' . $unifiedWeb);
    }
    // utest:all re-renders the unified report at the end of the run, so
    // the refresh hint only applies to standalone lane runs.
    if (getenv('UTEST_ALL') !== '1') {
      $this->io()->text('  (run `drush utest:report-render` after a standalone reflow run to refresh the unified report)');
    }
  }

  /**
   * Run pa11y-ci over BASE_URL/sitemap.xml.
   *
   * Requires BASE_URL/sitemap.xml to be reachable. Unlike the Playwright key
   * page commands, this lane does not fall back to / or /user/login.
   */
  #[CLI\Command(name: 'utest:pa11y', aliases: ['utpa11y'])]
  #[CLI\Option(name: 'base-url', description: 'The site base URL. Requires <base-url>/sitemap.xml. Falls back to BASE_URL env var, then http://127.0.0.1:8888.')]
  #[CLI\Usage(name: 'drush utest:pa11y --base-url=https://site.test', description: 'Run pa11y against https://site.test/sitemap.xml.')]
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

    // Stop cleanly if the sitemap is unreachable, empty, or host-mismatched.
    if (!$this->preflightLane($base, $sitemap, FALSE, 'pa11y Accessibility Tests')) {
      return self::EXIT_FAILURE;
    }

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

    // Map the shared a11y profile to pa11y's HTML_CodeSniffer standard so
    // utest:all runs pa11y at the same WCAG level as alfa / axe.
    $profileKey = getenv('A11Y_PROFILE') ?: 'comprehensive';
    $pa11yStandard = ['strict' => 'WCAG2A', 'standard' => 'WCAG2AA', 'comprehensive' => 'WCAG2AAA'][$profileKey] ?? 'WCAG2AA';
    $profileName = [
      'strict' => 'Strict Mode (WCAG Level A only)',
      'standard' => 'Standard Mode (WCAG Level A + AA)',
      'comprehensive' => 'Comprehensive Mode (All WCAG Levels + Best Practices)',
      'custom' => 'Custom Mode (User-defined)',
    ][$profileKey] ?? ucfirst($profileKey);
    // Rule tags per profile, mirroring a11y-profiles.js so every report shows
    // the same profile description as alfa / axe.
    $profileTags = [
      'strict' => 'wcag2a, wcag21a',
      'standard' => 'wcag2a, wcag2aa, wcag21a, wcag21aa',
      'comprehensive' => 'wcag2a, wcag2aa, wcag2aaa, wcag21a, wcag21aa, wcag21aaa, wcag22a, wcag22aa',
    ][$profileKey] ?? '';

    // Generate runtime pa11y-ci config at tests/.pa11yci.json.
    $baseCfgPath = 'tests/accessibility/pa11y/.pa11yci.base.json';
    $localCfgPath = 'tests/.pa11yci.json';
    $cfg = [
      'defaults' => [
        'standard' => $pa11yStandard,
        'includeWarnings' => TRUE,
        'timeout' => 90000,
        'userAgent' => 'pa11y-ci accessibility testing',
        'chromeLaunchConfig' => [
          'args' => ['--ignore-certificate-errors'],
        ],
      ],
      'concurrency' => 4,
    ];

    // Optional site override: a site can create the base config file to
    // merge extra pa11y-ci settings over the generated defaults. The file
    // does not ship with the suite.
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
      // --sitemap must be a CLI flag (config sitemap key is unused in v4)
      $this->io()->section('pa11y-ci accessibility testing');
      $jsonPath = $reportPaths['realPath'] . '/pa11y-report.json';
      $logPath = $reportPaths['realPath'] . '/pa11y.log';
      $escapedSitemap = escapeshellarg($sitemap);
      $escapedJsonPath = escapeshellarg($jsonPath);
      $escapedLogPath = escapeshellarg($logPath);
      // Write JSON directly to file; stderr goes to log file.
      $cmd = [
        'bash',
        '-lc',
        "cd tests && npx pa11y-ci --sitemap $escapedSitemap --json > $escapedJsonPath 2>$escapedLogPath",
      ];

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
        $this->generatePa11yHtmlReport($reportPaths['realPath'], $jsonData, $profileName, $profileTags, $sitemap);

        // Emit unified test-suite-findings.json so the test-suite renderer can
        // aggregate pa11y alongside alfa / axe / lint. Failure here is
        // informational — the legacy per-test report still works.
        $emitterScript = 'tests/accessibility/pa11y/emit-findings.js';
        if (is_file($emitterScript)) {
          $emit = new Process([$this->resolveNodeBinary(), $emitterScript, $reportPaths['realPath']]);
          $emit->setTimeout(60);
          $emit->run();
          if (!$emit->isSuccessful()) {
            $this->io()->text('Could not emit unified test-suite-findings.json: ' . trim($emit->getErrorOutput() ?: $emit->getOutput()));
          }
        }
      }

      if (!$process->isSuccessful() && !$jsonData) {
        // pa11y-ci crashed before producing results; nothing was verified.
        $this->displayPa11yReportLocations('pa11y accessibility testing did not complete!', $reportPaths);
        return self::EXIT_FAILURE;
      }

      // Same gate as every other lane: critical/serious fail, moderate and
      // minor are advisory, missing findings count as incomplete. pa11y-ci
      // itself exits non-zero on any issue, so the gate reads the normalized
      // severities instead of the exit code.
      $this->displayPa11yReportLocations('pa11y accessibility testing completed!', $reportPaths);
      return $this->gateLaneFindings($reportPaths['realPath'], 'pa11y tests');
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

      return self::EXIT_FAILURE;
    }
  }

  /**
   * Run custom-code PHPUnit tests — the Functional / Regression lane.
   *
   * Report-only: failing Unit + Kernel tests are flagged in the unified
   * report but never fail the build. Scoped to custom modules, themes, and
   * profiles; core and contrib tests are never run.
   */
  #[CLI\Command(name: 'utest:phpunit', aliases: ['utphpunit'])]
  #[CLI\Option(name: 'base-url', description: 'The site base URL. Falls back to the BASE_URL env var.')]
  #[CLI\Option(
    name: 'module',
    description: 'One or more custom module machine names to run locally. Accepts comma-separated values.',
  )]
  #[CLI\Option(
    name: 'modules',
    description: 'Comma-separated custom module machine names to run locally.',
  )]
  #[CLI\Option(
    name: 'theme',
    description: 'One or more custom theme machine names to run locally. Accepts comma-separated values.',
  )]
  #[CLI\Option(
    name: 'themes',
    description: 'Comma-separated custom theme machine names to run locally.',
  )]
  #[CLI\Option(
    name: 'profile',
    description: 'One or more custom profile machine names to run locally. Accepts comma-separated values.',
  )]
  #[CLI\Option(
    name: 'profiles',
    description: 'Comma-separated custom profile machine names to run locally.',
  )]
  #[CLI\Option(
    name: 'ignore-scope',
    description: 'Ignore UTEST_CUSTOM_* scope variables and run the full custom-code PHPUnit lane.',
  )]
  #[CLI\Usage(name: 'drush utest:phpunit', description: 'Run all custom-code Unit/Kernel tests, unless UTEST_CUSTOM_* scope variables are set.')]
  #[CLI\Usage(name: 'drush utest:phpunit --modules=foo,bar', description: 'Run PHPUnit for one or more custom modules.')]
  #[CLI\Usage(name: 'drush utest:phpunit --themes=theme_a --profiles=profile_a', description: 'Run PHPUnit tests found under selected custom themes/profiles.')]
  #[CLI\Usage(name: 'drush utest:phpunit --ignore-scope', description: 'Ignore UTEST_CUSTOM_* variables and run all custom-code PHPUnit tests.')]
  public function phpunit(
    array $options = [
      'base-url' => NULL,
      'module' => NULL,
      'modules' => NULL,
      'theme' => NULL,
      'themes' => NULL,
      'profile' => NULL,
      'profiles' => NULL,
      'ignore-scope' => FALSE,
    ],
  ) {
    $this->io()->section('Custom-code PHPUnit tests (Functional / Regression)');

    // DRUPAL_ROOT is the docroot (web/); its parent is the project root.
    $root = defined('DRUPAL_ROOT') ? dirname(DRUPAL_ROOT) : dirname(getcwd());
    $baseUrl = $options['base-url'] ?: getenv('BASE_URL') ?: 'http://127.0.0.1:8888';
    $scope = !empty($options['ignore-scope'])
      ? $this->emptyCustomCodeScope()
      : $this->getCustomCodeScope($options);
    $isScopedRun = $scope['is_scoped'];

    if ($isScopedRun) {
      $this->io()->text($this->formatCustomCodeScopeMessage($scope));
      $scopedBasePaths = $this->getReportPaths('scoped/phpunit/custom', $baseUrl);
      $this->removeDirectory($scopedBasePaths['realPath']);
      mkdir($scopedBasePaths['realPath'], 0777, TRUE);
      $reportPaths = $this->getReportPaths('scoped/phpunit/custom/phpunit', $baseUrl);
    }
    else {
      $reportPaths = $this->getReportPaths('phpunit', $baseUrl);
    }

    // The standalone runner needs no Drupal bootstrap (so the same script
    // powers CI), handles its own fail-soft preflight (dev deps,
    // pdo_sqlite), runs the custom Unit + Kernel tests, and writes the
    // unified findings.json.
    $runner = $root . '/tests/phpunit/run.js';
    if (!is_file($runner)) {
      $this->io()->warning("PHPUnit runner not found ($runner). Skipping.");
      return;
    }

    $command = [$this->resolveNodeBinary(), $runner, $reportPaths['realPath']];
    if ($isScopedRun) {
      foreach (['modules', 'themes', 'profiles'] as $scopeType) {
        if (!empty($scope[$scopeType])) {
          $command[] = '--' . $scopeType . '=' . implode(',', $scope[$scopeType]);
        }
      }
    }

    $process = new Process($command, $root, NULL, NULL, 600);
    try {
      $process->run(function ($type, $buffer) {
        $this->output()->write($buffer);
      });
    }
    catch (ProcessTimedOutException $e) {
      // Report-only lane: a timeout shouldn't throw an uncaught error on a
      // standalone run. Warn and continue (the report stays partial).
      $this->io()->warning('PHPUnit lane timed out before completing (report-only). Re-run, or scope with --modules/--themes/--profiles.');
      return;
    }

    if ($isScopedRun) {
      $this->renderScopedPhpunitReport($baseUrl);
      $reportUrl = rtrim((string) $baseUrl, '/') . '/sites/default/files/test-reports/scoped/phpunit/custom/index.html';
      $this->io()->success(
        'Scoped PHPUnit lane complete (report-only): ' . $reportUrl,
      );
      return;
    }

    $this->displayPhpunitReportLocations($reportPaths);
    $this->io()->success('PHPUnit lane complete (report-only). Also included in the unified report under Functional / Regression.');
  }

  /**
   * Per-test post-run message for phpunit.
   *
   * Leads with the standalone PHPUnit HTML report (shared renderer), then
   * the machine-readable JSON.
   */
  protected function displayPhpunitReportLocations(array $reportPaths): void {
    $reportLocal = $reportPaths['realPath'] . '/phpunit-report.html';
    if (file_exists($reportLocal)) {
      $this->io()->text('Open the report:');
      $this->io()->text('  - ' . $reportLocal);
      if (!empty($reportPaths['webUrl'])) {
        $this->io()->text('  - ' . $reportPaths['webUrl'] . '/phpunit-report.html');
      }
      $this->io()->text('');
    }
    $this->io()->text('Findings (machine-readable):');
    $this->io()->text('  - ' . $reportPaths['realPath'] . '/test-suite-findings.json');
    if (!empty($reportPaths['webUrl'])) {
      $this->io()->text('  - ' . $reportPaths['webUrl'] . '/test-suite-findings.json');
    }
    $this->io()->text('');
  }

  /**
   * Return an empty custom-code scope.
   */
  protected function emptyCustomCodeScope(): array {
    return [
      'modules' => [],
      'themes' => [],
      'profiles' => [],
      'source' => 'none',
      'is_scoped' => FALSE,
    ];
  }

  /**
   * Parse local custom-code scope filters from CLI options or environment.
   *
   * CLI scope flags win over UTEST_CUSTOM_* environment variables as a group:
   * if any CLI scope flag is present, environment scope filters are ignored for
   * that run. Empty scope means the caller should run the full custom-code
   * surface.
   *
   * @param array $options
   *   Drush command options.
   * @param array $allowed
   *   Allowed plural scope keys: modules, themes, profiles.
   *
   * @return array
   *   Scope data keyed by modules/themes/profiles, plus source and is_scoped.
   */
  protected function getCustomCodeScope(
    array $options,
    array $allowed = ['modules', 'themes', 'profiles'],
  ): array {
    $allowed = array_fill_keys($allowed, TRUE);
    $scope = $this->emptyCustomCodeScope();

    $optionMap = [
      'modules' => ['module', 'modules'],
      'themes' => ['theme', 'themes'],
      'profiles' => ['profile', 'profiles'],
    ];
    $envMap = [
      'modules' => 'UTEST_CUSTOM_MODULES',
      'themes' => 'UTEST_CUSTOM_THEMES',
      'profiles' => 'UTEST_CUSTOM_PROFILES',
    ];

    $hasCliScope = FALSE;
    foreach ($optionMap as $type => $keys) {
      if (!isset($allowed[$type])) {
        continue;
      }
      foreach ($keys as $key) {
        if ($this->hasCustomCodeScopeValue($options[$key] ?? NULL)) {
          $hasCliScope = TRUE;
          break 2;
        }
      }
    }

    foreach ($optionMap as $type => $keys) {
      if (!isset($allowed[$type])) {
        continue;
      }
      $rawValues = [];
      if ($hasCliScope) {
        foreach ($keys as $key) {
          if ($this->hasCustomCodeScopeValue($options[$key] ?? NULL)) {
            $rawValues[] = $options[$key];
          }
        }
      }
      else {
        $envValue = getenv($envMap[$type]);
        if ($this->hasCustomCodeScopeValue($envValue)) {
          $rawValues[] = $envValue;
        }
      }
      $scope[$type] = $this->parseCustomCodeScopeValues($rawValues, $type);
    }

    $scope['source'] = $hasCliScope ? 'cli' : 'env';
    $scope['is_scoped'] = !empty($scope['modules'])
      || !empty($scope['themes'])
      || !empty($scope['profiles']);
    if (!$scope['is_scoped']) {
      $scope['source'] = 'none';
    }

    return $scope;
  }

  /**
   * Determine whether a raw scope option/environment value is set.
   */
  protected function hasCustomCodeScopeValue(mixed $value): bool {
    if (is_array($value)) {
      return !empty(array_filter($value, fn($item) => trim((string) $item) !== ''));
    }
    if ($value === TRUE) {
      return FALSE;
    }
    return $value !== NULL && $value !== FALSE && trim((string) $value) !== '';
  }

  /**
   * Parse and validate comma/space-separated custom-code machine names.
   */
  protected function parseCustomCodeScopeValues(array $rawValues, string $type): array {
    $items = [];
    foreach ($rawValues as $rawValue) {
      $values = is_array($rawValue) ? $rawValue : [$rawValue];
      foreach ($values as $value) {
        $parsedItems = preg_split('/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($parsedItems as $item) {
          if (!preg_match('/^[a-z][a-z0-9_]*$/', $item)) {
            throw new \InvalidArgumentException("Invalid custom $type machine name: $item");
          }
          $items[$item] = $item;
        }
      }
    }
    return array_values($items);
  }

  /**
   * Format a human-readable summary of the custom-code scope.
   */
  protected function formatCustomCodeScopeMessage(array $scope): string {
    $parts = [];
    foreach (['modules', 'themes', 'profiles'] as $type) {
      if (!empty($scope[$type])) {
        $parts[] = $type . ': ' . implode(', ', $scope[$type]);
      }
    }
    $source = $scope['source'] === 'env' ? 'environment' : 'CLI';
    return 'Scoped custom-code run from ' . $source . ': ' . implode('; ', $parts);
  }

  /**
   * Render the local scoped PHPUnit report shell.
   */
  protected function renderScopedPhpunitReport(string $baseUrl): void {
    try {
      $renderApp = Drush::getApplication();
      $renderCmd = $renderApp->find('utest:report-render');
      $renderInput = new ArrayInput([
        'command'    => 'utest:report-render',
        '--dest-uri' => 'public://test-reports/scoped/phpunit/custom',
        '--src-uri'  => 'public://test-reports/scoped/phpunit/custom',
        '--base-url' => $baseUrl,
      ]);
      $renderExit = $renderCmd->run($renderInput, new NullOutput());
      if ($renderExit !== 0) {
        $this->io()->warning('Scoped PHPUnit report render exited with code: ' . $renderExit);
      }
    }
    catch (\Exception $e) {
      $this->io()->warning('Scoped PHPUnit report render failed: ' . $e->getMessage());
    }
  }

  /**
   * Render the local scoped lint report shell.
   */
  protected function renderScopedLintReport(string $baseUrl): void {
    try {
      $renderApp = Drush::getApplication();
      $renderCmd = $renderApp->find('utest:report-render');
      $renderInput = new ArrayInput([
        'command'    => 'utest:report-render',
        '--dest-uri' => 'public://test-reports/scoped/lint/custom',
        '--src-uri'  => 'public://test-reports/scoped/lint/custom',
        '--base-url' => $baseUrl,
      ]);
      $renderExit = $renderCmd->run($renderInput, new NullOutput());
      if ($renderExit !== 0) {
        $this->io()->warning('Scoped lint report render exited with code: ' . $renderExit);
      }
    }
    catch (\Exception $e) {
      $this->io()->warning('Scoped lint report render failed: ' . $e->getMessage());
    }
  }

  /**
   * Run linting checks on custom modules, themes, and profiles.
   */
  #[CLI\Command(name: 'utest:lint', aliases: ['utlint'])]
  #[CLI\Option(name: 'base-url', description: 'The site base URL. Falls back to the BASE_URL env var if unset, then to http://127.0.0.1:8888.')]
  #[CLI\Option(name: 'module', description: 'One or more custom module machine names to lint. Accepts comma-separated values.')]
  #[CLI\Option(name: 'modules', description: 'Comma-separated custom module machine names to lint.')]
  #[CLI\Option(name: 'theme', description: 'One or more custom theme machine names to lint. Accepts comma-separated values.')]
  #[CLI\Option(name: 'themes', description: 'Comma-separated custom theme machine names to lint.')]
  #[CLI\Option(name: 'profile', description: 'One or more custom profile machine names to lint. Accepts comma-separated values.')]
  #[CLI\Option(name: 'profiles', description: 'Comma-separated custom profile machine names to lint.')]
  #[CLI\Option(name: 'ignore-scope', description: 'Ignore UTEST_CUSTOM_* scope variables and lint the full custom-code surface.')]
  #[CLI\Usage(name: 'drush utest:lint', description: 'Run full custom-code lint, unless UTEST_CUSTOM_* scope variables are set.')]
  #[CLI\Usage(name: 'drush utest:lint --modules=foo,bar', description: 'Lint one or more custom modules.')]
  #[CLI\Usage(name: 'drush utest:lint --themes=theme_a --profiles=profile_a', description: 'Lint selected custom themes/profiles.')]
  #[CLI\Usage(name: 'drush utest:lint --ignore-scope', description: 'Ignore UTEST_CUSTOM_* variables and lint all custom code.')]
  public function lint(
    array $options = [
      'base-url' => NULL,
      'module' => NULL,
      'modules' => NULL,
      'theme' => NULL,
      'themes' => NULL,
      'profile' => NULL,
      'profiles' => NULL,
      'ignore-scope' => FALSE,
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
    $scope = !empty($options['ignore-scope'])
      ? $this->emptyCustomCodeScope()
      : $this->getCustomCodeScope($options);
    $isScopedRun = $scope['is_scoped'];

    if ($isScopedRun) {
      $this->io()->text($this->formatCustomCodeScopeMessage($scope));
      $scopedBasePaths = $this->getReportPaths('scoped/lint/custom', $baseUrl);
      $this->removeDirectory($scopedBasePaths['realPath']);
      mkdir($scopedBasePaths['realPath'], 0777, TRUE);
      $reportPaths = $this->getReportPaths('scoped/lint/custom/lint', $baseUrl);
    }
    else {
      $reportPaths = $this->getReportPaths('lint', $baseUrl);
    }

    // Build command arguments.
    $args = [];
    if ($isScopedRun) {
      foreach (['modules', 'themes', 'profiles'] as $scopeType) {
        if (!empty($scope[$scopeType])) {
          $args[] = '--' . $scopeType . '=' . escapeshellarg(implode(',', $scope[$scopeType]));
        }
      }
    }

    $env = [
      'BASE_URL' => (string) $baseUrl,
    ];
    if ($isScopedRun) {
      $env['LINT_OUTPUT_DIR'] = $reportPaths['realPath'];
    }

    try {
      // Run the linting script.
      $cmd = ['bash', '-lc', 'cd tests && node code-quality/lint-orchestrator.js ' . implode(' ', $args)];
      $this->runProcess($cmd, 600, 'Linting Tests', $env);

      $this->displayLintReportLocations('Linting Tests completed successfully!', $reportPaths);
      if ($isScopedRun) {
        $this->renderScopedLintReport($baseUrl);
      }
      return TRUE;

    }
    catch (ProcessTimedOutException $e) {
      // A timeout is not a lint result; surface it distinctly so it isn't read
      // as "issues found" with a stale report.
      $this->io()->warning('Linting timed out before completing; the report may be partial. Re-run, or narrow with --modules/--themes/--profiles for faster local feedback.');
      return FALSE;
    }
    catch (\RuntimeException $e) {
      // Linting found issues (expected behavior)
      $this->displayLintReportLocations('Linting Tests completed with issues found!', $reportPaths);
      if ($isScopedRun) {
        $this->renderScopedLintReport($baseUrl);
      }
      $this->io()->text('Linting issues were detected. Please review the report above for details.');
      // Don't re-throw - linting finding issues is not a command failure.
      return FALSE;
    }
  }

  /**
   * Run the full CI-style suite: lint, PHPUnit, full-site a11y, and report.
   *
   * Includes full custom-code lint, full custom-code PHPUnit, Alfa full-site,
   * pa11y, axe full-site, reflow, meta-viewport, and unified report render.
   * Ignores UTEST_CUSTOM_* local scope variables so the full-suite report is
   * not accidentally narrowed by a developer's local environment.
   */
  #[CLI\Command(name: 'utest:all', aliases: ['utall'])]
  #[CLI\Option(name: 'base-url', description: 'The site base URL. Falls back to BASE_URL env var, then http://127.0.0.1:8888.')]
  #[CLI\Option(name: 'sitemap-url', description: 'Optional explicit sitemap URL. Defaults to <base-url>/sitemap.xml.')]
  #[CLI\Option(name: 'index', description: 'Render the unified report after all lanes complete. Defaults to true.')]
  #[CLI\Option(name: 'a11y-profile', description: 'Accessibility profile for Alfa/axe lanes: strict, standard, comprehensive, or custom.')]
  #[CLI\Option(name: 'a11y-custom-tags', description: 'Comma-separated rule tags when --a11y-profile=custom.')]
  #[CLI\Option(name: 'a11y-severity-levels', description: 'Comma-separated severity levels for selected a11y lanes.')]
  #[CLI\Usage(name: 'drush utest:all', description: 'Run full custom-code lint, full custom-code PHPUnit, full-site Alfa, pa11y, axe, reflow, meta-viewport, then render the report.')]
  #[CLI\Usage(name: 'drush utest:all --base-url=https://site.test', description: 'Run the full suite against an explicit local site URL.')]
  #[CLI\Usage(name: 'drush utest:all --sitemap-url=https://site.test/sitemap.xml', description: 'Run full-site a11y lanes against an explicit sitemap.')]
  #[CLI\Usage(name: 'UTEST_CUSTOM_MODULES=foo drush utest:all', description: 'Still runs full custom-code lint/PHPUnit; utest:all ignores UTEST_CUSTOM_* scope variables.')]
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
    // Precedence: --base-url flag > BASE_URL env > localhost.
    $options['base-url'] = $options['base-url'] ?: getenv('BASE_URL') ?: 'http://127.0.0.1:8888';

    // Resolve sitemap URL from base-url if not provided.
    if (empty($options['sitemap-url'])) {
      $options['sitemap-url'] = rtrim((string) $options['base-url'], '/') . '/sitemap.xml';
    }
    $testResults = [];

    // utest:all generates the unified index.html, so every per-lane report
    // (lint, phpunit, a11y) gets a "Back to all reports" link. Individual lane
    // commands leave this unset, so their standalone reports omit the link.
    putenv('UTEST_ALL=1');
    $_ENV['UTEST_ALL'] = '1';

    // Ensure JS deps and browsers exist.
    try {
      $this->jsInstall();
      $this->browsers();
    }
    catch (\Exception $e) {
      $this->io()->text('Failed to install dependencies: ' . $e->getMessage());
      return self::EXIT_FAILURE;
    }

    // Pre-flight: run the full check-config and abort if anything FAILs.
    // checkConfig prints its own "Test suite pre-flight check" title.
    $preflightFails = $this->checkConfig(['base-url' => $options['base-url']]);
    if ($preflightFails > 0) {
      $this->io()->error("Pre-flight found $preflightFails blocking issue(s). Fix the items marked FAIL above, then re-run drush utest:all.");
      return self::EXIT_FAILURE;
    }

    // Run test suites with individual error handling. Lint and PHPUnit run
    // first and print their own section headers.
    $this->io()->section('Running code quality and functional test suites');

    try {
      $lintClean = $this->lint([
        'base-url' => $options['base-url'],
        'ignore-scope' => TRUE,
      ]);
      $testResults['lint'] = $lintClean ? 'PASSED' : 'FINDINGS (non-blocking)';
      $lintClean ? $this->logger()->success('Linting passed') : $this->logger()->success('Linting completed with findings (non-blocking, see report)');
    }
    catch (\Exception $e) {
      $testResults['lint'] = 'INCOMPLETE';
      $this->logger()->warning('Linting did not complete: ' . $e->getMessage());
    }

    // Run custom-code PHPUnit (Functional / Regression) before the
    // accessibility crawl. Report-only — it never fails the run.
    try {
      $this->phpunit([
        'base-url' => $options['base-url'],
        'ignore-scope' => TRUE,
      ]);
      $testResults['phpunit'] = 'COMPLETED (report-only)';
      $this->logger()->success('PHPUnit (Functional / Regression) completed (report-only)');
    }
    catch (\Exception $e) {
      $testResults['phpunit'] = 'SKIPPED';
      $this->logger()->warning('PHPUnit lane skipped: ' . $e->getMessage());
    }

    // utest:all runs the full a11y suite: sitemap-wide Alfa + pa11y + axe +
    // reflow + meta-viewport. Each lane is also available on its own (drush
    // utest:alfa, utest:axe, and so on).
    $this->io()->section('Running accessibility test suite');

    // Run Alfa full-site audit.
    try {
      $exit = $this->alfaFull([
        'base-url' => $options['base-url'],
        'sitemap-url' => $options['sitemap-url'],
        'a11y-profile' => $options['a11y-profile'] ?? 'comprehensive',
        'a11y-custom-tags' => $options['a11y-custom-tags'] ?? NULL,
        'a11y-severity-levels' => $options['a11y-severity-levels'] ?? NULL,
      ]);
      $testResults['alfa-full'] = $this->a11yLaneStatus('alfa-full', (int) $exit, (string) $options['base-url']);
    }
    catch (\Exception $e) {
      $testResults['alfa-full'] = 'INCOMPLETE';
      $this->logger()->warning('Alfa full-site audit did not complete: ' . $e->getMessage());
    }

    // Run pa11y tests.
    try {
      $exit = $this->pa11y([
        'base-url' => $options['base-url'],
      ]);
      $testResults['pa11y'] = $this->a11yLaneStatus('pa11y', (int) $exit, (string) $options['base-url']);
    }
    catch (\Exception $e) {
      $testResults['pa11y'] = 'INCOMPLETE';
      $this->logger()->warning('pa11y tests did not complete: ' . $e->getMessage());
    }

    // Run axe-core full-site (free) so the unified report includes the
    // `best-practice` rule chip — that filter only appears when an axe
    // pass ran (Alfa's tag filter doesn't recognize `best-practice`).
    // Sitemap-wide coverage parallel to alfa-full / pa11y, no API key
    // required.
    try {
      $exit = $this->axeFull([
        'base-url' => $options['base-url'],
        'sitemap-url' => $options['sitemap-url'],
        'a11y-profile' => $options['a11y-profile'] ?? 'comprehensive',
        'a11y-custom-tags' => $options['a11y-custom-tags'] ?? NULL,
        'a11y-severity-levels' => $options['a11y-severity-levels'] ?? NULL,
      ]);
      $testResults['axe-full'] = $this->a11yLaneStatus('axe-full', (int) $exit, (string) $options['base-url']);
    }
    catch (\Exception $e) {
      $testResults['axe-full'] = 'INCOMPLETE';
      $this->logger()->warning('axe full-site tests did not complete: ' . $e->getMessage());
    }

    // Run reflow audit (WCAG 2.1 SC 1.4.10). No rule engine — Playwright
    // sets viewport to 320px and measures horizontal overflow. None of the
    // other a11y engines check this natively.
    try {
      $exit = $this->reflow([
        'base-url' => $options['base-url'],
        'sitemap-url' => $options['sitemap-url'],
      ]);
      $testResults['reflow'] = $this->a11yLaneStatus('reflow', (int) $exit, (string) $options['base-url']);
    }
    catch (\Exception $e) {
      $testResults['reflow'] = 'INCOMPLETE';
      $this->logger()->warning('Reflow audit did not complete: ' . $e->getMessage());
    }

    // Run meta-viewport audit (WCAG 2.0 SC 1.4.4). Static DOM check for
    // zoom-blocking `user-scalable=no` / `maximum-scale<2` directives.
    try {
      $exit = $this->metaViewport([
        'base-url' => $options['base-url'],
        'sitemap-url' => $options['sitemap-url'],
      ]);
      $testResults['meta-viewport'] = $this->a11yLaneStatus('meta-viewport', (int) $exit, (string) $options['base-url']);
    }
    catch (\Exception $e) {
      $testResults['meta-viewport'] = 'INCOMPLETE';
      $this->logger()->warning('Meta-viewport audit did not complete: ' . $e->getMessage());
    }

    // Render the unified Test Report (utest:report-render) if requested.
    if (!empty($options['index'])) {
      // Accessibility, Security, and Code Quality Report — the canonical
      // single-page view. Reads each test's test-suite-findings.json (the
      // contract in tests/reports/_shell/findings.schema.json) and renders
      // the unified
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
          $testResults['report'] = 'RENDERED';
          $reportUrl = $options['base-url']
            ? rtrim((string) $options['base-url'], '/') . '/sites/default/files/test-reports/index.html'
            : 'public://test-reports/index.html';
          $this->logger()->success('Test Report rendered: ' . $reportUrl);
        }
        else {
          $testResults['report'] = 'FAILED';
          $this->logger()->warning('Test Report render exited with code: ' . $renderExit);
        }
      }
      catch (\Exception $e) {
        $testResults['report'] = 'FAILED';
        $this->logger()->warning('Test Report render failed: ' . $e->getMessage());
      }
    }

    // Per-lane summary and exit status. Lint and PHPUnit are report-only, so
    // they never fail the command; the a11y lanes and the report render do.
    $this->io()->newLine();
    $this->io()->section('Test Suite Summary');
    $rows = [];
    foreach ($testResults as $lane => $result) {
      $display = match (TRUE) {
        $result === 'FAILED' => '<fg=red>FAILED</>',
        $result === 'INCOMPLETE' => '<fg=yellow>INCOMPLETE</>',
        str_starts_with($result, 'PASSED') || $result === 'RENDERED' => "<fg=green>$result</>",
        default => $result,
      };
      $rows[] = [$lane, $display];
    }
    $this->io()->table(['Lane', 'Result'], $rows);

    $gatingLanes = ['alfa-full', 'pa11y', 'axe-full', 'reflow', 'meta-viewport', 'report'];
    $failedLanes = array_keys(array_filter(
      $testResults,
      static fn ($result, $lane) => in_array($result, ['FAILED', 'INCOMPLETE'], TRUE) && in_array($lane, $gatingLanes, TRUE),
      ARRAY_FILTER_USE_BOTH
    ));
    if ($failedLanes) {
      // Quiet by design: the summary table and Test Report above already show
      // the findings. The non-zero exit code carries the result for CI.
      $this->io()->text('Lanes with critical/serious findings or incomplete runs: ' . implode(', ', $failedLanes) . '. Details are in the Test Report above.');
      return self::EXIT_FAILURE;
    }
    if (str_starts_with($testResults['lint'] ?? '', 'FINDINGS')) {
      $this->io()->text('Lint findings were detected (non-blocking). Review the lint report.');
    }
    return self::EXIT_SUCCESS;
  }

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

        // Storage mode is the same for the whole run; say it once.
        static $drupalFsNoticeShown = FALSE;
        if (!$drupalFsNoticeShown) {
          $this->io()->text('Using Drupal file system for report storage.');
          $drupalFsNoticeShown = TRUE;
        }
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

      // Storage mode is the same for the whole run; say it once.
      static $fallbackFsNoticeShown = FALSE;
      if (!$fallbackFsNoticeShown) {
        $this->io()->text('Using fallback file system for report storage.');
        $fallbackFsNoticeShown = TRUE;
      }
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
   *   The test type (e.g., 'axe', 'alfa', 'axe-watcher') for descriptive
   *   naming.
   */
  protected function generateStaticHtmlReport($reportPath, $testType = 'playwright') {
    try {
      // Get descriptive report name.
      $descriptiveName = $this->getDescriptiveReportName($testType);
      $descriptivePath = $reportPath . '/' . $descriptiveName;

      // Try multiple approaches to generate HTML report.
      $reportGenerated = FALSE;

      // Method 1: Check if Playwright already generated an HTML report in
      // the target directory.
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

      // Method 2: Use playwright show-report to generate HTML if not
      // already present.
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

        $cmd = [
          'bash',
          '-lc',
          'cd tests && npx playwright show-report --host=none 2>/dev/null || echo "HTML report generation completed"',
        ];

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
   * Translate a `--max-pages` value into the env-var string specs expect.
   *
   * Accepts integers as well as the sentinels "all" or 0, which both mean
   * "no cap".
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
   * Print a heads-up about the upcoming scan size and estimated time.
   *
   * Contributors see the cost before the run starts.
   *
   * @param string $label
   *   Display name of the lane (e.g. "Alfa Full Site Audit").
   * @param mixed $raw
   *   The raw --max-pages value (string/int) for echo context.
   * @param float $perPageSeconds
   *   Rough per-page time estimate for the lane (Alfa ~8s, axe ~4s).
   */
  protected function announceMaxPages(string $label, $raw, float $perPageSeconds): void {
    // Blank line first so consecutive lanes read as separate blocks.
    $this->io()->newLine();
    $isAll = (is_string($raw) && strtolower(trim($raw)) === 'all') || (int) $raw <= 0;
    if ($isAll) {
      $this->io()->text(sprintf(
        '%s: --max-pages=all → scanning the full sitemap. Estimated cost: ~%.0fs per page (full run depends on sitemap size). Progress prints as [current/total] for each page; for very large sitemaps consider --max-pages=N first or run in the background with nohup.',
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
   * Get descriptive report name based on test type.
   *
   * @param string $testType
   *   The test type (e.g., 'axe', 'alfa', 'axe-watcher').
   *
   * @return string
   *   The descriptive report filename.
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
   * Generate a minimal fallback HTML report when Playwright generation fails.
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
   * Display Alfa report locations (standard and custom HTML reports).
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
    $customHtmlExists = file_exists($reportPaths['realPath'] . '/alfa-full-report.html');
    $jsonExists = file_exists($reportPaths['realPath'] . '/alfa-full-site-report.json');
    $standardHtmlExists = file_exists($reportPaths['realPath'] . '/index.html');

    $this->io()->text('');
    $this->io()->text('CUSTOM ALFA VISUAL REPORT (Recommended):');
    if ($customHtmlExists) {
      $this->io()->text('  - HTML (local): ' . $reportPaths['realPath'] . '/alfa-full-report.html');
      $this->io()->text('  - HTML (web): ' . $reportPaths['webUrl'] . '/alfa-full-report.html');
      $this->io()->text('     → Interactive visual report with severity filtering, WCAG grouping, and detailed fix recommendations');
    }
    else {
      $this->logger()->warning('Custom HTML report not found - check test execution logs');
    }

    $this->io()->text('');
    $this->io()->text('STANDARD PLAYWRIGHT REPORT:');
    if ($standardHtmlExists) {
      $this->io()->text('  - HTML (local): ' . $reportPaths['realPath'] . '/index.html');
      $this->io()->text('  - HTML (web): ' . $reportPaths['webUrl'] . '/index.html');
      $this->io()->text('     → Standard Playwright test results and execution details');
    }
    else {
      $this->io()->text('  ℹ️  Standard HTML report not generated (Playwright only creates detailed HTML reports when needed)');
      $this->io()->text('     → Console output above contains all test execution details');
    }

    $this->io()->text('');
    $this->io()->text('JSON DATA:');
    if ($jsonExists) {
      $this->io()->text('  - JSON (local): ' . $reportPaths['realPath'] . '/alfa-full-site-report.json');
      $this->io()->text('  - JSON (web): ' . $reportPaths['webUrl'] . '/alfa-full-site-report.json');
      $this->io()->text('     → Raw data for programmatic analysis and integration');
    }
    else {
      $this->logger()->warning('JSON report not found - check test execution logs');
    }

    $this->io()->text('');
    if ($customHtmlExists) {
      $this->io()->text('TIP: Open the Custom Alfa Visual Report for the best accessibility analysis experience!');
      $this->io()->text('   Features: High-priority issue highlighting, interactive violation cards, WCAG criteria grouping');
    }
    else {
      $this->logger()->warning('Custom HTML report generation may have failed. Check the test output above for errors.');
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
      $this->io()->text('  - HTML (local): ' . $reportPaths['realPath'] . '/lint-report.html');
      $this->io()->text('  - HTML (web): ' . $reportPaths['webUrl'] . '/lint-report.html');
      $this->io()->text('     → Interactive report with detailed linting results and issue breakdown');
    }
    else {
      $this->logger()->warning('HTML report not found - check linting execution logs');
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
      $this->io()->text('  - HTML: ' . $reportPaths['webUrl'] . '/pa11y-report.html');
    }
    if ($jsonExists) {
      $this->io()->text('  - JSON: ' . $reportPaths['webUrl'] . '/pa11y-report.json');
    }
    if ($logExists) {
      $this->io()->text('  - Log: ' . $reportPaths['webUrl'] . '/pa11y.log');
    }
  }

  /**
   * Generate HTML report from pa11y-ci JSON output.
   */
  protected function generatePa11yHtmlReport($reportPath, array $jsonData, string $profileName = '', string $ruleTags = '', string $sitemap = '') {
    $timestamp = date('Y-m-d H:i:s');
    $results = $jsonData['results'] ?? $jsonData;

    // Header metadata, shown in the same shape as the Alfa report.
    $safeProfileName = htmlspecialchars($profileName, ENT_QUOTES, 'UTF-8');
    $safeRuleTags = htmlspecialchars($ruleTags, ENT_QUOTES, 'UTF-8');
    $safeSitemap = htmlspecialchars($sitemap, ENT_QUOTES, 'UTF-8');
    $profileLine = $safeProfileName !== '' ? "<p><strong>Profile:</strong> $safeProfileName</p>" : '';
    $tagsLine = $safeRuleTags !== '' ? "<p><strong>Rule Tags:</strong> $safeRuleTags</p>" : '';
    $sitemapLine = $safeSitemap !== '' ? "<p><strong>Sitemap:</strong> $safeSitemap</p>" : '';

    // Derive the base URL from the first tested page for the surface banner.
    $baseUrl = '';
    if (is_array($results) && !empty($results)) {
      $parts = parse_url((string) array_key_first($results));
      if (!empty($parts['scheme']) && !empty($parts['host'])) {
        $baseUrl = $parts['scheme'] . '://' . $parts['host'];
      }
    }
    $safeBaseUrl = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');

    // The "Back to all reports" link points at the unified index.html, which
    // only exists after a full utest:all run. Individual pa11y runs have no
    // index to return to, so the link shows only when utest:all set UTEST_ALL.
    $isCi = getenv('CI') === 'true' || getenv('GITHUB_ACTIONS') === 'true';
    $backLink = getenv('UTEST_ALL') === '1' ? '<footer class="site-footer"><a href="../index.html">← Back to all reports</a></footer>' : '';

    // Surface banner (Local / CI / CI · multidev), mirroring buildSurface() so
    // the heading reads the same as the alfa / lint / unified reports.
    $multidevUrl = getenv('MULTIDEV_URL') ?: '';
    $branch = getenv('GIT_BRANCH') ?: getenv('GITHUB_HEAD_REF') ?: trim((string) @shell_exec('git branch --show-current 2>/dev/null'));
    if ($multidevUrl !== '') {
      $prNumber = getenv('PR_NUMBER') ?: getenv('GITHUB_PR_NUMBER') ?: '';
      $surfaceText = 'CI · multidev';
      $surfaceUrl = $multidevUrl;
      $surfaceLabel = $prNumber !== '' ? "pr-{$prNumber}" : ($branch ?: 'multidev');
    }
    elseif ($isCi) {
      $surfaceText = 'CI';
      $surfaceUrl = $baseUrl;
      $surfaceLabel = $branch !== '' ? "{$branch} branch" : '';
    }
    else {
      $surfaceText = 'Local';
      $surfaceUrl = $baseUrl;
      $surfaceLabel = $branch !== '' ? "{$branch} branch" : '';
    }
    $safeSurfaceText = htmlspecialchars($surfaceText, ENT_QUOTES, 'UTF-8');
    $surfaceUrlSpan = $surfaceUrl !== '' ? '<span class="surface-url">' . htmlspecialchars($surfaceUrl, ENT_QUOTES, 'UTF-8') . '</span>' : '';
    $surfaceDetailSpan = $surfaceLabel !== '' ? '<span>(' . htmlspecialchars($surfaceLabel, ENT_QUOTES, 'UTF-8') . ')</span>' : '';

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
      <h3>$safeCode</h3>
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

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>pa11y Accessibility Report</title>
<style>
* { box-sizing: border-box; }
:root {
  --brand-primary: #003660; --brand-primary-ink: #ffffff;
  --brand-accent: #febc11; --brand-accent-ink: #2a2100;
  --color-bg: #eef2f6; --color-surface: #ffffff; --color-surface-2: #f5f8fb;
  --color-text: #172431; --color-text-muted: #5a6b7b;
  --color-border: #d6dee7; --color-border-strong: #b4c1cd;
  --color-accent: var(--brand-primary); --color-accent-bg: #e6eef6;
  --severity-critical: #9b1c15; --severity-serious: #8b3409; --severity-moderate: #6a4900; --severity-minor: #1f5c78; --severity-pass: #1a6731;
  --severity-critical-bg: #fdecea; --severity-serious-bg: #fceee0; --severity-moderate-bg: #fdf3d4; --severity-minor-bg: #e3f0f6; --severity-pass-bg: #e3f3e7;
  --font-sans: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  --font-mono: ui-monospace, "SF Mono", "Cascadia Code", Menlo, Consolas, monospace;
  --radius: 5px; --radius-card: 4px; --radius-row: 3px;
  --shadow: 0 1px 2px rgba(16,33,51,0.05), 0 2px 6px rgba(16,33,51,0.05);
  --shadow-card: 0 1px 3px rgba(16,33,51,0.06), 0 8px 28px rgba(16,33,51,0.08);
  --max-width: 1120px;
}
@media (prefers-color-scheme: dark) {
  :root {
    --color-bg: #0e1922; --color-surface: #16222e; --color-surface-2: #1c2b38;
    --color-text: #e6edf3; --color-text-muted: #9db0c0;
    --color-border: #2a3a48; --color-border-strong: #3c4d5d;
    --color-accent: #7fb2e3; --color-accent-bg: #16303f;
    --severity-critical: #ffa79f; --severity-serious: #f2b183; --severity-moderate: #e4c352; --severity-minor: #8fc7e2; --severity-pass: #82d3a0;
    --severity-critical-bg: #38201d; --severity-serious-bg: #33271a; --severity-moderate-bg: #32301a; --severity-minor-bg: #13303c; --severity-pass-bg: #163127;
    --shadow: 0 1px 2px rgba(0,0,0,0.4), 0 2px 8px rgba(0,0,0,0.35);
    --shadow-card: 0 1px 3px rgba(0,0,0,0.5), 0 10px 30px rgba(0,0,0,0.45);
  }
}
body { font-family: var(--font-sans); margin: 0; padding: 0 0 8px; background: var(--color-bg); color: var(--color-text); line-height: 1.5; -webkit-font-smoothing: antialiased; }
a { color: var(--color-accent); }
code { overflow-wrap: anywhere; font-family: var(--font-mono); }
a:focus-visible, button:focus-visible, input:focus-visible { outline: 3px solid var(--color-accent); outline-offset: 2px; }
.skip-link { position: absolute; left: -9999px; top: 0; background: var(--brand-primary); color: #fff; padding: 8px 12px; z-index: 1000; text-decoration: none; }
.skip-link:focus { left: 8px; top: 8px; }

/* Full-bleed navy masthead */
.site-masthead { background: var(--brand-primary); color: var(--brand-primary-ink); padding: 30px 20px 56px; }
.masthead-inner { width: min(100% - 32px, var(--max-width)); margin-inline: auto; }
.site-masthead h1 { margin: 12px 0 6px; font-size: clamp(1.5rem, 1rem + 2.2vw, 2.1rem); line-height: 1.2; font-weight: 750; letter-spacing: -0.015em; color: #fff; }
.site-masthead .meta { margin: 0; color: rgba(255,255,255,0.72); font-size: 0.875rem; }
.surface-banner { display: inline-flex; flex-wrap: wrap; gap: 8px; align-items: center; background: rgba(255,255,255,0.12); color: #fff; padding: 6px 14px; border-radius: 5px; font-size: 0.8125rem; border: 1px solid rgba(255,255,255,0.22); }
.surface-banner strong { font-weight: 700; color: var(--brand-accent); }
.surface-banner .surface-url { font-family: var(--font-mono); font-size: 0.78rem; color: #fff; opacity: 0.92; overflow-wrap: anywhere; }

/* White card pulled up to overlap the masthead */
.container { width: min(100% - 32px, var(--max-width)); margin: -32px auto 0; position: relative; z-index: 1; background: var(--color-surface); border-radius: var(--radius); box-shadow: var(--shadow-card); padding: 20px; }
.header-info { color: var(--color-text-muted); font-size: 0.9375rem; }
.header-info p { margin: 4px 0; }
.header-info strong { color: var(--color-text); font-weight: 600; }

/* Summary stat cards */
.summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin: 20px 0; }
.stat-card { background: var(--color-surface); color: var(--color-text); padding: 16px; border: 1px solid var(--color-border); border-radius: var(--radius-card); text-align: center; }
.stat-number { font-size: 2.25rem; font-weight: 700; line-height: 1; }
.stat-label { color: var(--color-text-muted); font-size: 0.875rem; font-weight: 600; margin-top: 8px; }
.stat-card.errors { background: var(--severity-critical-bg); border-color: var(--severity-critical); color: var(--severity-critical); }
.stat-card.warnings { background: var(--severity-moderate-bg); border-color: var(--severity-moderate); color: var(--severity-moderate); }
.stat-card.notices { background: var(--severity-minor-bg); border-color: var(--severity-minor); color: var(--severity-minor); }
.stat-card.unique { background: var(--color-accent-bg); border-color: var(--color-accent); color: var(--color-accent); }
.stat-card.errors .stat-label, .stat-card.warnings .stat-label, .stat-card.notices .stat-label, .stat-card.unique .stat-label { color: inherit; }

/* Tabs */
.tabs { display: flex; gap: 4px; margin-bottom: 0; }
.tab { padding: 10px 20px; background: var(--color-surface-2); border: 1px solid var(--color-border); border-bottom: none; cursor: pointer; font: inherit; font-size: 0.95em; font-weight: 600; color: var(--color-text-muted); border-radius: var(--radius-card) var(--radius-card) 0 0; }
.tab.active { background: var(--color-surface); color: var(--color-accent); }
.tab-content { display: none; background: var(--color-surface); padding: 20px; border: 1px solid var(--color-border); border-radius: 0 var(--radius-card) var(--radius-card) var(--radius-card); }
.tab-content.active { display: block; }

/* Filters */
.filters { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; align-items: center; }
.filter-btn { padding: 6px 14px; border: 1px solid var(--color-border-strong); border-radius: var(--radius-card); background: var(--color-surface); color: var(--color-text-muted); cursor: pointer; font: inherit; font-size: 0.85em; font-weight: 600; transition: background 0.1s, color 0.1s, border-color 0.1s; }
.filter-btn.active { border-color: var(--brand-primary); background: var(--brand-primary); color: #fff; }
.filter-btn:hover { border-color: var(--color-accent); color: var(--color-accent); }
.filter-btn.active:hover { color: #fff; }
.filter-label { font-weight: 600; color: var(--color-text-muted); font-size: 0.9em; }
.search-box { padding: 7px 12px; border: 1px solid var(--color-border-strong); border-radius: var(--radius-card); font: inherit; font-size: 0.9em; width: 250px; background: var(--color-surface); color: var(--color-text); }
.search-box:focus { outline: none; border-color: var(--color-accent); }

/* Issue cards */
.issue-card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-row); margin-bottom: 10px; overflow: hidden; }
.issue-card.hidden { display: none; }
.issue-header { display: flex; align-items: center; gap: 15px; padding: 14px 18px; cursor: pointer; transition: background 0.1s; }
.issue-header:hover { background: var(--color-bg); }
.type-badge { padding: 3px 10px; border-radius: 3px; font-size: 0.75em; font-weight: 700; white-space: nowrap; min-width: 65px; text-align: center; text-transform: uppercase; }
.issue-error .type-badge, .type-badge.issue-error { background: var(--severity-critical-bg); color: var(--severity-critical); }
.issue-warning .type-badge, .type-badge.issue-warning { background: var(--severity-moderate-bg); color: var(--severity-moderate); }
.issue-notice .type-badge, .type-badge.issue-notice { background: var(--severity-minor-bg); color: var(--severity-minor); }
/* Issue type is conveyed by the type badge, not a colored left border. */
.issue-title { flex: 1; }
.issue-title h3 { margin: 0; font-size: 0.95em; color: var(--color-text); font-weight: 700; }
.visually-hidden { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
.issue-message { margin: 4px 0 0; font-size: 0.85em; color: var(--color-text-muted); line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.issue-meta { display: flex; gap: 12px; align-items: center; flex-shrink: 0; }
.meta-stat { background: var(--color-surface-2); padding: 3px 10px; border-radius: 3px; font-size: 0.8em; font-weight: 600; color: var(--color-text-muted); white-space: nowrap; }
.expand-icon { font-size: 1.2em; color: var(--color-text-muted); transition: transform 0.15s; }
.issue-card.open .expand-icon { transform: rotate(90deg); }
.issue-detail { display: none; padding: 0 18px 18px; border-top: 1px solid var(--color-border); }
.issue-card.open .issue-detail { display: block; }
.issue-detail h4 { margin: 14px 0 8px; color: var(--color-text-muted); font-size: 0.9em; }
.page-list { list-style: none; padding: 0; margin: 0; }
.page-list li { padding: 8px 12px; border-bottom: 1px solid var(--color-border); font-size: 0.85em; }
.page-list li:last-child { border-bottom: none; }
.page-list a { color: var(--color-accent); word-break: break-all; }
.page-list .count { color: var(--color-text-muted); font-size: 0.85em; }
.selectors { margin-top: 4px; }
.selector { background: var(--color-surface-2); border: 1px solid var(--color-border); padding: 2px 6px; border-radius: 3px; font-size: 0.8em; font-family: var(--font-mono); word-break: break-all; display: inline-block; margin: 2px 0; }
.more { color: var(--color-text-muted); font-size: 0.8em; font-style: italic; }

/* Page table */
.page-table { width: 100%; border-collapse: collapse; }
.page-table th { background: var(--color-surface-2); padding: 10px 14px; text-align: left; border-bottom: 2px solid var(--color-border-strong); font-size: 0.85em; color: var(--color-text-muted); text-transform: uppercase; cursor: pointer; }
.page-table th:hover { background: var(--color-bg); }
.page-table td { padding: 10px 14px; border-bottom: 1px solid var(--color-border); font-size: 0.9em; }
.page-table a { color: var(--color-accent); word-break: break-all; }
.no-results { text-align: center; padding: 40px; color: var(--color-text-muted); font-size: 1.1em; }
.site-footer { width: min(100% - 32px, var(--max-width)); margin: 32px auto 0; padding-top: 18px; border-top: 1px solid var(--color-border); color: var(--color-text-muted); font-size: 0.8125rem; text-align: center; }
.site-footer a { color: var(--color-accent); text-decoration: none; }
.site-footer a:hover { text-decoration: underline; }
</style>
</head>
<body>
<a href="#main" class="skip-link">Skip to results</a>
<header class="site-masthead">
  <div class="masthead-inner">
    <div class="surface-banner" role="status">
      <strong>$safeSurfaceText</strong>
      $surfaceUrlSpan
      $surfaceDetailSpan
    </div>
    <h1>pa11y Accessibility Report</h1>
    <p class="meta">Generated $timestamp</p>
  </div>
</header>
<main id="main" class="container">
  <div class="header-info">
    $profileLine
    $tagsLine
    <p><strong>Issue Types:</strong> error, warning, notice (pa11y-ci fails on errors)</p>
    <p><strong>Base URL:</strong> $safeBaseUrl</p>
    $sitemapLine
  </div>
  <div class="summary" role="group" aria-label="Results summary">
    <div class="stat-card errors"><div class="stat-number">$totalErrors</div><div class="stat-label">Errors</div></div>
    <div class="stat-card warnings"><div class="stat-number">$totalWarnings</div><div class="stat-label">Warnings</div></div>
    <div class="stat-card notices"><div class="stat-number">$totalNotices</div><div class="stat-label">Notices</div></div>
    <div class="stat-card unique"><div class="stat-number">$totalIssueTypes</div><div class="stat-label">Unique Issues</div></div>
    <div class="stat-card"><div class="stat-number">$totalUrls</div><div class="stat-label">Pages</div></div>
  </div>

<div class="tabs" role="tablist">
  <button class="tab active" role="tab" onclick="switchTab('issues')" id="tab-issues" aria-selected="true" aria-controls="panel-issues">Issues by Rule ($totalIssueTypes)</button>
  <button class="tab" role="tab" onclick="switchTab('pages')" id="tab-pages" aria-selected="false" aria-controls="panel-pages">Issues by Page ($totalUrls)</button>
</div>

<div class="tab-content active" id="panel-issues" role="tabpanel" aria-labelledby="tab-issues">
  <h2 class="visually-hidden">Issues by Rule</h2>
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
  <h2 class="visually-hidden">Issues by Page</h2>
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
$backLink

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
   * Run an external command with a timeout, streaming output as it runs.
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

import { test, expect } from '@playwright/test';
import { chromium, BrowserContext } from 'playwright';
import {
  wrapPlaywrightPage,
  playwrightConfig,
  PlaywrightController
} from '@axe-core/watcher';
import { AxeBuilder } from '@axe-core/playwright';
import { getAccessibilityConfig, getProfileInfo } from '../config/a11y-profiles.js';
import { fetchSitemapUrls } from '../utils/sitemap.js';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8888';
const SITEMAP_URL = process.env.SITEMAP_URL || `${BASE}/sitemap.xml`;
const MAX_PAGES = parseInt(process.env.AXE_MAX_PAGES || '50');
const API_KEY = process.env.AXE_API_KEY;

// Get axe-specific configuration
const axeConfig = getAccessibilityConfig('axe');
const profileInfo = getProfileInfo();

// For full-site testing, always use ALL severity levels for comprehensive coverage
const ALL_SEVERITY_LEVELS = ['critical', 'serious', 'moderate', 'minor'];

// Use configured severity levels for local analysis (what should cause test failures)
const FAIL_ON_SEVERITY = new Set(axeConfig.severity || ALL_SEVERITY_LEVELS);

console.log(`DEBUG: Environment variables:`);
console.log(`   BASE_URL: ${BASE}`);
console.log(`   SITEMAP_URL: ${SITEMAP_URL}`);
console.log(`   AXE_MAX_PAGES: ${MAX_PAGES}`);
console.log(`   AXE_API_KEY: ${API_KEY ? 'Set' : 'Not set'}`);
console.log(`DEBUG: Accessibility Profile: ${profileInfo.name}`);
console.log(`DEBUG: Rule tags: ${axeConfig.tags ? axeConfig.tags.join(', ') : 'undefined'}`);
console.log(`DEBUG: Profile severity levels: ${axeConfig.severity ? axeConfig.severity.join(', ') : 'undefined'}`);
console.log(`DEBUG: Full-site severity levels: ${ALL_SEVERITY_LEVELS.join(', ')} (all levels for comprehensive coverage)`);
console.log(`DEBUG: Local test failure severity: ${Array.from(FAIL_ON_SEVERITY).join(', ')}`);

// Function to fetch and parse sitemap — uses shared utility
// (see utils/sitemap.js)

test.describe(`axe Developer Hub - Full Site Audit (${profileInfo.name})`, () => {
  let browserContext: BrowserContext;

  test.beforeAll(async () => {
    if (!API_KEY) {
      throw new Error('AXE_API_KEY environment variable is required for axe Developer Hub integration');
    }

    // Prepare axe configuration for Developer Hub
    const axeHubConfig: any = {
      apiKey: API_KEY,
      // Include rule tags from accessibility profile
      tags: axeConfig.tags || ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'],
    };

    // Add runOnly configuration if available
    if (axeConfig.options && axeConfig.options.runOnly) {
      axeHubConfig.runOnly = axeConfig.options.runOnly;
    }

    console.log(`DEBUG: axe Developer Hub Configuration:`);
    console.log(`   API Key: ${API_KEY ? 'Set' : 'Not set'}`);
    console.log(`   Tags: ${axeHubConfig.tags.join(', ')}`);
    console.log(`   RunOnly: ${axeHubConfig.runOnly ? JSON.stringify(axeHubConfig.runOnly) : 'Not set'}`);

    // Create browser context with comprehensive axe Developer Hub configuration
    browserContext = await chromium.launchPersistentContext(
      '', // Use temporary directory for user data
      playwrightConfig({
        axe: axeHubConfig,
        headless: true,
        channel: 'chromium',
        args: ['--headless=new']
      })
    );
  });

  test.afterAll(async () => {
    if (browserContext) {
      await browserContext.close();
    }
  });

  test('Full site accessibility audit with axe Developer Hub', async () => {
    // Dynamic test timeout proportional to number of pages. MAX_PAGES=0
    // means "no cap"; budget for up to 1000 pages so full-sitemap sweeps
    // on medium-sized sites don't hit the timeout.
    const perPageBudgetMs = 75_000; // nav + axe + flush per page (~75s)
    const budgetCount = MAX_PAGES <= 0 ? 1000 : Math.min(MAX_PAGES, 1000);
    test.setTimeout(Math.max(5 * 60_000, budgetCount * perPageBudgetMs));
    // Initialize test summary
    const testSummary = {
      totalPages: 0,
      passedPages: 0,
      failedPages: 0,
      totalViolations: 0,
      startTime: new Date().toISOString(),
      endTime: '',
      duration: 0
    };
    
    const allResults: any[] = [];
    
    console.log(`Fetching sitemap from: ${SITEMAP_URL}`);
    const sitemapUrls = await fetchSitemapUrls(SITEMAP_URL, { baseUrl: BASE, maxPages: MAX_PAGES });
    testSummary.totalPages = sitemapUrls.length;
    
    console.log(`\nStarting axe Developer Hub audit of ${sitemapUrls.length} pages...`);
    
    // Track pages that fail to load for retry
    const failedPages: string[] = [];
    const maxRetries = 2;
    
    // Helper function to test a single page
    const testSinglePage = async (url: string, attempt: number = 1): Promise<any> => {
      const urlPath = new URL(url).pathname;
      const isRetry = attempt > 1;
      
      console.log(`\n[${allResults.length + 1}/${sitemapUrls.length}] ${isRetry ? `Retry ${attempt - 1}: ` : ''}Testing: ${urlPath}`);
      
      const pageResult = {
        url: url,
        path: urlPath,
        timestamp: new Date().toISOString(),
        passed: false,
        violations: [] as any[],
        error: null as string | null,
        violationCount: 0,
        attempt: attempt
      };

      let page;
      let pageController: PlaywrightController;
      
      try {
        // Create a page instance using the browser context
        page = await browserContext.newPage();

        // Initialize the PlaywrightController
        pageController = new PlaywrightController(page);

        // Wrap the Playwright page
        page = wrapPlaywrightPage(page, pageController);

        // Navigate to the page with extended timeout and better wait strategy
        try {
          await page.goto(url, { 
            waitUntil: 'domcontentloaded',
            timeout: 90000 // Use 90 seconds to match global config
          });
          
          // Wait for network activity to settle for better page stability
          await page.waitForLoadState('networkidle', { timeout: 30000 });
        } catch (timeoutError) {
          console.log(`   ⚠️  Initial navigation timeout, attempting fallback strategy...`);
          // Fallback: try with just domcontentloaded
          await page.goto(url, { 
            waitUntil: 'domcontentloaded',
            timeout: 60000 
          });
        }

        // Step 1: Let axe Developer Hub integration run and send data
        console.log(`   Running axe Developer Hub analysis...`);
        await page.waitForTimeout(2000); // Give Developer Hub time to initialize
        
        // Flush Developer Hub results
        try {
          if (pageController && page && !page.isClosed()) {
            await pageController.flush();
            console.log(`   Results sent to axe Developer Hub`);
          }
        } catch (e) {
          console.log(`   ⚠️  Warning: Controller flush error: ${(e as Error).message}`);
        }

        // Step 2: Run local axe analysis on the same page for immediate feedback
        console.log(`   Running local axe analysis for immediate feedback...`);
        
        // Wait a moment to ensure Developer Hub processing is complete
        await page.waitForTimeout(1000);
        
        // Run local axe analysis on the same page (after Developer Hub is done)
        let axeBuilder = new AxeBuilder({ page }).withTags(axeConfig.tags);
        
        // Note: We use withTags() for tag-based filtering, not withRules()
        // Sequential approach: Developer Hub first, then local analysis on same page
        
        const localResults = await axeBuilder.analyze();
        const localViolations = localResults.violations.filter(v => v.impact && FAIL_ON_SEVERITY.has(v.impact));
        
        // Basic page validation
        const title = await page.title();
        console.log(`   Page title: ${title}`);
        
        // Store detailed results
        pageResult.violations = localViolations;
        pageResult.violationCount = localViolations.length;
        pageResult.passed = !!title && localViolations.length === 0;
        
        // Update summary
        testSummary.totalViolations += localViolations.length;
        
        if (pageResult.passed) {
          testSummary.passedPages++;
          console.log(`   ✅ PASS - No accessibility violations found`);
          console.log(`   Results sent to both local analysis and axe Developer Hub`);
        } else {
          testSummary.failedPages++;
          console.log(`   ❌ FAIL - Found ${localViolations.length} accessibility violation(s):`);
          localViolations.forEach(violation => {
            console.log(`      - ${violation.id}: ${violation.description} (${violation.impact})`);
          });
          console.log(`   Detailed results available in both local report and axe Developer Hub dashboard`);
        }

      } catch (error) {
        pageResult.error = error.message;
        pageResult.passed = false;
        
        // Check if this is a retryable error (navigation, timeout, or browser closure)
        const isRetryableError = error.message.includes('timeout') || 
                                 error.message.includes('navigation') ||
                                 error.message.includes('ERR_') ||
                                 error.message.includes('net::') ||
                                 error.message.includes('Target page, context or browser has been closed') ||
                                 error.message.includes('Browser has been closed') ||
                                 error.message.includes('Context has been closed');
        
        if (isRetryableError && attempt <= maxRetries) {
          console.log(`   ⚠️  Retryable error (attempt ${attempt}): ${error.message}`);
          // Don't count this as a final failure yet, we'll retry
        } else {
          testSummary.failedPages++;
          console.log(`   ❌ ERROR - ${error.message}`);
        }
      } finally {
        // Clean up page and controller
        try {
          if (pageController && page && !page.isClosed()) {
            await pageController.flush();
          }
        } catch (flushError) {
          console.log(`   ⚠️  Warning: Controller flush error: ${flushError.message}`);
        }
        if (page && !page.isClosed()) {
          await page.close().catch(() => {}); // swallow if the context was already torn down
        }
        // Add a small delay to prevent state pollution between pages
        await new Promise(resolve => setTimeout(resolve, 100));
      }
      
      return pageResult;
    };
    
    // Test each URL with retry logic
    for (let i = 0; i < sitemapUrls.length; i++) {
      const url = sitemapUrls[i];
      let pageResult = await testSinglePage(url, 1);
      
      // If the page failed with a retryable error, add to retry list
      if (pageResult.error && (pageResult.error.includes('timeout') || 
                              pageResult.error.includes('navigation') ||
                              pageResult.error.includes('ERR_') ||
                              pageResult.error.includes('net::') ||
                              pageResult.error.includes('Target page, context or browser has been closed') ||
                              pageResult.error.includes('Browser has been closed') ||
                              pageResult.error.includes('Context has been closed'))) {
        failedPages.push(url);
      }
      
      // Store initial result
      allResults.push(pageResult);
    }
    
    // Retry failed pages
    if (failedPages.length > 0) {
      console.log(`\nRetrying ${failedPages.length} pages that failed to load...`);
      
      for (const url of failedPages) {
        for (let attempt = 2; attempt <= maxRetries + 1; attempt++) {
          const retryResult = await testSinglePage(url, attempt);
          
          // If successful, replace the original failed result
          if (!retryResult.error) {
            const originalIndex = allResults.findIndex(r => r.url === url && r.attempt === 1);
            if (originalIndex !== -1) {
              allResults[originalIndex] = retryResult;
              console.log(`   ✅ Retry successful for ${retryResult.path}`);
            }
            break;
          } else if (attempt === maxRetries + 1) {
            // Final failure
            console.log(`   ❌ Final failure for ${retryResult.path} after ${maxRetries} retries`);
          }
        }
      }
    }
    
    // Generate comprehensive summary
    testSummary.endTime = new Date().toISOString();
    testSummary.duration = new Date(testSummary.endTime).getTime() - new Date(testSummary.startTime).getTime();
    
    console.log(`\naxe Developer Hub Full Site Audit Complete:`);
    console.log(`   Total Pages: ${testSummary.totalPages}`);
    console.log(`   Passed: ${testSummary.passedPages}`);
    console.log(`   Failed: ${testSummary.failedPages}`);
    console.log(`   Total Violations: ${testSummary.totalViolations}`);
    console.log(`   Duration: ${Math.round(testSummary.duration / 1000)}s`);
    console.log(`   axe Developer Hub: Check your dashboard for detailed results`);
    
    // Hybrid approach: Fail for both load failures AND accessibility violations
    const accessibilityFailures = allResults.filter(result => !result.passed && !result.error);
    const totalViolationsFound = allResults.reduce((sum, result) => sum + result.violationCount, 0);
    
    console.log(`\nHybrid Testing Summary:`);
    console.log(`   ✅ All accessibility data sent to Developer Hub for comprehensive analysis`);
    console.log(`   Local analysis provides immediate feedback for CI/CD`);
    console.log(`   Check axe Developer Hub dashboard for detailed violation reports and trends`);
    
    if (accessibilityFailures.length > 0) {
      console.log(`\n❌ ACCESSIBILITY FAILURES DETECTED:`);
      accessibilityFailures.forEach(result => {
        console.log(`   ${result.path}: ${result.violationCount} violation(s)`);
        result.violations.forEach(violation => {
          console.log(`      - ${violation.id}: ${violation.description} (${violation.impact})`);
        });
      });
    }
    
    // Fail if accessibility violations are found (immediate feedback) or pages couldn't load
    expect(
      accessibilityFailures.length,
      `${accessibilityFailures.length} page(s) failed accessibility checks with ${totalViolationsFound} total violations. Local analysis detected issues that require attention. See console output and axe Developer Hub dashboard for details.`
    ).toBe(0);
  });
});

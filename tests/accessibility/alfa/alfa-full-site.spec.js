import { test, expect } from "@playwright/test";
import { Audit, Rules, Logging } from "@siteimprove/alfa-test-utils";
import { Playwright } from "@siteimprove/alfa-playwright";
import { writeFileSync, mkdirSync, existsSync } from "fs";
import { join, resolve, dirname } from "path";
import { fileURLToPath } from "url";
import { getRuleInfo, getSeverityColor, getCategoryIcon } from "./dynamic-rule-extractor.js";
import { getAccessibilityConfig, getProfileInfo } from "../config/a11y-profiles.js";
import { escapeHtml, generateHtmlReport } from "../utils/report-generator.js";
import { fetchSitemapUrls } from "../utils/sitemap.js";
import { writeAlfaFindings } from "../utils/findings-emitter.js";

// Resolve project root (three levels up from tests/accessibility/alfa/)
const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const PROJECT_ROOT = resolve(__dirname, '..', '..', '..');

const BASE = process.env.BASE_URL || "http://127.0.0.1:8888";
const SITEMAP_URL = process.env.SITEMAP_URL || `${BASE}/sitemap.xml`;
const MAX_PAGES = parseInt(process.env.ALFA_MAX_PAGES || "50"); // Limit for performance
const OUTPUT_DIR = process.env.ALFA_OUTPUT_DIR || join(PROJECT_ROOT, "web/sites/default/files/test-reports/alfa");

// Get Alfa-specific configuration
const alfaConfig = getAccessibilityConfig('alfa');

console.log(`DEBUG: Environment variables:`);
console.log(`   BASE_URL: ${BASE}`);
console.log(`   SITEMAP_URL: ${SITEMAP_URL}`);
console.log(`   ALFA_MAX_PAGES: ${MAX_PAGES}`);
console.log(`   ALFA_OUTPUT_DIR: ${OUTPUT_DIR}`);
console.log(`   A11Y_PROFILE: ${process.env.A11Y_PROFILE || 'undefined'}`);
console.log(`DEBUG: Rule tags: ${alfaConfig.tags.join(', ')}`);

// Ensure output directory exists (Drush should have created it, but double-check)
try {
  mkdirSync(OUTPUT_DIR, { recursive: true });
  console.log(`✅ DEBUG: Output directory created/verified: ${OUTPUT_DIR}`);
} catch (e) {
  console.log(`❌ DEBUG: Error creating output directory: ${e.message}`);
}

// Function to fetch and parse sitemap — uses shared utility
// (see utils/sitemap.js)

test.describe("Siteimprove Alfa - Full Site Audit", () => {
  test("Full site accessibility audit", async ({ page }) => {
    // Get profile information at runtime when environment variables are available
    const profileInfo = getProfileInfo();
    
    console.log(`DEBUG: Profile resolved at runtime: ${profileInfo.name}`);
    console.log(`DEBUG: Profile description: ${profileInfo.description}`);
    
    // Initialize test summary
    const testSummary = {
      totalPages: 0,
      passedPages: 0,
      failedPages: 0,
      totalIssues: 0,
      startTime: new Date().toISOString()
    };
    
    const allResults = [];
    
    console.log(`Fetching sitemap from: ${SITEMAP_URL}`);
    const sitemapUrls = await fetchSitemapUrls(SITEMAP_URL, { baseUrl: BASE, maxPages: MAX_PAGES });
    testSummary.totalPages = sitemapUrls.length;
    
    console.log(`\nStarting Alfa audit of ${sitemapUrls.length} pages...`);
    
    // Test each URL
    for (let i = 0; i < sitemapUrls.length; i++) {
      const url = sitemapUrls[i];
      const urlPath = new URL(url).pathname;
      
      console.log(`\n[${i + 1}/${sitemapUrls.length}] Testing: ${urlPath}`);
      
      const pageResult = {
        url: url,
        path: urlPath,
        timestamp: new Date().toISOString(),
        passed: false,
        issues: [],
        error: null,
        issueCount: 0
      };

      try {
        // Navigate to the page with extended timeout and better wait strategy
        console.log(`   Navigating to: ${url}`);
        try {
          await page.goto(url, { 
            waitUntil: "domcontentloaded", 
            timeout: 90000 // Use 90 seconds to match global config
          });
          
          // Wait for network activity to settle for better page stability
          await page.waitForLoadState('networkidle', { timeout: 30000 });
        } catch (timeoutError) {
          console.log(`   ⚠️  Initial navigation timeout, attempting fallback strategy...`);
          // Fallback: try with just domcontentloaded
          await page.goto(url, { 
            waitUntil: "domcontentloaded", 
            timeout: 60000 
          });
        }

        // Convert page to Alfa format
        const docHandle = await page.evaluateHandle(() => window.document);
        const alfaPage = await Playwright.toPage(docHandle);

        // Use configured rule set based on selected profile
        console.log(`   Running ${profileInfo.name}: ${profileInfo.description}`);

        // Run Alfa audit with configured rule set
        const results = await Audit.run(alfaPage, {
          rules: {
            tags: alfaConfig.tags
          },
          ...alfaConfig.options
        });

        // Process results with severity filtering
        const allFailures = results.resultAggregates.filter(a => a.failed > 0);
        
        // Get configured severity levels for filtering
        const configuredSeverities = new Set(alfaConfig.severity || ['critical', 'serious']);
        console.log(`   Filtering by severity levels: ${Array.from(configuredSeverities).join(', ')}`);
        
        // Filter failures by severity and collect all issues for reporting
        const allIssues = [];
        const filteredFailures = [];
        
        for (const failure of allFailures) {
          // Extract rule URL from the failure array structure [ruleUrl, stats]
          const ruleUrl = Array.isArray(failure) ? failure[0] : failure.rule;
          const stats = Array.isArray(failure) ? failure[1] : failure;
          const ruleInfo = await getRuleInfo(ruleUrl);
          
          const issue = {
            rule: ruleUrl,
            failed: stats.failed,
            passed: stats.passed,
            cantTell: stats.cantTell,
            severity: ruleInfo.severity,
            ruleInfo: ruleInfo
          };
          
          // Add to all issues for comprehensive reporting
          allIssues.push(issue);
          
          // Add to filtered failures only if severity matches configured levels
          if (configuredSeverities.has(ruleInfo.severity)) {
            filteredFailures.push(failure);
          }
        }

        // Store all issues for comprehensive reporting
        pageResult.issues = allIssues;
        pageResult.filteredIssues = allIssues.filter(issue => configuredSeverities.has(issue.severity));
        
        // Determine pass/fail based on FILTERED failures only
        pageResult.passed = filteredFailures.length === 0;
        pageResult.issueCount = allFailures.length; // Total issues for reporting
        pageResult.filteredIssueCount = filteredFailures.length; // Issues that cause failure

        // Update summary based on filtered results
        if (pageResult.passed) {
          testSummary.passedPages++;
          if (allFailures.length > 0) {
            console.log(`   ✅ PASS - ${allFailures.length} total issue(s) found, but none match severity filter (${Array.from(configuredSeverities).join(', ')})`);
          } else {
            console.log(`   ✅ PASS - No accessibility issues found`);
          }
        } else {
          testSummary.failedPages++;
          testSummary.totalIssues += filteredFailures.length;
          console.log(`   ❌ FAIL - ${filteredFailures.length} filtered issue(s) found (${allFailures.length} total issues)`);
        }

        // Log detailed results for this page
        if (allFailures.length > 0) {
          console.log(`   Issues (showing all, filtering by ${Array.from(configuredSeverities).join(', ')}):`);
          for (const failure of allFailures) {
            // Extract rule URL from the failure array structure
            const ruleUrl = Array.isArray(failure) ? failure[0] : failure.rule;
            const failureStats = Array.isArray(failure) ? failure[1] : failure;
            const ruleInfo = await getRuleInfo(ruleUrl);
            const categoryIcon = getCategoryIcon(ruleInfo.category);
            const isFiltered = configuredSeverities.has(ruleInfo.severity);
            const statusIcon = isFiltered ? '' : '⚠️';
            
            console.log(`     ${statusIcon} ${categoryIcon} ${ruleInfo.id || ruleUrl}: ${ruleInfo.title}`);
            console.log(`        Severity: ${ruleInfo.severity.toUpperCase()}, Failures: ${failureStats.failed} ${isFiltered ? '(CAUSES FAILURE)' : '(FILTERED OUT)'}`);
            console.log(`        Fix: ${ruleInfo.fixRecommendations[0] || 'See detailed report for recommendations'}`);
          }
        }

      } catch (error) {
        pageResult.error = error.message;
        pageResult.passed = false;
        testSummary.failedPages++;
        console.log(`   ❌ ERROR - ${error.message}`);
      }
      
      // Store results
      allResults.push(pageResult);
    }
    
    // Generate comprehensive report
    testSummary.endTime = new Date().toISOString();
    testSummary.duration = new Date(testSummary.endTime).getTime() - new Date(testSummary.startTime).getTime();
    
    const report = {
      summary: testSummary,
      results: allResults,
      generatedAt: new Date().toISOString(),
      sitemapUrl: SITEMAP_URL,
      baseUrl: BASE
    };

    // Write JSON report
    const jsonReportPath = join(OUTPUT_DIR, "alfa-full-site-report.json");
    console.log(`DEBUG: Attempting to write JSON report to: ${jsonReportPath}`);
    try {
      writeFileSync(jsonReportPath, JSON.stringify(report, null, 2));
      console.log(`✅ DEBUG: JSON report written successfully`);
    } catch (error) {
      console.log(`❌ DEBUG: Error writing JSON report: ${error.message}`);
    }

    // Write HTML report
    const htmlReport = await generateHtmlReport(report, profileInfo);
    const htmlReportPath = join(OUTPUT_DIR, "alfa-full-site-report.html");
    console.log(`DEBUG: Attempting to write HTML report to: ${htmlReportPath}`);
    try {
      writeFileSync(htmlReportPath, htmlReport);
      console.log(`✅ DEBUG: HTML report written successfully`);
    } catch (error) {
      console.log(`❌ DEBUG: Error writing HTML report: ${error.message}`);
    }

    // Emit test-suite-findings.json — the unified-shape contract that the unified
    // report shell consumes. Sits alongside the existing JSON/HTML reports.
    try {
      const findingsPath = writeAlfaFindings(report, profileInfo, alfaConfig, OUTPUT_DIR, "alfa-full");
      console.log(`✅ DEBUG: test-suite-findings.json written: ${findingsPath}`);
    } catch (error) {
      console.log(`❌ DEBUG: Error writing test-suite-findings.json: ${error.message}`);
    }

    console.log(`\nAlfa Full Site Audit Complete:`);
    console.log(`   Total Pages: ${testSummary.totalPages}`);
    console.log(`   Passed: ${testSummary.passedPages}`);
    console.log(`   Failed: ${testSummary.failedPages}`);
    console.log(`   Total Issues: ${testSummary.totalIssues}`);
    console.log(`   Reports: ${jsonReportPath}, ${htmlReportPath}`);
    
    // Assert overall test result - fail only if pages had issues matching configured severity levels
    const filteredFailures = allResults.filter(result => !result.passed && !result.error);
    const configuredSeverities = alfaConfig.severity || ['critical', 'serious'];
    
    console.log(`\nFinal Test Result:`);
    console.log(`   Configured severity levels: ${configuredSeverities.join(', ')}`);
    console.log(`   Pages that failed severity filter: ${filteredFailures.length}`);
    console.log(`   Total pages with any issues: ${allResults.filter(r => r.issueCount > 0).length}`);
    
    expect(
      filteredFailures.length,
      `${filteredFailures.length} page(s) failed accessibility checks with severity levels: ${configuredSeverities.join(', ')}. See detailed report for specifics.`
    ).toBe(0);
  });
});


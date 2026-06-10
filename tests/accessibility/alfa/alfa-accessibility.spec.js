import { test, expect } from "@playwright/test";
import { Audit, Rules, Logging } from "@siteimprove/alfa-test-utils";
import { Playwright } from "@siteimprove/alfa-playwright";
import { writeFileSync, mkdirSync } from "fs";
import { getRuleInfo, getSeverityColor, getCategoryIcon } from "./dynamic-rule-extractor.js";
import { getAccessibilityConfig, getProfileInfo } from "../config/a11y-profiles.js";
import { writeAlfaFindings } from "../utils/findings-emitter.js";

const BASE = process.env.BASE_URL || "http://127.0.0.1:8888";
const PATHS = (process.env.PLAYWRIGHT_PATHS || "/,/user/login").split(",").map(s => s.trim());
const REPORT_DIR = process.env.PLAYWRIGHT_REPORT_DIR || null;

// Check if network features should be disabled for more reliable testing
const DISABLE_NETWORK_FEATURES = process.env.ALFA_DISABLE_NETWORK_FEATURES === 'true';

// Get Alfa-specific configuration
const alfaConfig = getAccessibilityConfig('alfa');
const profileInfo = getProfileInfo();

test.describe(`Siteimprove Alfa (${profileInfo.name})`, () => {
  // Collect per-page results for summary report
  const pageResults = [];

  for (const p of PATHS) {
    test(`alfa ${p}`, async ({ page }) => {
      console.log(`Testing accessibility for: ${BASE}${p}`);
      
      await page.goto(`${BASE}${p}`, { 
        waitUntil: "networkidle",
        timeout: 30000 
      });

      // Wait for any dynamic content to load
      await page.waitForTimeout(2000);

      // ✅ Correct adapter usage: document handle → Alfa page
      const docHandle = await page.evaluateHandle(() => window.document);
      const alfaPage = await Playwright.toPage(docHandle);

      // Use configured rule set based on selected profile
      console.log(`Running ${profileInfo.name}: ${profileInfo.description}`);
      console.log(` Rule tags: ${alfaConfig.tags.join(', ')}`);

      const results = await Audit.run(alfaPage, {
        rules: {
          tags: alfaConfig.tags
        },
        ...alfaConfig.options
      });
      
      // Enhanced logging with detailed output
      console.log(`Audit Results for ${p}:`);
      Logging.fromAudit(results).print();

      // More comprehensive failure detection
      const failures = results.resultAggregates.filter(a => a.failed > 0);
      const cantTells = results.resultAggregates.filter(a => a.cantTell > 0);
      
      console.log(`❌ Failed rules: ${failures.size}`);
      console.log(`Inconclusive rules: ${cantTells.size}`);
      
      // Get configured severity levels for filtering (same as alfa-full)
      const configuredSeverities = new Set(alfaConfig.severity || ['critical', 'serious']);
      console.log(`Filtering by severity levels: ${Array.from(configuredSeverities).join(', ')}`);
      
      // Filter failures by severity and collect all issues for reporting
      const allIssues = [];
      const filteredFailures = [];
      
      if (failures.size > 0) {
        console.log(`\nAccessibility violations found:`);
        for (const failure of failures) {
          // Extract rule URL from the failure array structure
          const ruleUrl = Array.isArray(failure) ? failure[0] : failure.rule;
          const stats = Array.isArray(failure) ? failure[1] : failure;
          const ruleInfo = await getRuleInfo(ruleUrl);
          const categoryIcon = getCategoryIcon(ruleInfo.category);
          
          // Add to all issues for comprehensive reporting
          allIssues.push({
            rule: ruleUrl,
            failed: stats.failed,
            passed: stats.passed,
            severity: ruleInfo.severity,
            ruleInfo: ruleInfo
          });
          
          // Add to filtered failures only if severity matches configured levels
          if (configuredSeverities.has(ruleInfo.severity)) {
            filteredFailures.push(failure);
          }
          
          // Determine if this issue causes test failure
          const causesFailure = configuredSeverities.has(ruleInfo.severity);
          const statusIcon = causesFailure ? '' : '⚠️';
          
          console.log(`\n   ${statusIcon} ${categoryIcon} ${ruleInfo.id || ruleUrl}: ${ruleInfo.title}`);
          console.log(`      Severity: ${ruleInfo.severity.toUpperCase()}, Failures: ${stats.failed} ${causesFailure ? '(CAUSES FAILURE)' : '(FILTERED OUT)'}`);
          console.log(`      Passed: ${stats.passed}`);
          console.log(`      Description: ${ruleInfo.description}`);
          console.log(`      WCAG Criteria: ${ruleInfo.wcagCriteria.join(', ')}`);
          console.log(`      Fix Recommendations:`);
          ruleInfo.fixRecommendations.forEach((rec, index) => {
            console.log(`        ${index + 1}. ${rec}`);
          });
          if (ruleInfo.codeExamples.bad !== 'No example available') {
            console.log(`      Code Example (Bad):`);
            console.log(`        ${ruleInfo.codeExamples.bad.replace(/\n/g, '\n        ')}`);
            console.log(`      Code Example (Good):`);
            console.log(`        ${ruleInfo.codeExamples.good.replace(/\n/g, '\n        ')}`);
          }
          console.log(`      Resources: ${ruleInfo.resources.join(', ')}`);
        }
      }

      if (cantTells.size > 0) {
        console.log(`\n⚠️  Inconclusive results (manual review needed):`);
        for (const cantTell of cantTells) {
          const ruleUrl = Array.isArray(cantTell) ? cantTell[0] : cantTell.rule;
          const ruleInfo = await getRuleInfo(ruleUrl);
          const categoryIcon = getCategoryIcon(ruleInfo.category);
          
          console.log(`\n   ${categoryIcon} ${ruleInfo.id || ruleUrl}: ${ruleInfo.title}`);
          console.log(`      Inconclusive: ${Array.isArray(cantTell) ? cantTell[1].cantTell : cantTell.cantTell}`);
          console.log(`      Description: ${ruleInfo.description}`);
          console.log(`      Manual Review Required: This rule requires human judgment to determine compliance`);
        }
      }

      // Summary of filtering results
      console.log(`\nTest Result Summary:`);
      console.log(`   Total issues found: ${failures.size}`);
      console.log(`   Issues causing failure: ${filteredFailures.length} (${Array.from(configuredSeverities).join(', ')} severity)`);
      console.log(`   Issues reported only: ${failures.size - filteredFailures.length} (other severities)`);

      // Collect results for summary report
      pageResults.push({
        path: p,
        url: `${BASE}${p}`,
        totalIssues: failures.size,
        filteredFailures: filteredFailures.length,
        inconclusiveRules: cantTells.size,
        issues: allIssues,
        timestamp: new Date().toISOString(),
      });

      // Use filtered failures for test assertion (same logic as alfa-full)
      expect(
        filteredFailures.length,
        `Alfa found ${filteredFailures.length} critical/serious accessibility violation(s) on ${p} (${failures.size} total issues found) using ${profileInfo.name}. See console output for details.`
      ).toBe(0);
    });
  }

  // Write JSON summary report if report directory is configured
  test.afterAll(async () => {
    if (!REPORT_DIR) return;
    try {
      mkdirSync(REPORT_DIR, { recursive: true });
      const report = {
        tool: 'siteimprove-alfa',
        profile: profileInfo.name,
        description: profileInfo.description,
        timestamp: new Date().toISOString(),
        pages: pageResults,
        summary: {
          totalPages: pageResults.length,
          pagesWithIssues: pageResults.filter(r => r.filteredFailures > 0).length,
          totalIssues: pageResults.reduce((sum, r) => sum + r.totalIssues, 0),
          totalFailures: pageResults.reduce((sum, r) => sum + r.filteredFailures, 0),
        },
      };
      const reportPath = `${REPORT_DIR}/alfa-report.json`;
      writeFileSync(reportPath, JSON.stringify(report, null, 2));
      console.log(`JSON report written to: ${reportPath}`);

      // Emit test-suite-findings.json for the unified report shell.
      try {
        const findingsPath = writeAlfaFindings(report, profileInfo, alfaConfig, REPORT_DIR, "alfa");
        console.log(`test-suite-findings.json written to: ${findingsPath}`);
      } catch (e2) {
        console.warn(`⚠️ Failed to write test-suite-findings.json: ${e2.message}`);
      }
    } catch (e) {
      console.warn(`⚠️ Failed to write JSON report: ${e.message}`);
    }
  });
});

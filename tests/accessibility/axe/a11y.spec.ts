import { test, expect } from '@playwright/test';
import { AxeBuilder } from '@axe-core/playwright';
import { writeFileSync, mkdirSync } from 'fs';
import { getAccessibilityConfig, getProfileInfo } from '../config/a11y-profiles.js';
// @ts-ignore — JS module without types
import { writeAxeFindings } from '../utils/findings-emitter.js';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8888';
const PATHS = (process.env.PLAYWRIGHT_PATHS || '/,/user/login').split(',').map(s => s.trim());
const REPORT_DIR = process.env.PLAYWRIGHT_REPORT_DIR || null;

// Get axe-specific configuration
const axeConfig = getAccessibilityConfig('axe');
const profileInfo = getProfileInfo();

// Use configured severity levels or default to serious/critical
const FAIL = new Set(axeConfig.severity || ['serious','critical']);

test.describe(`A11y (Playwright + axe) - ${profileInfo.name}`, () => {
  // Collect per-page results for summary report
  const pageResults: Array<{
    path: string;
    url: string;
    violations: number;
    filteredViolations: number;
    passes: number;
    details: Array<{
      id: string;
      description: string;
      impact: string | undefined;
      helpUrl: string;
      nodes: Array<{ target: string; html: string }>;
    }>;
    timestamp: string;
  }> = [];

  for (const p of PATHS) {
    test(`axe ${p}`, async ({ page }) => {
      console.log(`Testing accessibility for: ${BASE}${p}`);
      console.log(`Using ${profileInfo.name}: ${profileInfo.description}`);
      console.log(` Rule tags: ${axeConfig.tags.join(', ')}`);
      console.log(`⚠️  Severity levels: ${Array.from(FAIL).join(', ')}`);
      
      await page.goto(`${BASE}${p}`);
      
      // Use configured rule tags for filtering
      let axeBuilder = new AxeBuilder({ page }).withTags(axeConfig.tags);
      
      // Note: We use withTags() for tag-based filtering, not withRules()
      // The runOnly configuration contains tags, not individual rule names
      
      const results = await axeBuilder.analyze();
      const bad = results.violations.filter(v => v.impact && FAIL.has(v.impact));
      
      if (bad.length > 0) {
        console.log(`❌ Found ${bad.length} accessibility violation(s):`);
        bad.forEach(violation => {
          console.log(`\n   ${violation.id}: ${violation.description} (${violation.impact})`);
          console.log(`      Help: ${violation.helpUrl}`);
          violation.nodes.forEach((node, i) => {
            console.log(`      ${i + 1}. ${node.target.join(' > ')}`);
            if (node.html) console.log(`         HTML: ${node.html.substring(0, 200)}`);
          });
        });
      } else {
        console.log(`✅ No accessibility violations found`);
      }

      // Collect results for summary report
      pageResults.push({
        path: p,
        url: `${BASE}${p}`,
        violations: results.violations.length,
        filteredViolations: bad.length,
        passes: results.passes.length,
        details: bad.map(v => ({
          id: v.id,
          description: v.description,
          impact: v.impact,
          helpUrl: v.helpUrl,
          nodes: v.nodes.map(n => ({
            target: n.target.join(' > '),
            html: n.html,
          })),
        })),
        timestamp: new Date().toISOString(),
      });
      
      expect(bad, JSON.stringify(bad, null, 2)).toHaveLength(0);
    });
  }

  // Write JSON summary report if report directory is configured
  test.afterAll(async () => {
    if (!REPORT_DIR) return;
    try {
      mkdirSync(REPORT_DIR, { recursive: true });
      const report = {
        tool: 'axe-core',
        profile: profileInfo.name,
        description: profileInfo.description,
        timestamp: new Date().toISOString(),
        pages: pageResults,
        summary: {
          totalPages: pageResults.length,
          pagesWithIssues: pageResults.filter(r => r.filteredViolations > 0).length,
          totalViolations: pageResults.reduce((sum, r) => sum + r.violations, 0),
          totalFilteredViolations: pageResults.reduce((sum, r) => sum + r.filteredViolations, 0),
          totalPasses: pageResults.reduce((sum, r) => sum + r.passes, 0),
        },
      };
      const reportPath = `${REPORT_DIR}/axe-report.json`;
      writeFileSync(reportPath, JSON.stringify(report, null, 2));
      console.log(`JSON report written to: ${reportPath}`);

      // Unified test-suite-findings.json for the test-suite report renderer. Failure
      // here is informational — the legacy per-test report still works.
      try {
        const findingsPath = writeAxeFindings(pageResults, axeConfig, profileInfo, REPORT_DIR, 'axe');
        console.log(`Unified test-suite-findings.json written to: ${findingsPath}`);
      } catch (innerErr) {
        console.warn(`⚠️ Could not write unified test-suite-findings.json: ${(innerErr as Error).message}`);
      }
    } catch (e) {
      console.warn(`⚠️ Failed to write JSON report: ${(e as Error).message}`);
    }
  });
});

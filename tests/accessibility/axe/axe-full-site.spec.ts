import { test, expect } from '@playwright/test';
import { AxeBuilder } from '@axe-core/playwright';
import { writeFileSync, mkdirSync } from 'fs';
import { join } from 'path';
// @ts-ignore — JS module without types
import { fetchSitemapUrls } from '../utils/sitemap.js';
// @ts-ignore — JS module without types
import { getAccessibilityConfig, getProfileInfo } from '../config/a11y-profiles.js';
// @ts-ignore — JS module without types
import { writeAxeFindings } from '../utils/findings-emitter.js';

// Free axe-core full-site runner. Mirrors `a11y.spec.ts` (key pages) but
// iterates the sitemap so coverage matches `alfa-full` / `pa11y`. Uses the
// open-source axe-core engine via @axe-core/playwright — no API key, no
// Deque Developer Hub dependency. The paid `axe-watcher-full` lane stays
// for sites that want the hosted dashboard / analytics layer.

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8888';
const SITEMAP_URL = process.env.SITEMAP_URL || `${BASE}/sitemap.xml`;
const MAX_PAGES = parseInt(process.env.AXE_MAX_PAGES || '50', 10);
// Drush always sets AXE_OUTPUT_DIR; the relative fallback only kicks in
// when running `npx playwright test ...` directly from `tests/`.
const OUTPUT_DIR = process.env.AXE_OUTPUT_DIR ||
  join(process.cwd(), '../web/sites/default/files/test-reports/axe-full');

mkdirSync(OUTPUT_DIR, { recursive: true });

const axeConfig = getAccessibilityConfig('axe');
const profileInfo = getProfileInfo();
const FAIL = new Set<string>(axeConfig.severity || ['serious', 'critical']);

interface PageResult {
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
  error?: string;
}

test.describe(`A11y (Playwright + axe full-site) - ${profileInfo.name}`, () => {
  test('Full site axe audit', async ({ page }) => {
    console.log(`Fetching sitemap from: ${SITEMAP_URL}`);
    const urls = await fetchSitemapUrls(SITEMAP_URL, {
      baseUrl: BASE,
      maxPages: MAX_PAGES,
    });

    console.log(`axe full-site audit: ${urls.length} pages`);
    console.log(`Profile: ${profileInfo.name}`);
    console.log(`Rule tags: ${axeConfig.tags.join(', ')}`);
    console.log(`Severity levels: ${Array.from(FAIL).join(', ')}`);

    const pageResults: PageResult[] = [];

    for (let i = 0; i < urls.length; i++) {
      const url = urls[i];
      const path = new URL(url).pathname;
      console.log(`[${i + 1}/${urls.length}] ${path}`);

      try {
        await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
        await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});

        const results = await new AxeBuilder({ page })
          .withTags(axeConfig.tags)
          .analyze();

        const bad = results.violations.filter((v) => v.impact && FAIL.has(v.impact));

        pageResults.push({
          path,
          url,
          violations: results.violations.length,
          filteredViolations: bad.length,
          passes: results.passes.length,
          details: bad.map((v) => ({
            id: v.id,
            description: v.description,
            impact: v.impact,
            helpUrl: v.helpUrl,
            nodes: v.nodes.map((n) => ({
              target: Array.isArray(n.target) ? n.target.join(' > ') : String(n.target),
              html: n.html,
            })),
          })),
          timestamp: new Date().toISOString(),
        });

        if (bad.length > 0) {
          console.log(`   FAIL — ${bad.length} violation(s)`);
        } else {
          console.log(`   PASS — no violations at configured severity`);
        }
      } catch (err) {
        const msg = (err as Error).message;
        console.log(`   ERROR — ${msg}`);
        pageResults.push({
          path,
          url,
          violations: 0,
          filteredViolations: 0,
          passes: 0,
          details: [],
          timestamp: new Date().toISOString(),
          error: msg,
        });
      }
    }

    // Per-tool JSON dump (debug + tooling) and unified findings.json for the
    // test-suite renderer. Both are best-effort — the run shouldn't fail just
    // because the report file couldn't be written.
    try {
      const dumpPath = join(OUTPUT_DIR, 'axe-full-report.json');
      writeFileSync(dumpPath, JSON.stringify({
        tool: 'axe-core',
        profile: profileInfo.name,
        timestamp: new Date().toISOString(),
        pages: pageResults,
        summary: {
          totalPages: pageResults.length,
          pagesWithIssues: pageResults.filter((r) => r.filteredViolations > 0).length,
          totalViolations: pageResults.reduce((s, r) => s + r.violations, 0),
          totalFilteredViolations: pageResults.reduce((s, r) => s + r.filteredViolations, 0),
          totalPasses: pageResults.reduce((s, r) => s + r.passes, 0),
        },
      }, null, 2));
      console.log(`axe-full-report.json written: ${dumpPath}`);
    } catch (err) {
      console.warn(`Could not write axe-full-report.json: ${(err as Error).message}`);
    }

    try {
      const findingsPath = writeAxeFindings(pageResults, axeConfig, profileInfo, OUTPUT_DIR, 'axe-full');
      console.log(`test-suite-findings.json written: ${findingsPath}`);
    } catch (err) {
      console.warn(`Could not write test-suite-findings.json: ${(err as Error).message}`);
    }

    // Don't fail the Playwright test on violations — surfaced via the unified
    // findings, not gated here. Matches alfa-full / pa11y / reflow / meta-viewport.
    expect(pageResults.length).toBeGreaterThan(0);
  });
});

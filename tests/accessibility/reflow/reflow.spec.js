import { test, expect } from "@playwright/test";
import { writeFileSync, mkdirSync, existsSync } from "fs";
import { join, resolve, dirname } from "path";
import { fileURLToPath } from "url";
import { fetchSitemapUrls } from "../utils/sitemap.js";
import { writeReflowFindings } from "../utils/findings-emitter.js";

// WCAG 2.1 SC 1.4.10 (Reflow): content must reflow to a 320 CSS px wide
// viewport (and 256 CSS px tall for horizontal-scroll content) without
// loss of information or two-dimensional scrolling. Neither axe-core,
// Siteimprove Alfa, nor pa11y check this natively — it requires
// rendering at the target viewport. This runner does that single check.

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const PROJECT_ROOT = resolve(__dirname, "..", "..", "..");

const BASE = process.env.BASE_URL || "http://127.0.0.1:8888";
const SITEMAP_URL = process.env.SITEMAP_URL || `${BASE}/sitemap.xml`;
const MAX_PAGES = parseInt(process.env.REFLOW_MAX_PAGES || "50", 10);
const OUTPUT_DIR = process.env.REFLOW_OUTPUT_DIR ||
  join(PROJECT_ROOT, "web/sites/default/files/test-reports/reflow");

// 320 CSS px is the WCAG threshold; 1px tolerance absorbs rounding from
// browsers that report fractional viewport widths.
const REFLOW_VIEWPORT = { width: 320, height: 1024 };
const OVERFLOW_TOLERANCE_PX = 1;

mkdirSync(OUTPUT_DIR, { recursive: true });

test.describe("Reflow (WCAG 2.1 SC 1.4.10) - 320px viewport", () => {
  test("Reflow audit", async ({ browser }) => {
    const context = await browser.newContext({ viewport: REFLOW_VIEWPORT });
    const page = await context.newPage();

    console.log(`Fetching sitemap from: ${SITEMAP_URL}`);
    const urls = await fetchSitemapUrls(SITEMAP_URL, {
      baseUrl: BASE,
      maxPages: MAX_PAGES,
    });

    console.log(`Reflow audit: ${urls.length} pages at ${REFLOW_VIEWPORT.width}x${REFLOW_VIEWPORT.height}`);

    const pageResults = [];

    for (let i = 0; i < urls.length; i++) {
      const url = urls[i];
      const path = new URL(url).pathname;
      console.log(`[${i + 1}/${urls.length}] ${path}`);

      try {
        await page.goto(url, { waitUntil: "domcontentloaded", timeout: 60000 });
        await page.waitForLoadState("networkidle", { timeout: 15000 }).catch(() => {});

        const overflow = await page.evaluate((tolerance) => {
          const html = document.documentElement;
          const body = document.body;
          const docWidth = Math.max(
            html.scrollWidth, body ? body.scrollWidth : 0,
          );
          const viewportWidth = window.innerWidth;

          // Identify the worst offenders so the finding can point at
          // specific elements rather than just "page overflows."
          const overflowing = [];
          if (docWidth > viewportWidth + tolerance) {
            const all = document.querySelectorAll("body *");
            for (const el of all) {
              const rect = el.getBoundingClientRect();
              const right = rect.left + rect.width;
              if (right > viewportWidth + tolerance) {
                const tag = el.tagName.toLowerCase();
                const id = el.id ? `#${el.id}` : "";
                const cls = el.className && typeof el.className === "string"
                  ? `.${el.className.trim().split(/\s+/).slice(0, 2).join(".")}`
                  : "";
                overflowing.push({
                  selector: `${tag}${id}${cls}`,
                  width_px: Math.round(rect.width),
                  right_edge_px: Math.round(right),
                });
                if (overflowing.length >= 5) break;
              }
            }
          }

          return {
            doc_width_px: docWidth,
            viewport_width_px: viewportWidth,
            overflow_px: Math.max(0, docWidth - viewportWidth),
            overflowing_elements: overflowing,
          };
        }, OVERFLOW_TOLERANCE_PX);

        const failed = overflow.overflow_px > OVERFLOW_TOLERANCE_PX;
        pageResults.push({
          url, path,
          failed,
          overflow_px: overflow.overflow_px,
          doc_width_px: overflow.doc_width_px,
          viewport_width_px: overflow.viewport_width_px,
          overflowing_elements: overflow.overflowing_elements,
        });

        if (failed) {
          console.log(`   FAIL — ${overflow.overflow_px}px horizontal overflow`);
        } else {
          console.log(`   PASS — content reflows cleanly`);
        }
      } catch (err) {
        console.log(`   ERROR — ${err.message}`);
        pageResults.push({
          url, path, failed: true,
          overflow_px: 0, doc_width_px: 0,
          viewport_width_px: REFLOW_VIEWPORT.width,
          overflowing_elements: [],
          error: err.message,
        });
      }
    }

    // Persist a JSON dump for debugging alongside the canonical findings.
    const dumpPath = join(OUTPUT_DIR, "reflow-report.json");
    writeFileSync(dumpPath, JSON.stringify({
      tool: "playwright-reflow",
      viewport: REFLOW_VIEWPORT,
      tolerance_px: OVERFLOW_TOLERANCE_PX,
      pages: pageResults,
      summary: {
        total: pageResults.length,
        failed: pageResults.filter(r => r.failed).length,
        passed: pageResults.filter(r => !r.failed).length,
      },
    }, null, 2));
    console.log(`reflow-report.json written: ${dumpPath}`);

    try {
      const findingsPath = writeReflowFindings(pageResults, REFLOW_VIEWPORT, OUTPUT_DIR);
      console.log(`test-suite-findings.json written: ${findingsPath}`);
    } catch (err) {
      console.warn(`Could not write test-suite-findings.json: ${err.message}`);
    }

    await context.close();

    // Don't fail the Playwright test on overflow — reflow is reported
    // in the unified findings, not gated here. Matches the orchestrator
    // pattern used by alfa-full / pa11y / lint.
    expect(pageResults.length).toBeGreaterThan(0);
  });
});

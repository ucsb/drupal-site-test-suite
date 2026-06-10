import { test, expect } from "@playwright/test";
import { writeFileSync, mkdirSync } from "fs";
import { join, resolve, dirname } from "path";
import { fileURLToPath } from "url";
import { fetchSitemapUrls } from "../utils/sitemap.js";
import { writeMetaViewportFindings } from "../utils/findings-emitter.js";

// WCAG 2.0 SC 1.4.4 (Resize Text, Level AA): users must be able to zoom
// text up to 200% without loss of content or functionality. The
// `<meta name="viewport">` tag's `user-scalable=no` and `maximum-scale<2`
// directives explicitly block that — both are accessibility blockers
// even though many mobile UIs ship them by reflex. Static a11y tools
// (axe-core, Siteimprove Alfa, pa11y) don't natively check this, so
// this Playwright runner fills that gap with a cheap DOM inspection.

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const PROJECT_ROOT = resolve(__dirname, "..", "..", "..");

const BASE = process.env.BASE_URL || "http://127.0.0.1:8888";
const SITEMAP_URL = process.env.SITEMAP_URL || `${BASE}/sitemap.xml`;
const MAX_PAGES = parseInt(process.env.META_VIEWPORT_MAX_PAGES || "50", 10);
const OUTPUT_DIR = process.env.META_VIEWPORT_OUTPUT_DIR ||
  join(PROJECT_ROOT, "web/sites/default/files/test-reports/meta-viewport");

mkdirSync(OUTPUT_DIR, { recursive: true });

// Parse a meta-viewport content string ("width=device-width, initial-scale=1,
// user-scalable=no") into a plain object. Tokens that don't follow key=value
// are dropped silently — matches how browsers tolerate malformed content.
function parseViewportContent(content) {
  const out = {};
  if (!content) return out;
  for (const part of String(content).split(",")) {
    const [k, v] = part.split("=").map(s => (s || "").trim());
    if (k) out[k.toLowerCase()] = v == null ? "" : v;
  }
  return out;
}

// Return the failure reason ("user-scalable=no", "maximum-scale=1", etc.)
// or null if the page passes. Both directives independently violate 1.4.4;
// when a page sets both, report the most user-hostile one.
function classifyViewport(parsed) {
  if (parsed["user-scalable"] === "no" || parsed["user-scalable"] === "0") {
    return { reason: "user-scalable=no", value: parsed["user-scalable"] };
  }
  const max = parseFloat(parsed["maximum-scale"]);
  if (Number.isFinite(max) && max < 2) {
    return { reason: `maximum-scale=${parsed["maximum-scale"]}`, value: parsed["maximum-scale"] };
  }
  return null;
}

test.describe("Meta-viewport (WCAG 2.0 SC 1.4.4) — zoom not blocked", () => {
  test("Meta-viewport audit", async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();

    console.log(`Fetching sitemap from: ${SITEMAP_URL}`);
    const urls = await fetchSitemapUrls(SITEMAP_URL, {
      baseUrl: BASE,
      maxPages: MAX_PAGES,
    });

    console.log(`Meta-viewport audit: ${urls.length} pages`);

    const pageResults = [];

    for (let i = 0; i < urls.length; i++) {
      const url = urls[i];
      const path = new URL(url).pathname;
      console.log(`[${i + 1}/${urls.length}] ${path}`);

      try {
        await page.goto(url, { waitUntil: "domcontentloaded", timeout: 60000 });

        const content = await page.evaluate(() => {
          const el = document.querySelector('meta[name="viewport"]');
          return el ? el.getAttribute("content") : null;
        });

        const parsed = parseViewportContent(content);
        const failure = classifyViewport(parsed);

        pageResults.push({
          url, path,
          failed: failure !== null,
          content: content || "",
          failure_reason: failure ? failure.reason : null,
          failure_value: failure ? failure.value : null,
        });

        if (failure) {
          console.log(`   FAIL — ${failure.reason}`);
        } else {
          console.log(`   PASS — zoom not blocked`);
        }
      } catch (err) {
        console.log(`   ERROR — ${err.message}`);
        pageResults.push({
          url, path, failed: true,
          content: "", failure_reason: "audit-error",
          failure_value: null, error: err.message,
        });
      }
    }

    const dumpPath = join(OUTPUT_DIR, "meta-viewport-report.json");
    writeFileSync(dumpPath, JSON.stringify({
      tool: "playwright-meta-viewport",
      pages: pageResults,
      summary: {
        total: pageResults.length,
        failed: pageResults.filter(r => r.failed).length,
        passed: pageResults.filter(r => !r.failed).length,
      },
    }, null, 2));
    console.log(`meta-viewport-report.json written: ${dumpPath}`);

    try {
      const findingsPath = writeMetaViewportFindings(pageResults, OUTPUT_DIR);
      console.log(`test-suite-findings.json written: ${findingsPath}`);
    } catch (err) {
      console.warn(`Could not write test-suite-findings.json: ${err.message}`);
    }

    await context.close();

    // Don't fail the Playwright test on violations — surfaced via the
    // unified findings, not gated here. Matches reflow / alfa-full / pa11y.
    expect(pageResults.length).toBeGreaterThan(0);
  });
});

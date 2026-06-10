/**
 * Self-accessibility meta-test for the unified report shell.
 *
 * The report shell (tests/reports/_shell/index.template.html) is itself an
 * accessibility-sensitive surface — site builders and reviewers will read it
 * with screen readers, keyboards, and zoom. If the report we use to surface
 * a11y bugs has its own a11y bugs, we lose credibility. This meta-test
 * renders the template against representative fixtures, opens every
 * collapsed section so axe sees the full content, and asserts zero
 * violations against WCAG 2.0 / 2.1 A + AA + best-practice tags.
 *
 * Adding new shell features? Run this spec locally before opening a PR.
 */

import { test, expect } from "@playwright/test";
import AxeBuilder from "@axe-core/playwright";
import { readFileSync, writeFileSync } from "fs";
import { join, resolve, dirname } from "path";
import { fileURLToPath } from "url";
import { tmpdir } from "os";

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const SHELL_DIR = resolve(__dirname, "..", "..", "reports", "_shell");
const TEMPLATE_PATH = join(SHELL_DIR, "index.template.html");
const FIXTURES_DIR = join(SHELL_DIR, "fixtures");

const A11Y_TAGS = ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "best-practice"];

function renderToTmp(fixtureName: string): string {
  const template = readFileSync(TEMPLATE_PATH, "utf8");
  const data = readFileSync(join(FIXTURES_DIR, fixtureName), "utf8");
  // Match the runtime escaping the PHP renderer applies — defuse any literal
  // </script> in the JSON so the inline payload can't break out.
  const escaped = data.replace(/<\//g, "<\\/");
  const html = template.replace("{{REPORT_DATA_JSON}}", escaped);
  const out = join(tmpdir(), `report-shell-self-a11y-${Date.now()}-${fixtureName.replace(/\W+/g, "-")}.html`);
  writeFileSync(out, html);
  return out;
}

test.describe("Unified report shell — self-accessibility", () => {
  test("issues view passes WCAG 2.0/2.1 A + AA + best-practice", async ({ page }) => {
    const filePath = renderToTmp("sample-report-data.json");
    await page.goto(`file://${filePath}`);
    await page.waitForLoadState("networkidle");

    // Open every collapsed <details> so axe evaluates the full content,
    // including finding bodies and impact group panels.
    await page.$$eval("details:not([open])", (els) =>
      els.forEach((e) => e.setAttribute("open", "")),
    );

    const results = await new AxeBuilder({ page }).withTags(A11Y_TAGS).analyze();
    expect(results.violations, formatViolations(results.violations)).toEqual([]);
  });

  test("clean-run view passes WCAG 2.0/2.1 A + AA + best-practice", async ({ page }) => {
    const filePath = renderToTmp("sample-clean-run.json");
    await page.goto(`file://${filePath}`);
    await page.waitForLoadState("networkidle");

    const results = await new AxeBuilder({ page }).withTags(A11Y_TAGS).analyze();
    expect(results.violations, formatViolations(results.violations)).toEqual([]);
  });
});

function formatViolations(violations: any[]): string {
  if (!violations.length) return "";
  return violations
    .map((v) => {
      const nodes = v.nodes.map((n: any) => `    ${n.target.join(" → ")}`).join("\n");
      return `  ${v.id} (${v.impact}): ${v.help}\n${nodes}`;
    })
    .join("\n\n");
}

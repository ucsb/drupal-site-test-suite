#!/usr/bin/env node
/**
 * Post-processor that converts pa11y-ci's JSON output into a unified
 * test-suite-findings.json for the test-suite report renderer. Runs after the
 * `utest:pa11y` drush command writes pa11y-report.json.
 *
 * Usage: node emit-findings.js <reportDir> [--html]
 *   <reportDir>: absolute path containing pa11y-report.json
 *   --html: also render pa11y-report.html via the shared findings renderer.
 *     Used by CI, where pa11y-ci runs directly on the runner and the drush
 *     command's bespoke HTML report is never generated. Local drush runs
 *     omit the flag so their bespoke report is not overwritten.
 *
 * Writes <reportDir>/test-suite-findings.json. Exits 0 on success or when no
 * pa11y-report.json exists (informational lane — the unified report
 * just won't show pa11y findings); exits 1 only on parser failure.
 */

import { existsSync, readFileSync, writeFileSync } from "fs";
import { join } from "path";
import { writePa11yFindings } from "../utils/findings-emitter.js";
import { renderFindingsReport } from "../utils/findings-report.js";

const args = process.argv.slice(2);
const wantHtml = args.includes("--html");
const reportDir = args.find((a) => a !== "--html");
if (!reportDir) {
  console.error("Usage: emit-findings.js <reportDir> [--html]");
  process.exit(2);
}

const sourcePath = join(reportDir, "pa11y-report.json");
if (!existsSync(sourcePath)) {
  console.warn(`pa11y-report.json not found at ${sourcePath} — skipping findings emission.`);
  process.exit(0);
}

let parsed;
try {
  parsed = JSON.parse(readFileSync(sourcePath, "utf8"));
} catch (err) {
  console.error(`Failed to parse ${sourcePath}: ${err.message}`);
  process.exit(1);
}

try {
  const target = writePa11yFindings(parsed, reportDir);
  console.log(`Unified pa11y test-suite-findings.json written to: ${target}`);
  if (wantHtml) {
    const payload = JSON.parse(readFileSync(target, "utf8"));
    const htmlPath = join(reportDir, "pa11y-report.html");
    writeFileSync(htmlPath, renderFindingsReport(payload));
    console.log(`pa11y HTML report written to: ${htmlPath}`);
  }
} catch (err) {
  console.error(`Failed to emit test-suite-findings.json: ${err.message}`);
  process.exit(1);
}

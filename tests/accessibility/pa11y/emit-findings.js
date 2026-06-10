#!/usr/bin/env node
/**
 * Post-processor that converts pa11y-ci's JSON output into a unified
 * test-suite-findings.json for the test-suite report renderer. Runs after the
 * `utest:a11y:pa11y` drush command writes pa11y-report.json.
 *
 * Usage: node emit-findings.js <reportDir>
 *   <reportDir>: absolute path containing pa11y-report.json
 *
 * Writes <reportDir>/test-suite-findings.json. Exits 0 on success or when no
 * pa11y-report.json exists (informational lane — the unified report
 * just won't show pa11y findings); exits 1 only on parser failure.
 */

import { existsSync, readFileSync } from "fs";
import { join } from "path";
import { writePa11yFindings } from "../utils/findings-emitter.js";

const reportDir = process.argv[2];
if (!reportDir) {
  console.error("Usage: emit-findings.js <reportDir>");
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
} catch (err) {
  console.error(`Failed to emit test-suite-findings.json: ${err.message}`);
  process.exit(1);
}

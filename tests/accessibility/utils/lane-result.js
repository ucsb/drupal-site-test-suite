#!/usr/bin/env node
/**
 * Prints the gate result for a lane's test-suite-findings.json, one word on
 * stdout. Shared by CI (per-lane result outputs) so every a11y lane gates on
 * the same policy instead of each tool's raw exit code.
 *
 *   failed      critical or serious findings
 *   findings    advisory (moderate/minor) findings only
 *   passed      ran clean
 *   incomplete  did not verify everything (0 pages, errored pages,
 *               missing or unparseable findings file)
 *
 * Incomplete wins over failed: an incomplete run is re-run, not "fixed".
 *
 * Usage: node lane-result.js <findingsDir>
 */

import { existsSync, readFileSync } from "fs";
import { join } from "path";

const dir = process.argv[2];
if (!dir) {
  console.error("Usage: lane-result.js <findingsDir>");
  process.exit(2);
}

const path = join(dir, "test-suite-findings.json");
if (!existsSync(path)) {
  console.log("incomplete");
  process.exit(0);
}

let data;
try {
  data = JSON.parse(readFileSync(path, "utf8"));
} catch {
  console.log("incomplete");
  process.exit(0);
}

const summary = data.summary || {};
if (summary.status === "incomplete") {
  console.log("incomplete");
  process.exit(0);
}

const totals = summary.totals_by_severity || {};
if ((totals.critical || 0) + (totals.serious || 0) > 0) {
  console.log("failed");
  process.exit(0);
}

if (Array.isArray(data.findings) && data.findings.length > 0) {
  console.log("findings");
  process.exit(0);
}

console.log("passed");

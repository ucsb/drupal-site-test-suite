#!/usr/bin/env node
/**
 * Custom-module PHPUnit (Functional / Regression) runner. Standalone — no Drupal
 * bootstrap — so it runs in CI without a deployed site (Unit + Kernel tests only
 * need PHP + the code + SQLite). Discovers tests under web/modules/custom and
 * web/profiles/custom, runs them, and writes the unified findings.json.
 *
 * Report-only: always exits 0. Run from the project root.
 *   node tests/phpunit/run.js [reportDir]
 */
import { existsSync, readFileSync, writeFileSync, mkdirSync, rmSync } from "fs";
import { join, resolve } from "path";
import { execFileSync } from "child_process";
import fg from "fast-glob";
import { writePhpunitFindings } from "../accessibility/utils/findings-emitter.js";

const root = resolve(process.cwd());
// Resolve to absolute — Drupal's test bootstrap changes the working directory,
// so a relative --log-junit path (e.g. from CI) would land somewhere Node won't
// find it afterward.
const reportDir = resolve(root, process.argv[2] || "web/sites/default/files/test-reports/phpunit");
mkdirSync(reportDir, { recursive: true });

const finish = (xml) => {
  const out = writePhpunitFindings(xml || "", {}, reportDir);
  console.log(`Wrote ${out}`);
  process.exit(0);
};

const phpunitBin = join(root, "vendor/bin/phpunit");
const bootstrap = join(root, "web/core/tests/bootstrap.php");

// Preflight — fail-soft (downstream sites may be installed without dev deps).
if (!existsSync(phpunitBin)) {
  console.warn("PHPUnit not installed (dev dependencies). Skipping (report-only).");
  finish("");
}
if (!existsSync(bootstrap)) {
  console.warn(`Drupal core test bootstrap not found (${bootstrap}). Skipping.`);
  finish("");
}

// Kernel tests need a database; the config uses SQLite, so only pdo_sqlite is
// required. Unit tests need nothing.
let hasSqlite = false;
try {
  hasSqlite = execFileSync("php", ["-r", "echo extension_loaded('pdo_sqlite') ? 1 : 0;"]).toString().trim() === "1";
} catch { /* php missing — phpunit run below will surface it */ }
const types = hasSqlite ? ["Unit", "Kernel"] : ["Unit"];
if (!hasSqlite) console.log("pdo_sqlite unavailable — running Unit tests only.");

// Discover custom test dirs (phpunit 9 won't expand globs in config, so resolve
// here and pass absolute paths).
const patterns = [];
for (const base of ["web/modules/custom", "web/profiles/custom"]) {
  for (const t of types) {
    for (const depth of ["*", "*/*", "*/modules/*", "*/modules/*/*"]) {
      patterns.push(`${base}/${depth}/tests/src/${t}`);
    }
  }
}
const dirs = [...new Set(fg.sync(patterns, {
  onlyDirectories: true, cwd: root, absolute: true,
  ignore: ["**/node_modules/**", "**/vendor/**"],
}))];

if (!dirs.length) {
  console.log("No custom-module Unit/Kernel tests found.");
  finish("");
}
console.log(`${dirs.length} custom test directory(ies).`);

const esc = (s) => String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
const sqlitePath = join(reportDir, "kernel.sqlite");
rmSync(sqlitePath, { force: true });
const dirXml = dirs.map((d) => `      <directory>${esc(d)}</directory>`).join("\n");
const config = `<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="${esc(bootstrap)}" colors="true" cacheResult="false" beStrictAboutTestsThatDoNotTestAnything="false">
  <php>
    <ini name="memory_limit" value="-1"/>
    <env name="SIMPLETEST_BASE_URL" value="http://localhost"/>
    <env name="SIMPLETEST_DB" value="sqlite://localhost/${esc(sqlitePath)}"/>
    <env name="SYMFONY_DEPRECATIONS_HELPER" value="weak"/>
  </php>
  <testsuites>
    <testsuite name="custom">
${dirXml}
    </testsuite>
  </testsuites>
</phpunit>
`;
const configPath = join(reportDir, "phpunit.generated.xml");
writeFileSync(configPath, config);

const junitPath = join(reportDir, "junit.xml");
rmSync(junitPath, { force: true });
try {
  // Report-only: a non-zero exit means test failures, which we capture below.
  execFileSync(phpunitBin, ["-c", configPath, "--log-junit", junitPath], { cwd: root, stdio: "inherit" });
} catch { /* test failures — expected, handled via junit */ }

const xml = existsSync(junitPath) ? readFileSync(junitPath, "utf8") : "";
rmSync(configPath, { force: true });
rmSync(sqlitePath, { force: true });
finish(xml);

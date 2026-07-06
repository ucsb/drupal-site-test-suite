#!/usr/bin/env node
/**
 * Custom-code PHPUnit (Functional / Regression) runner. Standalone, no Drupal
 * bootstrap, so it runs in CI without a deployed site (Unit + Kernel tests only
 * need PHP + the code + SQLite). Discovers tests under custom modules, themes,
 * and profiles, runs them, and writes the unified findings.json.
 *
 * Report-only: always exits 0. Run from the project root.
 *   node tests/phpunit/run.js [reportDir]
 *   node tests/phpunit/run.js [reportDir] --modules=my_custom_module --themes=my_theme --profiles=my_profile
 */
import { existsSync, readFileSync, writeFileSync, mkdirSync, rmSync } from "fs";
import { dirname, join, resolve } from "path";
import { execFileSync } from "child_process";
import fg from "fast-glob";
import { writePhpunitFindings } from "../accessibility/utils/findings-emitter.js";

const root = resolve(process.cwd());

function splitScope(values) {
  return [...new Set(values
    .flatMap((value) => String(value).split(/[\s,]+/))
    .map((value) => value.trim())
    .filter(Boolean))];
}

function parseArgs(argv) {
  let reportDirArg = "web/sites/default/files/test-reports/phpunit";
  const scope = { modules: [], themes: [], profiles: [] };
  const optionTypes = {
    module: "modules",
    modules: "modules",
    theme: "themes",
    themes: "themes",
    profile: "profiles",
    profiles: "profiles",
  };

  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];
    const equals = arg.match(/^--([^=]+)=(.*)$/);
    if (equals && optionTypes[equals[1]]) {
      scope[optionTypes[equals[1]]].push(equals[2]);
      continue;
    }
    if (arg.startsWith("--") && optionTypes[arg.slice(2)]) {
      scope[optionTypes[arg.slice(2)]].push(argv[++i] || "");
      continue;
    }
    if (!arg.startsWith("--")) {
      reportDirArg = arg;
    }
  }

  return {
    reportDirArg,
    selectedModules: splitScope(scope.modules),
    selectedThemes: splitScope(scope.themes),
    selectedProfiles: splitScope(scope.profiles),
  };
}

const {
  reportDirArg,
  selectedModules,
  selectedThemes,
  selectedProfiles,
} = parseArgs(process.argv.slice(2));

// Resolve to absolute; Drupal's test bootstrap changes the working directory,
// so a relative --log-junit path (e.g. from CI) would land somewhere Node won't
// find it afterward.
const reportDir = resolve(root, reportDirArg);
mkdirSync(reportDir, { recursive: true });

const finish = (xml) => {
  const out = writePhpunitFindings(xml || "", {}, reportDir);
  console.log(`Wrote ${out}`);
  process.exit(0);
};

const phpunitBin = join(root, "vendor/bin/phpunit");
const bootstrap = join(root, "web/core/tests/bootstrap.php");

// Preflight: fail-soft (downstream sites may be installed without dev deps).
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
} catch { /* php missing; phpunit run below will surface it */ }
const types = hasSqlite ? ["Unit", "Kernel"] : ["Unit"];
if (!hasSqlite) console.log("pdo_sqlite unavailable; running Unit tests only.");

function testDirsForRoots(roots) {
  const dirs = [];
  for (const componentRoot of [...new Set(roots)]) {
    for (const t of types) {
      const dir = join(componentRoot, "tests", "src", t);
      if (existsSync(dir)) dirs.push(dir);
    }
  }
  return dirs;
}

function discoverAllCustomTestDirs() {
  // Discover custom test dirs (phpunit 9 won't expand globs in config, so
  // resolve here and pass absolute paths).
  const patterns = [];
  const bases = ["web/modules/custom", "web/profiles/custom", "web/themes"];
  for (const base of bases) {
    for (const t of types) {
      for (const depth of ["*", "*/*", "*/modules/*", "*/modules/*/*", "*/themes/*"]) {
        patterns.push(`${base}/${depth}/tests/src/${t}`);
      }
    }
  }
  return fg.sync(patterns, {
    onlyDirectories: true, cwd: root, absolute: true,
    ignore: ["**/node_modules/**", "**/vendor/**", "web/themes/contrib/**"],
  });
}

function discoverScopedRoots(type, names, patternsForName) {
  const validName = /^[a-z][a-z0-9_]*$/;
  const roots = [];
  const missing = [];
  const invalid = [];

  for (const name of names) {
    if (!validName.test(name)) {
      invalid.push(name);
      continue;
    }

    const infoFiles = fg.sync(patternsForName(name), {
      onlyFiles: true, cwd: root, absolute: true,
      ignore: ["**/node_modules/**", "**/vendor/**", "web/themes/contrib/**"],
    });

    if (!infoFiles.length) {
      missing.push(name);
      continue;
    }

    for (const infoFile of infoFiles) {
      roots.push(dirname(infoFile));
    }
  }

  if (invalid.length) {
    console.warn(`Invalid ${type} machine name(s), skipped: ${invalid.join(", ")}`);
  }
  if (missing.length) {
    console.warn(`Custom ${type}(s) not found, skipped: ${missing.join(", ")}`);
  }

  return roots;
}

function discoverScopedCustomTestDirs(scope) {
  const roots = [
    ...discoverScopedRoots("module", scope.modules, (name) => [
      `web/modules/custom/**/${name}/${name}.info.yml`,
      `web/profiles/custom/**/modules/**/${name}/${name}.info.yml`,
    ]),
    ...discoverScopedRoots("theme", scope.themes, (name) => [
      `web/themes/**/${name}/${name}.info.yml`,
      `web/profiles/custom/**/themes/**/${name}/${name}.info.yml`,
    ]),
    ...discoverScopedRoots("profile", scope.profiles, (name) => [
      `web/profiles/custom/**/${name}/${name}.info.yml`,
    ]),
  ];

  return testDirsForRoots(roots);
}

const selectedScope = {
  modules: selectedModules,
  themes: selectedThemes,
  profiles: selectedProfiles,
};
const isScopedRun = Object.values(selectedScope).some((items) => items.length > 0);

if (isScopedRun) {
  const labels = [];
  if (selectedModules.length) labels.push(`modules: ${selectedModules.join(", ")}`);
  if (selectedThemes.length) labels.push(`themes: ${selectedThemes.join(", ")}`);
  if (selectedProfiles.length) labels.push(`profiles: ${selectedProfiles.join(", ")}`);
  console.log(`Scoped custom-code filter: ${labels.join("; ")}`);
}

const dirs = [...new Set(isScopedRun
  ? discoverScopedCustomTestDirs(selectedScope)
  : discoverAllCustomTestDirs())];

if (!dirs.length) {
  const scope = isScopedRun ? "selected custom code" : "custom modules/themes/profiles";
  console.log(`No ${scope} Unit/Kernel tests found.`);
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
} catch { /* test failures expected, handled via junit */ }

const xml = existsSync(junitPath) ? readFileSync(junitPath, "utf8") : "";
rmSync(configPath, { force: true });
rmSync(sqlitePath, { force: true });
finish(xml);

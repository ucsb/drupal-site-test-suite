/**
 * findings-emitter — converts a single test's tool-native results into the
 * unified test-suite-findings.json contract (tests/reports/_shell/findings.schema.json).
 *
 * Each test (alfa, axe, pa11y, lint, etc.) writes its own test-suite-findings.json
 * next to its existing HTML/JSON report. A downstream renderer aggregates
 * them all and feeds the result into index.template.html to produce the
 * unified report.
 */

import { readFileSync, writeFileSync } from "fs";
import { join, resolve, dirname } from "path";
import { fileURLToPath } from "url";
import { execSync } from "child_process";

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

// rule-headlines.json lives alongside the unified report shell.
const RULE_HEADLINES_PATH = resolve(
  __dirname, "..", "..", "reports", "_shell", "rule-headlines.json"
);

let _ruleHeadlinesCache = null;
function loadRuleHeadlines() {
  if (_ruleHeadlinesCache) return _ruleHeadlinesCache;
  try {
    const raw = readFileSync(RULE_HEADLINES_PATH, "utf8");
    _ruleHeadlinesCache = JSON.parse(raw).rules || {};
  } catch (e) {
    console.warn(`⚠️  Could not load rule-headlines.json: ${e.message}`);
    _ruleHeadlinesCache = {};
  }
  return _ruleHeadlinesCache;
}

/**
 * Look up a plain-language override for `<test>:<rule_id>`. Returns the
 * override object (with optional headline / impact_category / severity /
 * fix_hint / tags) or an empty object if no override exists.
 *
 * Falls back from variant test names to their base family so a single
 * `axe:image-alt` entry covers `axe`, `axe-full`, `axe-watcher`, and
 * `axe-watcher-full` without duplicating the override 4x. Same idea for
 * `alfa` → `alfa-full`. Per-test overrides win when present.
 */
export function getHeadlineOverride(testName, ruleId) {
  const headlines = loadRuleHeadlines();
  const direct = headlines[`${testName}:${ruleId}`];
  if (direct) return direct;
  // Strip variant suffixes: -full, -watcher, -watcher-full → base family.
  const baseTest = testName.replace(/-(?:watcher-full|watcher|full)$/, "");
  if (baseTest !== testName) {
    const base = headlines[`${baseTest}:${ruleId}`];
    if (base) return base;
  }
  return {};
}

/**
 * Detect which surface the run is executing on, so the report banner can
 * label it correctly. Three contexts:
 *   - MULTIDEV_URL set        → ci-multidev (CI testing a preview/multidev environment)
 *   - else, GitHub Actions/CI → ci (repo-wide CI run, no deployed site —
 *                                e.g. the lint job, which runs before any
 *                                multidev exists; base_url is the Actions run)
 *   - else                    → local (developer machine; base_url tells you where)
 *
 * The earlier "local-upstream" vs "local-site" split was a URL-pattern
 * heuristic that misclassified .ddev.site/.lndo.site/staging hosts. The
 * useful signal is the deploy target (multidev) plus whether we're in CI —
 * base_url carries the rest.
 */
export function buildSurface() {
  const baseUrl = process.env.BASE_URL || "";
  const multidevUrl = process.env.MULTIDEV_URL || "";
  const branch = process.env.GIT_BRANCH
    || process.env.GITHUB_HEAD_REF
    || detectGitBranch()
    || "";

  if (multidevUrl) {
    const prNumber = process.env.PR_NUMBER || process.env.GITHUB_PR_NUMBER || "";
    return {
      context: "ci-multidev",
      label: prNumber ? `pr-${prNumber}` : (branch || "multidev"),
      base_url: multidevUrl
    };
  }

  // Repo-wide CI (no multidev). GitHub Actions sets GITHUB_ACTIONS; CI is the
  // generic fallback for other runners. Link the banner to the Actions run so
  // an artifact opened from the PR comment is traceable back to its build.
  if (process.env.GITHUB_ACTIONS || process.env.CI) {
    const runUrl = (process.env.GITHUB_SERVER_URL && process.env.GITHUB_REPOSITORY && process.env.GITHUB_RUN_ID)
      ? `${process.env.GITHUB_SERVER_URL}/${process.env.GITHUB_REPOSITORY}/actions/runs/${process.env.GITHUB_RUN_ID}`
      : "";
    return {
      context: "ci",
      label: branch ? `${branch} branch` : undefined,
      base_url: runUrl || baseUrl || undefined
    };
  }

  return {
    context: "local",
    label: branch ? `${branch} branch` : undefined,
    base_url: baseUrl || undefined
  };
}

function detectGitBranch() {
  try {
    return execSync("git branch --show-current", { encoding: "utf8", stdio: ["ignore", "pipe", "ignore"] })
      .trim() || null;
  } catch (e) { return null; }
}

/**
 * Compute summary rollups (totals_by_severity, totals_by_impact) from a
 * findings array. The shell expects these so it doesn't have to re-aggregate.
 */
export function computeSummary(findings, { pages_tested, files_scanned } = {}) {
  const totals_by_severity = { critical: 0, serious: 0, moderate: 0, minor: 0 };
  const totals_by_impact   = {
    "screen-reader": 0, "keyboard": 0, "low-vision": 0,
    "content": 0, "code-quality": 0, "security": 0, "uncategorized": 0
  };
  for (const f of findings) {
    if (totals_by_severity[f.severity] !== undefined) totals_by_severity[f.severity]++;
    const ic = f.impact_category || "uncategorized";
    if (totals_by_impact[ic] !== undefined) totals_by_impact[ic]++;
  }
  const summary = {
    totals_by_severity, totals_by_impact,
    status: findings.length === 0 ? "pass" : "findings-found"
  };
  if (typeof pages_tested === "number") summary.pages_tested = pages_tested;
  if (typeof files_scanned === "number") summary.files_scanned = files_scanned;
  return summary;
}

/**
 * Convert an Alfa full-site/key-pages report (the shape produced by
 * alfa-full-site.spec.js / alfa-accessibility.spec.js) into a test-suite-findings.json
 * payload and write it to disk.
 *
 * @param {object}  alfaReport   The existing { summary, results, ...} report.
 * @param {object}  profileInfo  { name, description, profile } from getProfileInfo().
 * @param {object}  alfaConfig   { tags, severity, options } from getAccessibilityConfig('alfa').
 * @param {string}  outputDir    Where to write test-suite-findings.json.
 * @param {string}  testName     'alfa' or 'alfa-full'.
 */
// Official Siteimprove Alfa rule titles (e.g. SIA-R53 -> "Headings are
// structured"), loaded once from the static map generated from the pinned SDK.
// Missing entries fall back to the rule id, so an unmapped/new rule degrades
// gracefully rather than breaking. Regenerate the map after bumping
// @siteimprove/alfa-rules (see alfa/generate-rule-titles.mjs).
let _alfaRuleTitles;
function alfaRuleTitle(ruleId) {
  if (_alfaRuleTitles === undefined) {
    try {
      _alfaRuleTitles = JSON.parse(readFileSync(new URL("../alfa/rule-titles.json", import.meta.url), "utf8"));
    } catch {
      _alfaRuleTitles = {};
    }
  }
  return _alfaRuleTitles[ruleId] || null;
}

export function writeAlfaFindings(alfaReport, profileInfo, alfaConfig, outputDir, testName) {
  const test = testName || "alfa-full";

  // Group issues across all pages by rule_id. Each issue carries a ruleInfo
  // object plus a `failed` count for that page; we accumulate locations.
  // The full-site spec stores pages under `results`; the key-pages spec uses
  // `pages` — accept either.
  const byRule = new Map();
  const pageResults = alfaReport.results || alfaReport.pages || [];
  for (const page of pageResults) {
    if (!page.issues || page.issues.length === 0) continue;
    for (const issue of page.issues) {
      // Only emit findings for issues that match the configured severity
      // levels — otherwise a "fail-on-critical" CI run would still publish
      // moderate/minor findings the user told the test to ignore.
      const configuredSeverities = new Set(alfaConfig.severity || ["critical", "serious"]);
      if (!configuredSeverities.has(issue.severity)) continue;

      const ruleId = issue.ruleInfo?.id || extractRuleIdFromUrl(issue.rule);
      if (!ruleId) continue;

      let entry = byRule.get(ruleId);
      if (!entry) {
        entry = { ruleId, ruleUrl: issue.rule, ruleInfo: issue.ruleInfo, locations: [] };
        byRule.set(ruleId, entry);
      }
      entry.locations.push({
        kind: "page",
        path: page.path,
        occurrences: issue.failed || 1
      });
    }
  }

  const findings = [];
  for (const { ruleId, ruleUrl, ruleInfo, locations } of byRule.values()) {
    const override = getHeadlineOverride(test, ruleId);
    const occurrences = locations.reduce((s, l) => s + (l.occurrences || 1), 0);
    findings.push({
      id: `${test}:${ruleId}`,
      rule_id: ruleId,
      rule_url: ruleUrl,
      severity: override.severity || ruleInfo?.severity || "minor",
      impact_category: override.impact_category || "uncategorized",
      headline: alfaRuleTitle(ruleId) || override.headline || ruleInfo?.title || ruleId,
      description: ruleInfo?.description || "",
      fix_hint: override.fix_hint || (ruleInfo?.fixRecommendations?.[0] || ""),
      wcag_criteria: ruleInfo?.wcagCriteria || [],
      ...(override.tags?.length ? { tags: override.tags.slice() } : {}),
      occurrences,
      locations
    });
  }

  // Stable sort: severity desc, then alphabetical by rule_id.
  const sevOrder = { critical: 0, serious: 1, moderate: 2, minor: 3 };
  findings.sort((a, b) => {
    const s = (sevOrder[a.severity] ?? 9) - (sevOrder[b.severity] ?? 9);
    return s !== 0 ? s : a.rule_id.localeCompare(b.rule_id);
  });

  const payload = {
    schema_version: "1.0",
    test,
    tool: "siteimprove-alfa",
    surface: buildSurface(),
    profile: {
      key: profileInfo?.profile || process.env.A11Y_PROFILE || "comprehensive",
      name: profileInfo?.name || "",
      tags: alfaConfig?.tags || [],
      severity_levels: alfaConfig?.severity || ["critical", "serious"]
    },
    generated_at: new Date().toISOString(),
    duration_ms: typeof alfaReport.summary?.duration === "number"
      ? alfaReport.summary.duration : undefined,
    summary: computeSummary(findings, {
      pages_tested: alfaReport.summary?.totalPages ?? pageResults.length
    }),
    findings
  };

  const target = join(outputDir, "test-suite-findings.json");
  writeFileSync(target, JSON.stringify(payload, null, 2));
  return target;
}

/**
 * Convert axe-core results (one entry per page tested) into a
 * test-suite-findings.json payload. Each axe rule becomes one finding; each page
 * + selector where the rule fired becomes a location.
 *
 * @param {Array}   pageResults  Per-page results in the shape produced by
 *                               a11y.spec.ts (`{ path, url, details: [{ id, description, impact, helpUrl, nodes: [{ target, html }] }] }`).
 * @param {object}  axeConfig    `{ tags, severity }` from a11y-profiles.
 * @param {object}  profileInfo  `{ name, description, profile }`.
 * @param {string}  outputDir    Where to write test-suite-findings.json.
 * @param {string}  testName     'axe' / 'axe-watcher-full' / etc.
 */
export function writeAxeFindings(pageResults, axeConfig, profileInfo, outputDir, testName) {
  const test = testName || "axe";

  // Group violations by rule_id. axe emits the same id once per page +
  // once per offending node within that page, so we accumulate
  // locations and dedupe by (path, selector).
  const byRule = new Map();
  for (const page of pageResults || []) {
    for (const v of page.details || []) {
      const ruleId = v.id;
      if (!ruleId) continue;
      let entry = byRule.get(ruleId);
      if (!entry) {
        entry = {
          ruleId,
          rule_url: v.helpUrl || "",
          description: v.description || "",
          impact: v.impact || "moderate",
          locations: []
        };
        byRule.set(ruleId, entry);
      }
      const nodes = v.nodes && v.nodes.length ? v.nodes : [{ target: "", html: "" }];
      for (const n of nodes) {
        entry.locations.push({
          kind: "page",
          path: page.path || page.url || "",
          selector: typeof n.target === "string" ? n.target : (Array.isArray(n.target) ? n.target.join(" > ") : ""),
          occurrences: 1
        });
      }
    }
  }

  const findings = [];
  for (const { ruleId, rule_url, description, impact, locations } of byRule.values()) {
    const override = getHeadlineOverride(test, ruleId);
    findings.push({
      id: `${test}:${ruleId}`,
      rule_id: ruleId,
      ...(rule_url ? { rule_url } : {}),
      severity: override.severity || mapAxeImpact(impact),
      impact_category: override.impact_category || "uncategorized",
      headline: override.headline || description || ruleId,
      description,
      fix_hint: override.fix_hint || "",
      wcag_criteria: [],
      ...(override.tags?.length ? { tags: override.tags.slice() } : {}),
      occurrences: locations.length,
      locations
    });
  }

  const sevOrder = { critical: 0, serious: 1, moderate: 2, minor: 3 };
  findings.sort((a, b) => {
    const s = (sevOrder[a.severity] ?? 9) - (sevOrder[b.severity] ?? 9);
    return s !== 0 ? s : a.rule_id.localeCompare(b.rule_id);
  });

  const payload = {
    schema_version: "1.0",
    test,
    tool: "axe-core",
    surface: buildSurface(),
    profile: {
      key: profileInfo?.profile || process.env.A11Y_PROFILE || "comprehensive",
      name: profileInfo?.name || "",
      tags: axeConfig?.tags || [],
      severity_levels: axeConfig?.severity || ["critical", "serious"]
    },
    generated_at: new Date().toISOString(),
    summary: computeSummary(findings, {
      pages_tested: pageResults?.length
    }),
    findings
  };

  const target = join(outputDir, "test-suite-findings.json");
  writeFileSync(target, JSON.stringify(payload, null, 2));
  return target;
}

function mapAxeImpact(impact) {
  // axe's impact taxonomy already matches the unified schema's severity
  // tiers — pass through, defaulting to "moderate" when unset.
  if (impact === "critical" || impact === "serious"
    || impact === "moderate" || impact === "minor") return impact;
  return "moderate";
}

/**
 * Convert pa11y-ci JSON output into a test-suite-findings.json payload. Each rule
 * code (`code`) becomes one finding; each issue (page + selector + line)
 * becomes a location.
 *
 * @param {object}  pa11yReport  pa11y-ci's `--reporter json` output:
 *                               `{ total, passes, errors, results: { url: [issue, ...] } }`.
 * @param {string}  outputDir    Where to write test-suite-findings.json.
 */
export function writePa11yFindings(pa11yReport, outputDir) {
  const test = "pa11y";

  const byRule = new Map();
  for (const [url, issues] of Object.entries(pa11yReport?.results || {})) {
    for (const issue of issues || []) {
      const ruleId = issue.code || "unknown";
      let entry = byRule.get(ruleId);
      if (!entry) {
        entry = {
          ruleId,
          rawType: issue.type || "error",
          message: issue.message || "",
          locations: []
        };
        byRule.set(ruleId, entry);
      }
      // pa11y returns a relative URL fragment via `context` and a CSS
      // selector — surface both. `path` holds the page URL.
      entry.locations.push({
        kind: "page",
        path: pageUrlToPath(url),
        selector: issue.selector || "",
        line: issue.context ? extractLineFromContext(issue.context) : undefined,
        occurrences: 1
      });
    }
  }

  const findings = [];
  for (const { ruleId, rawType, message, locations } of byRule.values()) {
    const override = getHeadlineOverride(test, ruleId);
    findings.push({
      id: `${test}:${ruleId}`,
      rule_id: ruleId,
      severity: override.severity || mapPa11yType(rawType),
      impact_category: override.impact_category || "uncategorized",
      headline: override.headline || message || ruleId,
      description: message,
      fix_hint: override.fix_hint || "",
      wcag_criteria: [],
      ...(override.tags?.length ? { tags: override.tags.slice() } : {}),
      occurrences: locations.length,
      locations
    });
  }

  const sevOrder = { critical: 0, serious: 1, moderate: 2, minor: 3 };
  findings.sort((a, b) => {
    const s = (sevOrder[a.severity] ?? 9) - (sevOrder[b.severity] ?? 9);
    return s !== 0 ? s : a.rule_id.localeCompare(b.rule_id);
  });

  const pagesTested = Object.keys(pa11yReport?.results || {}).length;
  const payload = {
    schema_version: "1.0",
    test,
    tool: "pa11y-ci",
    surface: buildSurface(),
    generated_at: new Date().toISOString(),
    summary: computeSummary(findings, { pages_tested: pagesTested }),
    findings
  };

  const target = join(outputDir, "test-suite-findings.json");
  writeFileSync(target, JSON.stringify(payload, null, 2));
  return target;
}

function mapPa11yType(t) {
  // pa11y emits 'error' / 'warning' / 'notice'. Map to the unified scale.
  if (t === "error") return "serious";
  if (t === "warning") return "moderate";
  return "minor";
}

function pageUrlToPath(url) {
  if (!url) return "";
  try {
    return new URL(url).pathname || url;
  } catch (_e) {
    return url;
  }
}

function extractLineFromContext(_ctx) {
  // pa11y's `context` is the offending HTML snippet, not a line number.
  // We leave `line` undefined; the snippet appears in `description` /
  // `headline`. If pa11y ever surfaces line numbers in its JSON, parse
  // them here.
  return undefined;
}

function extractRuleIdFromUrl(ruleUrl) {
  if (!ruleUrl) return null;
  const m = String(ruleUrl).match(/sia-r\d+/i);
  return m ? m[0].toUpperCase() : null;
}

/**
 * Convert lint-orchestrator findings (PHPCS + PHPStan + ESLint + cspell
 * + composer + markdownlint + actionlint) into a unified test-suite-findings.json
 * payload for the lint test. Each tool's rule becomes one finding with
 * one location per file:line where it triggered.
 *
 * @param {object}  inputs         { phpcs, phpstan, eslint, cspell, composer, markdownlint, actionlint } (each Array).
 * @param {object}  meta           { files_scanned, duration_ms }.
 * @param {string}  outputDir      Where to write test-suite-findings.json.
 */
export function writeLintFindings(inputs, meta, outputDir) {
  const test = "lint";
  const phpcsFindings = inputs?.phpcs || [];
  const phpstanFindings = inputs?.phpstan || [];
  const deprecationFindings = inputs?.deprecations || [];
  const referenceFindings = inputs?.references || [];
  const configFindings = inputs?.config || [];
  const permissionFindings = inputs?.permissions || [];
  const eslintFindings = inputs?.eslint || [];
  const cspellFindings = inputs?.cspell || [];
  const composerFindings = inputs?.composer || [];
  const markdownlintFindings = inputs?.markdownlint || [];
  const actionlintFindings = inputs?.actionlint || [];

  const findings = [
    ...groupPhpcsFindings(test, phpcsFindings),
    ...groupPhpStanFindings(test, phpstanFindings),
    ...groupDeprecationFindings(test, deprecationFindings),
    ...groupReferenceFindings(test, referenceFindings),
    ...groupConfigFindings(test, configFindings),
    ...groupPermissionFindings(test, permissionFindings),
    ...groupEslintFindings(test, eslintFindings),
    ...groupCspellFindings(test, cspellFindings),
    ...groupComposerFindings(test, composerFindings),
    ...groupMarkdownlintFindings(test, markdownlintFindings),
    ...groupActionlintFindings(test, actionlintFindings),
  ];

  const sevOrder = { critical: 0, serious: 1, moderate: 2, minor: 3 };
  findings.sort((a, b) => {
    const s = (sevOrder[a.severity] ?? 9) - (sevOrder[b.severity] ?? 9);
    return s !== 0 ? s : a.id.localeCompare(b.id);
  });

  // Include every tool that ran in this pass, even when it produced zero
  // findings — the report banner reflects which tools were exercised, not
  // which happened to find issues this run.
  const tools = [];
  if (Array.isArray(inputs?.phpcs)) tools.push("phpcs");
  if (Array.isArray(inputs?.phpstan)) tools.push("phpstan");
  if (Array.isArray(inputs?.deprecations)) tools.push("deprecations");
  if (Array.isArray(inputs?.references)) tools.push("references");
  if (Array.isArray(inputs?.config)) tools.push("config");
  if (Array.isArray(inputs?.permissions)) tools.push("permissions");
  if (Array.isArray(inputs?.eslint)) tools.push("eslint");
  if (Array.isArray(inputs?.cspell)) tools.push("cspell");
  if (Array.isArray(inputs?.composer)) tools.push("composer");
  if (Array.isArray(inputs?.markdownlint)) tools.push("markdownlint");
  if (Array.isArray(inputs?.actionlint)) tools.push("actionlint");

  const payload = {
    schema_version: "1.0",
    test,
    tool: tools.join("+") || "phpcs",
    surface: buildSurface(),
    generated_at: new Date().toISOString(),
    duration_ms: typeof meta?.duration_ms === "number" ? meta.duration_ms : undefined,
    summary: computeSummary(findings, {
      files_scanned: typeof meta?.files_scanned === "number" ? meta.files_scanned : undefined
    }),
    findings
  };

  const target = join(outputDir, "test-suite-findings.json");
  writeFileSync(target, JSON.stringify(payload, null, 2));
  return target;
}

function groupPhpcsFindings(test, phpcsFindings) {
  const byRule = new Map();
  for (const f of phpcsFindings) {
    let entry = byRule.get(f.rule_id);
    if (!entry) {
      entry = {
        rule_id: f.rule_id,
        // First-seen severity is used as the rule's representative severity;
        // PHPCS rules typically emit one type consistently.
        raw_severity: f.severity,
        message: f.message,
        fixable: f.fixable,
        locations: []
      };
      byRule.set(f.rule_id, entry);
    }
    entry.locations.push({
      kind: "file",
      path: scopedPath(f),
      line: f.line,
      column: f.column,
      occurrences: 1
    });
  }

  const out = [];
  for (const { rule_id, raw_severity, message, fixable, locations } of byRule.values()) {
    const override = getHeadlineOverride(test, `phpcs:${rule_id}`);
    out.push({
      id: `lint:phpcs:${rule_id}`,
      rule_id,
      severity: override.severity || mapPhpcsSeverity(raw_severity),
      impact_category: override.impact_category || "code-quality",
      headline: override.headline || message || rule_id,
      description: message || "",
      fix_hint: override.fix_hint
        || (fixable ? "Run `vendor/bin/phpcbf` to auto-fix this rule where possible." : ""),
      wcag_criteria: [],
      ...(override.tags?.length ? { tags: override.tags.slice() } : {}),
      occurrences: locations.length,
      locations
    });
  }
  return out;
}

function groupPhpStanFindings(test, phpstanFindings) {
  const byRule = new Map();
  for (const f of phpstanFindings) {
    const ruleId = f.rule_id || "uncategorized";
    let entry = byRule.get(ruleId);
    if (!entry) {
      entry = {
        rule_id: ruleId,
        message: f.message,
        locations: []
      };
      byRule.set(ruleId, entry);
    }
    entry.locations.push({
      kind: "file",
      path: f.path,
      line: f.line,
      occurrences: 1
    });
  }

  const out = [];
  for (const { rule_id, message, locations } of byRule.values()) {
    const override = getHeadlineOverride(test, `phpstan:${rule_id}`);
    out.push({
      id: `lint:phpstan:${rule_id}`,
      rule_id,
      severity: override.severity || "moderate",
      impact_category: override.impact_category || "code-quality",
      headline: override.headline || message || rule_id,
      description: message || "",
      fix_hint: override.fix_hint || "",
      wcag_criteria: [],
      ...(override.tags?.length ? { tags: override.tags.slice() } : {}),
      occurrences: locations.length,
      locations
    });
  }
  return out;
}

// Deprecation findings (PHPStan deprecation rules; identifiers like
// `method.deprecated`) kept in their own lane, separate from PHPStan.
function groupDeprecationFindings(test, deprecationFindings) {
  const byRule = new Map();
  for (const f of deprecationFindings) {
    const ruleId = f.rule_id || "deprecated";
    let entry = byRule.get(ruleId);
    if (!entry) {
      entry = { rule_id: ruleId, message: f.message, severity: f.severity, locations: [] };
      byRule.set(ruleId, entry);
    }
    entry.locations.push({ kind: "file", path: f.path, line: f.line, occurrences: 1 });
  }

  const out = [];
  for (const { rule_id, message, severity, locations } of byRule.values()) {
    const override = getHeadlineOverride(test, `deprecations:${rule_id}`);
    out.push({
      id: `lint:deprecations:${rule_id}`,
      rule_id,
      severity: override.severity || severity || "moderate",
      impact_category: override.impact_category || "code-quality",
      headline: override.headline || "Deprecated API usage — update before the next Drupal major",
      description: message || "",
      fix_hint: override.fix_hint || "Replace the deprecated symbol per its @deprecated change-record note.",
      wcag_criteria: [],
      ...(override.tags?.length ? { tags: override.tags.slice() } : {}),
      occurrences: locations.length,
      locations
    });
  }
  return out;
}

// Broken references (e.g. missing library assets), grouped per distinct message
// so each broken reference is its own finding.
function groupReferenceFindings(test, referenceFindings) {
  const byKey = new Map();
  for (const f of referenceFindings) {
    const key = f.message || f.rule_id || "reference";
    let entry = byKey.get(key);
    if (!entry) {
      entry = { rule_id: f.rule_id || "reference", message: f.message, severity: f.severity, locations: [] };
      byKey.set(key, entry);
    }
    entry.locations.push({ kind: "file", path: f.path, line: f.line, occurrences: 1 });
  }

  const out = [];
  for (const { rule_id, message, severity, locations } of byKey.values()) {
    const override = getHeadlineOverride(test, `references:${rule_id}`);
    const slug = String(message || rule_id).toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "").slice(0, 64);
    out.push({
      id: `lint:references:${slug || rule_id}`,
      rule_id,
      severity: override.severity || severity || "serious",
      impact_category: override.impact_category || "code-quality",
      headline: override.headline || message || "Broken reference",
      description: message || "",
      fix_hint: override.fix_hint || "Fix the path or remove the dangling reference.",
      wcag_criteria: [],
      occurrences: locations.length,
      locations
    });
  }
  return out;
}

function groupConfigFindings(test, configFindings) {
  const byRule = new Map();
  for (const f of configFindings) {
    const key = f.rule_id || "config";
    let entry = byRule.get(key);
    if (!entry) {
      entry = { rule_id: key, message: f.message, severity: f.severity, locations: [] };
      byRule.set(key, entry);
    }
    entry.locations.push({ kind: "file", path: f.path, line: f.line, occurrences: 1 });
  }

  const out = [];
  for (const { rule_id, message, severity, locations } of byRule.values()) {
    const override = getHeadlineOverride(test, `config:${rule_id}`);
    out.push({
      id: `lint:config:${rule_id}`,
      rule_id,
      severity: override.severity || severity || "minor",
      impact_category: override.impact_category || "code-quality",
      headline: override.headline || message || "Config hygiene issue",
      description: message || "",
      fix_hint: override.fix_hint || "Strip site-specific metadata (uuid / _core) from shipped config/install.",
      wcag_criteria: [],
      occurrences: locations.length,
      locations
    });
  }
  return out;
}

function groupPermissionFindings(test, permissionFindings) {
  const byRule = new Map();
  for (const f of permissionFindings) {
    const key = f.rule_id || "permissions";
    let entry = byRule.get(key);
    if (!entry) {
      entry = { rule_id: key, message: f.message, severity: f.severity, locations: [] };
      byRule.set(key, entry);
    }
    entry.locations.push({ kind: "file", path: f.path, line: f.line, occurrences: 1 });
  }

  const out = [];
  for (const { rule_id, severity, locations } of byRule.values()) {
    const override = getHeadlineOverride(test, `permissions:${rule_id}`);
    out.push({
      id: `lint:permissions:${rule_id}`,
      rule_id,
      severity: override.severity || severity || "minor",
      impact_category: override.impact_category || "code-quality",
      headline: override.headline || "Security-sensitive permission missing \"restrict access: true\"",
      description: "Permissions that grant administer/bypass-level access should set `restrict access: true` so Drupal warns admins before granting them.",
      fix_hint: override.fix_hint || "Add `restrict access: true` to the permission definition.",
      wcag_criteria: [],
      occurrences: locations.length,
      locations
    });
  }
  return out;
}

function groupEslintFindings(test, eslintFindings) {
  const byRule = new Map();
  for (const f of eslintFindings) {
    const ruleId = f.rule_id || "parsing-error";
    let entry = byRule.get(ruleId);
    if (!entry) {
      entry = {
        rule_id: ruleId,
        raw_severity: f.severity,
        message: f.message,
        fixable: f.fixable,
        locations: []
      };
      byRule.set(ruleId, entry);
    }
    entry.locations.push({
      kind: "file",
      path: scopedPath(f),
      line: f.line,
      column: f.column,
      occurrences: 1
    });
  }

  const out = [];
  for (const { rule_id, raw_severity, message, fixable, locations } of byRule.values()) {
    const override = getHeadlineOverride(test, `eslint:${rule_id}`);
    out.push({
      id: `lint:eslint:${rule_id}`,
      rule_id,
      severity: override.severity || mapEslintSeverity(raw_severity),
      impact_category: override.impact_category || "code-quality",
      headline: override.headline || message || rule_id,
      description: message || "",
      fix_hint: override.fix_hint
        || (fixable ? "Run `eslint --fix` to auto-fix this rule where possible." : ""),
      wcag_criteria: [],
      ...(override.tags?.length ? { tags: override.tags.slice() } : {}),
      occurrences: locations.length,
      locations
    });
  }
  return out;
}

function mapEslintSeverity(raw) {
  if (raw === "ERROR") return "moderate";
  return "minor";
}

function groupCspellFindings(test, cspellFindings) {
  // One finding per unique misspelled word; locations are each occurrence.
  // Word is kept verbatim (case-sensitive) so two casings of the same
  // misspelling don't collapse into one entry — typically different bugs.
  const byWord = new Map();
  for (const f of cspellFindings) {
    const word = f.word || "";
    let entry = byWord.get(word);
    if (!entry) {
      entry = {
        word,
        suggestions: f.suggestions || [],
        flagged: !!f.flagged,
        locations: []
      };
      byWord.set(word, entry);
    }
    entry.locations.push({
      kind: "file",
      path: f.path,
      line: f.line,
      column: f.column,
      occurrences: 1
    });
    // First-seen suggestions win; same word usually has the same fix.
    if (!entry.suggestions?.length && f.suggestions?.length) {
      entry.suggestions = f.suggestions;
    }
  }

  const out = [];
  for (const { word, suggestions, flagged, locations } of byWord.values()) {
    const override = getHeadlineOverride(test, `cspell:${word}`);
    const sevDefault = flagged ? "moderate" : "minor";
    const fixHint = override.fix_hint
      || (suggestions?.length
        ? `Did you mean ${suggestions.slice(0, 3).map(s => `\`${s}\``).join(", ")}? If the term is intentional, add it to \`tests/code-quality/spelling/.cspell/upstream-words.txt\`.`
        : `If the term is intentional, add it to \`tests/code-quality/spelling/.cspell/upstream-words.txt\`. Otherwise, fix the spelling.`);
    out.push({
      id: `lint:cspell:${word}`,
      rule_id: word,
      severity: override.severity || sevDefault,
      impact_category: override.impact_category || "code-quality",
      headline: override.headline || (flagged ? `Flagged term: ${word}` : `Possible misspelling: ${word}`),
      description: flagged
        ? `\`${word}\` is on the project's flagWords list (e.g., non-inclusive language).`
        : `\`${word}\` is not in the project dictionary.`,
      fix_hint: fixHint,
      wcag_criteria: [],
      ...(override.tags?.length ? { tags: override.tags.slice() } : {}),
      occurrences: locations.length,
      locations
    });
  }
  return out;
}

function groupComposerFindings(test, composerFindings) {
  // Group by rule_id; each occurrence is one location entry. composer
  // findings are project-scoped so locations only carry `path`
  // (composer.json or composer.lock) — no line numbers.
  const byRule = new Map();
  for (const f of composerFindings) {
    const ruleId = f.rule_id || "composer:unknown";
    let entry = byRule.get(ruleId);
    if (!entry) {
      entry = {
        rule_id: ruleId,
        raw_severity: f.severity,
        message: f.message,
        link: f.link || "",
        locations: []
      };
      byRule.set(ruleId, entry);
    }
    entry.locations.push({
      kind: "file",
      path: f.path || "composer.json",
      occurrences: 1
    });
  }

  const out = [];
  for (const { rule_id, raw_severity, message, link, locations } of byRule.values()) {
    const ruleSuffix = rule_id.replace(/^lint:/, "");
    const override = getHeadlineOverride(test, ruleSuffix);
    const severity = override.severity || mapComposerSeverity(raw_severity);
    // Audit findings (CVE advisories, abandoned packages) belong under
    // the security filter; validate findings stay code-quality.
    const defaultImpact = ruleSuffix.startsWith("composer:audit:") ? "security" : "code-quality";
    const finding = {
      id: `lint:${ruleSuffix}`,
      rule_id: ruleSuffix,
      severity,
      impact_category: override.impact_category || defaultImpact,
      headline: override.headline || message || ruleSuffix,
      description: message || "",
      fix_hint: override.fix_hint || "",
      wcag_criteria: [],
      ...(override.tags?.length ? { tags: override.tags.slice() } : {}),
      occurrences: locations.length,
      locations
    };
    if (link) finding.rule_url = link;
    out.push(finding);
  }
  return out;
}

function groupMarkdownlintFindings(test, mdFindings) {
  // Group by markdownlint rule_id (MD###). Each occurrence is one
  // location entry. Headlines fall back to the rule message when no
  // override is curated — markdownlint's native messages are clear
  // enough for most rules.
  const byRule = new Map();
  for (const f of mdFindings) {
    const ruleId = f.rule_id || "MD000";
    let entry = byRule.get(ruleId);
    if (!entry) {
      entry = {
        rule_id: ruleId,
        rule_name: f.rule_name || "",
        message: f.message,
        locations: []
      };
      byRule.set(ruleId, entry);
    }
    entry.locations.push({
      kind: "file",
      // markdownlint emits repo-relative paths directly; no scope
      // prefix needed (project-wide pass, not per-module).
      path: f.path || "",
      line: f.line,
      column: f.column,
      occurrences: 1
    });
  }

  const out = [];
  for (const { rule_id, rule_name, message, locations } of byRule.values()) {
    const override = getHeadlineOverride(test, `markdownlint:${rule_id}`);
    out.push({
      id: `lint:markdownlint:${rule_id}`,
      rule_id,
      severity: override.severity || "minor",
      impact_category: override.impact_category || "code-quality",
      headline: override.headline || (rule_name ? `${rule_id}: ${rule_name.replace(/-/g, " ")}` : message || rule_id),
      description: message || "",
      fix_hint: override.fix_hint || "",
      wcag_criteria: [],
      ...(override.tags?.length ? { tags: override.tags.slice() } : {}),
      occurrences: locations.length,
      locations
    });
  }
  return out;
}

function groupActionlintFindings(test, alFindings) {
  // Group by rule_id (kind, or kind+SC code for shellcheck). Each
  // occurrence is one location entry.
  const byRule = new Map();
  for (const f of alFindings) {
    const ruleId = f.rule_id || "actionlint:unknown";
    let entry = byRule.get(ruleId);
    if (!entry) {
      entry = {
        rule_id: ruleId,
        kind: f.kind || "unknown",
        message: f.message,
        locations: []
      };
      byRule.set(ruleId, entry);
    }
    entry.locations.push({
      kind: "file",
      path: f.path || "",
      line: f.line,
      column: f.column,
      occurrences: 1
    });
  }

  const out = [];
  for (const { rule_id, kind, message, locations } of byRule.values()) {
    const ruleSuffix = rule_id.replace(/^actionlint:/, "");
    const override = getHeadlineOverride(test, `actionlint:${ruleSuffix}`);
    const headline = override.headline
      || (kind === "shellcheck"
        ? `shellcheck: ${ruleSuffix.replace(/^shellcheck:/, "")} — ${message.replace(/^shellcheck reported issue in this script: /, "").slice(0, 80)}`
        : `actionlint: ${kind} — ${message.slice(0, 80)}`);
    out.push({
      id: `lint:actionlint:${ruleSuffix}`,
      rule_id: ruleSuffix,
      severity: override.severity || "minor",
      impact_category: override.impact_category || "code-quality",
      headline,
      description: message || "",
      fix_hint: override.fix_hint || "",
      wcag_criteria: [],
      ...(override.tags?.length ? { tags: override.tags.slice() } : {}),
      occurrences: locations.length,
      locations
    });
  }
  return out;
}

function mapComposerSeverity(raw) {
  // composer validate ERRORs are real schema/lock blockers; audit ERRORs
  // are CVEs. Both bubble up to "serious" in the unified report.
  if (raw === "ERROR") return "serious";
  return "minor";
}

function scopedPath(finding) {
  // The orchestrator pre-normalizes the location to a repo-relative `path`;
  // prefer it so every lane emits consistent paths.
  if (finding.path) return finding.path;
  if (finding.scope_name && finding.file) {
    return `${finding.scope_name}/${finding.file}`;
  }
  return finding.file || finding.scope_name || "";
}

function mapPhpcsSeverity(raw) {
  if (raw === "ERROR") return "moderate";
  return "minor";
}

/**
 * Convert reflow audit results (one entry per page tested at the WCAG
 * SC 1.4.10 threshold viewport) into a test-suite-findings.json payload.
 * Each page that overflows becomes one finding tagged WCAG 1.4.10.
 *
 * @param {Array}   pageResults  [{url, path, failed, overflow_px, ...}, ...]
 * @param {object}  viewport     { width, height } used for the audit.
 * @param {string}  outputDir    Where to write test-suite-findings.json.
 */
export function writeReflowFindings(pageResults, viewport, outputDir) {
  const findings = [];
  for (const r of pageResults) {
    if (!r.failed) continue;
    const elements = (r.overflowing_elements || []).map(e => e.selector).filter(Boolean);
    const elementsHint = elements.length
      ? ` Top offending: ${elements.slice(0, 3).join(", ")}.`
      : "";
    findings.push({
      id: `reflow:1.4.10:${r.path}`,
      rule_id: "wcag:1.4.10",
      severity: "serious",
      impact_category: "low-vision",
      headline: r.error
        ? `Reflow audit could not complete on ${r.path}`
        : `Page does not reflow at ${viewport.width}px viewport`,
      description: r.error
        ? `Audit failed: ${r.error}`
        : `Document is ${r.doc_width_px}px wide at a ${r.viewport_width_px}px viewport (${r.overflow_px}px overflow), forcing horizontal scrolling.${elementsHint}`,
      fix_hint: "Replace fixed pixel widths with relative units, allow tables to scroll inside containers, and verify long inline content (URLs, code) wraps.",
      wcag_criteria: ["1.4.10"],
      occurrences: 1,
      locations: [{ kind: "url", url: r.url, occurrences: 1 }],
      rule_url: "https://www.w3.org/WAI/WCAG21/Understanding/reflow.html",
      tags: ["wcag21aa"],
    });
  }

  const payload = {
    schema_version: "1.0",
    test: "reflow",
    tool: "playwright-reflow",
    surface: buildSurface(),
    profile: {
      key: "reflow",
      name: `WCAG 2.1 SC 1.4.10 — Reflow at ${viewport.width}px`,
      tags: ["wcag21aa"],
      severity_levels: ["serious"],
    },
    generated_at: new Date().toISOString(),
    duration_ms: 0,
    summary: computeSummary(findings, { pages_tested: pageResults.length }),
    findings,
  };

  const target = join(outputDir, "test-suite-findings.json");
  writeFileSync(target, JSON.stringify(payload, null, 2));
  return target;
}

/**
 * Convert meta-viewport audit results into a test-suite-findings.json payload.
 * Pages that ship `user-scalable=no` or `maximum-scale<2` block zoom and
 * violate WCAG 2.0 SC 1.4.4 (Resize Text). Findings are grouped by failure
 * reason (e.g. "user-scalable=no") so a site-wide misconfiguration produces
 * one finding with N locations rather than N separate findings.
 *
 * @param {Array}   pageResults  [{url, path, failed, failure_reason, ...}, ...]
 * @param {string}  outputDir    Where to write test-suite-findings.json.
 */
export function writeMetaViewportFindings(pageResults, outputDir) {
  const byReason = new Map();
  for (const r of pageResults) {
    if (!r.failed) continue;
    const reason = r.failure_reason || "audit-error";
    if (!byReason.has(reason)) byReason.set(reason, []);
    byReason.get(reason).push(r);
  }

  const findings = [];
  for (const [reason, rows] of byReason.entries()) {
    const isAuditError = reason === "audit-error";
    findings.push({
      id: `meta-viewport:1.4.4:${reason}`,
      rule_id: "wcag:1.4.4",
      severity: "serious",
      impact_category: "low-vision",
      headline: isAuditError
        ? "Meta-viewport audit could not complete on one or more pages"
        : `Meta-viewport blocks zoom (${reason})`,
      description: isAuditError
        ? "The audit failed to read the viewport meta tag on these pages."
        : `Pages ship a meta-viewport directive that prevents users from zooming text to 200%, in violation of WCAG 2.0 SC 1.4.4. Detected directive: ${reason}.`,
      fix_hint: isAuditError
        ? "Re-run the audit; if failures persist, check page response codes and JavaScript redirects."
        : "Remove `user-scalable=no` and any `maximum-scale<2` from the `<meta name=\"viewport\">` tag. The recommended baseline is `width=device-width, initial-scale=1`.",
      wcag_criteria: ["1.4.4"],
      occurrences: rows.length,
      locations: rows.map((r) => ({ kind: "url", url: r.url, occurrences: 1 })),
      rule_url: "https://www.w3.org/WAI/WCAG21/Understanding/resize-text.html",
      tags: ["wcag2aa"],
    });
  }

  const payload = {
    schema_version: "1.0",
    test: "meta-viewport",
    tool: "playwright-meta-viewport",
    surface: buildSurface(),
    profile: {
      key: "meta-viewport",
      name: "WCAG 2.0 SC 1.4.4 — Resize Text (meta-viewport)",
      tags: ["wcag2aa"],
      severity_levels: ["serious"],
    },
    generated_at: new Date().toISOString(),
    duration_ms: 0,
    summary: computeSummary(findings, { pages_tested: pageResults.length }),
    findings,
  };

  const target = join(outputDir, "test-suite-findings.json");
  writeFileSync(target, JSON.stringify(payload, null, 2));
  return target;
}

// ─── PHPUnit (Functional / Regression) ───────────────────────────────────────

function phpunitShortName(cls) {
  const parts = String(cls).split("\\");
  return parts[parts.length - 1] || cls;
}

// Parse a JUnit XML string into failing/erroring test cases plus a total count.
function parseJunit(xml) {
  const problems = [];
  const attr = (a, k) => {
    const m = a.match(new RegExp(k + '="([^"]*)"'));
    return m ? m[1] : "";
  };
  const decode = (s) =>
    s.replace(/<!\[CDATA\[([\s\S]*?)\]\]>/g, "$1")
      .replace(/&lt;/g, "<").replace(/&gt;/g, ">")
      .replace(/&quot;/g, '"').replace(/&amp;/g, "&").trim();

  // Match self-closing (<testcase .../>, passed) and full (<testcase ...>…
  // </testcase>) cases; only full cases carry a failure/error body.
  const re = /<testcase\b([^>]*?)(?:\/>|>([\s\S]*?)<\/testcase>)/g;
  let m;
  while ((m = re.exec(xml)) !== null) {
    const open = m[1];
    const body = m[2] || "";
    const fm = body.match(/<(failure|error)\b([^>]*?)(?:\/>|>([\s\S]*?)<\/\1>)/);
    if (!fm) continue;
    problems.push({
      name: attr(open, "name"),
      cls: attr(open, "class") || attr(open, "classname"),
      file: attr(open, "file"),
      line: parseInt(attr(open, "line"), 10) || undefined,
      kind: fm[1],
      message: decode(fm[3] || attr(fm[2], "message") || ""),
    });
  }
  const total = (xml.match(/<testcase\b/g) || []).length;
  return { problems, total };
}

// Map a JUnit report (from custom-module PHPUnit) into the unified schema. Each
// failing or erroring test becomes one finding; passing tests are summarized.
export function writePhpunitFindings(junitXml, meta, outputDir) {
  const parsed = parseJunit(junitXml || "");
  const cwd = process.cwd();
  const rel = (p) => (p && p.startsWith(cwd + "/") ? p.slice(cwd.length + 1) : p);

  const findings = parsed.problems.map((c) => ({
    id: `phpunit:${c.cls}::${c.name}`,
    rule_id: `${c.cls}::${c.name}`,
    severity: c.kind === "error" ? "serious" : "moderate",
    impact_category: "code-quality",
    headline: `${c.kind === "error" ? "Test error" : "Test failed"}: ${phpunitShortName(c.cls)}::${c.name}`,
    description: c.message || "",
    fix_hint: "",
    wcag_criteria: [],
    occurrences: 1,
    locations: c.file ? [{ kind: "file", path: rel(c.file), line: c.line, occurrences: 1 }] : [],
  }));
  findings.sort((a, b) => a.id.localeCompare(b.id));

  const payload = {
    schema_version: "1.0",
    test: "phpunit",
    tool: "phpunit",
    surface: buildSurface(),
    generated_at: new Date().toISOString(),
    duration_ms: typeof meta?.duration_ms === "number" ? meta.duration_ms : undefined,
    summary: computeSummary(findings, { files_scanned: parsed.total }),
    findings,
  };

  const target = join(outputDir, "test-suite-findings.json");
  writeFileSync(target, JSON.stringify(payload, null, 2));
  return target;
}

/**
 * Shared HTML report renderer for the test-suite-findings.json contract
 * (tests/reports/_shell/findings.schema.json).
 *
 * Lanes that don't have a bespoke HTML report (reflow, meta-viewport, axe-full)
 * call this with the payload they already build, so every lane gets a
 * self-contained, accessible report in the unified report family's styling.
 * alfa / pa11y / lint keep their own richer reports.
 *
 * @module utils/findings-report
 */

const SURFACE_LABELS = { local: "Local", ci: "CI", "ci-multidev": "CI · multidev" };
const SEV_ORDER = ["critical", "serious", "moderate", "minor"];

// Run-profile display names, mirroring a11y-profiles.js. Shown on every report
// so a utest:all run reads consistently regardless of which lane produced it.
const PROFILE_NAMES = {
  strict: "Strict Mode (WCAG Level A only)",
  standard: "Standard Mode (WCAG Level A + AA)",
  comprehensive: "Comprehensive Mode (All WCAG Levels + Best Practices)",
  custom: "Custom Mode (User-defined)",
};

// Report title per tool. Profile-driven lanes get the tool name (the profile
// shows on its own line); single-criterion lanes fall back to their SC name.
const TOOL_TITLES = {
  "siteimprove-alfa": "Siteimprove Alfa Accessibility Report",
  "axe-core": "axe-core Accessibility Report",
  "playwright-reflow": "Reflow (Level AA) Accessibility Report",
  "playwright-meta-viewport": "Meta-viewport Accessibility Report",
  "pa11y-ci": "pa11y Accessibility Report",
  "phpunit": "PHPUnit (Functional / Regression) Report",
};

// Non-page lanes label their scope stat differently (phpunit counts test
// cases, lint counts files); page-crawling lanes show Pages Tested.
const SCOPE_LABELS = { phpunit: "Tests Run" };

// Single-criterion lanes name the exact WCAG success criterion they verify,
// shown next to the rule tags in the header.
const SUCCESS_CRITERIA = {
  "playwright-reflow": "WCAG 2.1 SC 1.4.10: Reflow at 320px",
  "playwright-meta-viewport": "WCAG 2.0 SC 1.4.4: Resize Text",
};

// Tools that report the full severity scale (so the summary shows all four tiles
// even at 0); other lanes cover only their declared severity_levels.
const COMPREHENSIVE_TOOLS = new Set(["siteimprove-alfa", "axe-core"]);

function escapeHtml(s) {
  return String(s ?? "").replace(/[&<>"']/g, (c) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;",
  }[c]));
}

function severityCounts(findings) {
  const counts = { critical: 0, serious: 0, moderate: 0, minor: 0 };
  for (const f of findings) {
    if (counts[f.severity] !== undefined) counts[f.severity]++;
  }
  return counts;
}

function renderPageGroups(locations) {
  // Group locations per page, then make each page a collapsible group so a
  // finding with many pages/elements stays scannable (finding > page > element).
  const byPage = new Map();
  for (const l of locations || []) {
    const key = l.path || l.url || "(page)";
    if (!byPage.has(key)) byPage.set(key, { path: l.path || l.url || "(page)", url: l.url || "", items: [] });
    byPage.get(key).items.push(l);
  }
  return [...byPage.values()].map((g) => {
    const count = g.items.reduce((s, l) => s + (l.occurrences || 1), 0);
    const link = /^https?:/.test(g.url)
      ? `<a href="${escapeHtml(g.url)}" target="_blank" rel="noopener">${escapeHtml(g.path)}</a>`
      : escapeHtml(g.path);
    // Element selector + failing HTML markup, when the tool captured them, so a
    // developer or AI skill knows exactly where on the page and what to fix.
    const elements = g.items.map((l) => {
      const selector = l.selector ? `<div class="loc-selector"><code>${escapeHtml(l.selector)}</code></div>` : "";
      const html = l.html ? `<pre class="loc-html"><code>${escapeHtml(l.html)}</code></pre>` : "";
      return (selector || html) ? `<li>${selector}${html}</li>` : "";
    }).filter(Boolean).join("");
    // Pages with element detail collapse to reveal it; page-level-only findings
    // (no selector/markup) render as a plain row.
    return elements
      ? `<details class="page-group"><summary>${link} <span class="count">(${count})</span></summary><ul class="loc-list">${elements}</ul></details>`
      : `<div class="page-plain">${link} <span class="count">(${count})</span></div>`;
  }).join("");
}

function renderFinding(f, open) {
  const sev = SEV_ORDER.includes(f.severity) ? f.severity : "minor";
  const occ = f.occurrences || (f.locations || []).length || 1;
  const pageGroups = renderPageGroups(f.locations);
  const ruleCell = /^https?:/.test(f.rule_url || "")
    ? `<a href="${escapeHtml(f.rule_url)}" target="_blank" rel="noopener">${escapeHtml(f.rule_id)}</a>`
    : escapeHtml(f.rule_id || "");
  return `
  <details class="finding priority-${sev}"${open ? " open" : ""}>
    <summary>
      <h3 class="finding-head"><span class="sev-badge sev-${sev}">${sev}</span> <span class="finding-title">${escapeHtml(f.headline || f.rule_id || "Finding")}</span> <span class="finding-meta">${occ} occurrence${occ === 1 ? "" : "s"}</span></h3>
    </summary>
    <div class="finding-body">
      ${f.description ? `<p class="finding-desc">${escapeHtml(f.description)}</p>` : ""}
      ${f.fix_hint ? `<p class="finding-fix"><strong>Fix:</strong> ${escapeHtml(f.fix_hint)}</p>` : ""}
      ${f.rule_id ? `<p class="finding-rule">Rule: ${ruleCell}${(f.wcag_criteria && f.wcag_criteria.length) ? ` &middot; WCAG ${escapeHtml(f.wcag_criteria.join(", "))}` : ""}</p>` : ""}
      ${(f.locations && f.locations.length) ? `<h4>Affected pages</h4>${pageGroups}` : ""}
    </div>
  </details>`;
}

/**
 * Render a findings payload to a self-contained HTML report.
 *
 * @param {object} payload  A test-suite-findings.json object.
 * @returns {string} HTML document.
 */
export function renderFindingsReport(payload) {
  const {
    test = "report", tool = "", surface = {}, profile = {},
    summary = {}, findings = [], generated_at,
  } = payload || {};

  const surfaceText = SURFACE_LABELS[surface.context] || "Local";
  const surfaceUrl = surface.base_url || "";
  const surfaceDetail = surface.label ? `(${surface.label})` : "";
  const title = TOOL_TITLES[tool] || profile.name || tool || test;
  const runProfileKey = process.env.A11Y_PROFILE || "comprehensive";
  const runProfileName = PROFILE_NAMES[runProfileKey] || runProfileKey;
  const tags = (profile.tags || []).join(", ");
  const successCriterion = SUCCESS_CRITERIA[tool] || "";
  // At a glance: list the severities, then indicate which fail the run vs which
  // are advisory only. Report-only lanes fail on nothing.
  const gate = payload.gate || {};
  const capSev = (s) => String(s).charAt(0).toUpperCase() + String(s).slice(1);
  let gateLine;
  if (gate.fails_build && (gate.severities || []).length) {
    const failSet = new Set(gate.severities.map((s) => String(s).toLowerCase()));
    const fails = SEV_ORDER.filter((s) => failSet.has(s)).map(capSev);
    const advisory = SEV_ORDER.filter((s) => !failSet.has(s)).map(capSev);
    gateLine = `<p><strong>Fails the run on:</strong> ${fails.join(", ")}.${advisory.length ? ` ${advisory.join(", ")} issues are advisory only.` : ""}</p>`;
  } else {
    gateLine = `<p><strong>Report-only:</strong> all issues are advisory (does not fail the build).</p>`;
  }
  const sitemap = process.env.SITEMAP_URL || "";
  const generated = generated_at ? new Date(generated_at).toLocaleString() : "";
  const pages = summary.pages_tested;
  const total = findings.length;
  const sev = severityCounts(findings);

  // phpunit is the one non-a11y lane using this renderer: skip the a11y
  // header lines (profile, sitemap, base URL) and label its scope stat as
  // test cases rather than crawled pages.
  const isA11yLane = tool !== "phpunit";
  const isPageLane = typeof pages === "number";
  const scopeCount = isPageLane ? pages : summary.files_scanned;
  const scopeLabel = isPageLane ? "Pages Tested" : (SCOPE_LABELS[tool] || "Files Scanned");

  // The "Back to all reports" link points at the unified index.html, which only
  // exists after a full utest:all run. Individual lane runs have no index to
  // return to, so the link is shown only when utest:all set UTEST_ALL.
  const isAllRun = process.env.UTEST_ALL === "1";
  const backLink = isAllRun
    ? `<footer class="site-footer"><a href="../index.html">← Back to all reports</a></footer>`
    : "";

  // Show every severity the report covers, even at 0, plus any present. A
  // comprehensive engine (alfa/axe) reports all four; a single-criterion lane
  // (meta-viewport = serious) shows only its declared severity_levels.
  const applicable = COMPREHENSIVE_TOOLS.has(tool)
    ? new Set(SEV_ORDER)
    : new Set(profile.severity_levels || []);
  const sevCards = SEV_ORDER.filter((s) => applicable.has(s) || sev[s] > 0).map((s) =>
    `<div class="stat-card priority-${s}"><div class="stat-number">${sev[s]}</div><div class="stat-label">${s[0].toUpperCase() + s.slice(1)}</div></div>`
  ).join("\n    ");

  const ordered = findings.slice().sort(
    (a, b) => (SEV_ORDER.indexOf(a.severity) - SEV_ORDER.indexOf(b.severity))
      || String(a.rule_id).localeCompare(String(b.rule_id))
  );
  // An incomplete lane must never read as a clean pass. The notice sits above
  // the summary tiles; a 0-finding incomplete run also loses the green panel.
  const isIncomplete = summary.status === "incomplete";
  const incompleteDetail = summary.pages_errored
    ? `${summary.pages_errored} of ${typeof pages === "number" ? pages : "?"} page(s) errored and were not audited.`
    : "0 pages were tested.";
  const incompleteNotice = isIncomplete
    ? `<div class="incomplete-note" role="alert"><strong>Run incomplete:</strong> ${escapeHtml(incompleteDetail)} Results may understate the true findings; re-run this lane.</div>`
    : "";

  // Findings render collapsed; each summary shows severity + count, and the
  // reader expands the ones they want to read.
  const body = total === 0
    ? (isIncomplete
      ? `<div class="no-findings incomplete"><h2>No findings recorded</h2><p>This run was incomplete, so a clean result cannot be claimed.</p></div>`
      : `<div class="no-findings"><h2>No findings</h2><p>No issues were detected for this check.</p></div>`)
    : `<section aria-labelledby="findings-heading">
      <h2 id="findings-heading" class="visually-hidden">Findings</h2>
      <p class="findings-hint">${total} finding${total === 1 ? "" : "s"} — select a row to expand its details.</p>
      ${ordered.map((f) => renderFinding(f, false)).join("")}
    </section>`;

  return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>${escapeHtml(title)}</title>
<style>
* { box-sizing: border-box; }
:root {
  --brand-primary: #003660; --brand-primary-ink: #ffffff;
  --brand-accent: #febc11; --brand-accent-ink: #2a2100;
  --color-bg: #eef2f6; --color-surface: #ffffff; --color-surface-2: #f5f8fb;
  --color-text: #172431; --color-text-muted: #5a6b7b;
  --color-border: #d6dee7; --color-border-strong: #b4c1cd;
  --color-accent: var(--brand-primary); --color-accent-bg: #e6eef6;
  --severity-critical: #9b1c15; --severity-serious: #8b3409; --severity-moderate: #6a4900; --severity-minor: #1f5c78; --severity-pass: #1a6731;
  --severity-critical-bg: #fdecea; --severity-serious-bg: #fceee0; --severity-moderate-bg: #fdf3d4; --severity-minor-bg: #e3f0f6; --severity-pass-bg: #e3f3e7;
  --font-sans: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  --font-mono: ui-monospace, "SF Mono", "Cascadia Code", Menlo, Consolas, monospace;
  --radius: 5px; --radius-card: 4px; --radius-row: 3px;
  --shadow: 0 1px 2px rgba(16,33,51,0.05), 0 2px 6px rgba(16,33,51,0.05);
  --shadow-card: 0 1px 3px rgba(16,33,51,0.06), 0 8px 28px rgba(16,33,51,0.08);
  --max-width: 1120px;
}
@media (prefers-color-scheme: dark) {
  :root {
    --color-bg: #0e1922; --color-surface: #16222e; --color-surface-2: #1c2b38;
    --color-text: #e6edf3; --color-text-muted: #9db0c0;
    --color-border: #2a3a48; --color-border-strong: #3c4d5d;
    --color-accent: #7fb2e3; --color-accent-bg: #16303f;
    --severity-critical: #ffa79f; --severity-serious: #f2b183; --severity-moderate: #e4c352; --severity-minor: #8fc7e2; --severity-pass: #82d3a0;
    --severity-critical-bg: #38201d; --severity-serious-bg: #33271a; --severity-moderate-bg: #32301a; --severity-minor-bg: #13303c; --severity-pass-bg: #163127;
    --shadow: 0 1px 2px rgba(0,0,0,0.4), 0 2px 8px rgba(0,0,0,0.35);
    --shadow-card: 0 1px 3px rgba(0,0,0,0.5), 0 10px 30px rgba(0,0,0,0.45);
  }
}
body { font-family: var(--font-sans); margin: 0; padding: 0 0 8px; background: var(--color-bg); color: var(--color-text); line-height: 1.5; -webkit-font-smoothing: antialiased; }
a { color: var(--color-accent); }
code { overflow-wrap: anywhere; }
a:focus-visible, summary:focus-visible { outline: 3px solid var(--color-accent); outline-offset: 2px; }
.skip-link { position: absolute; left: -9999px; top: 0; background: var(--brand-primary); color: #fff; padding: 8px 12px; z-index: 1000; text-decoration: none; }
.skip-link:focus { left: 8px; top: 8px; }
.site-masthead { background: var(--brand-primary); color: var(--brand-primary-ink); padding: 30px 20px 56px; }
.masthead-inner { width: min(100% - 32px, var(--max-width)); margin-inline: auto; }
.site-masthead h1 { margin: 12px 0 6px; font-size: clamp(1.5rem, 1rem + 2.2vw, 2.1rem); line-height: 1.15; font-weight: 750; letter-spacing: -0.015em; color: #fff; }
.site-masthead .meta { margin: 0; color: rgba(255,255,255,0.72); font-size: 0.875rem; }
.site-masthead a { color: var(--brand-accent); }
.surface-banner { display: inline-flex; flex-wrap: wrap; gap: 8px; align-items: center; background: rgba(255,255,255,0.12); color: #fff; padding: 6px 14px; border-radius: 5px; font-size: 0.8125rem; border: 1px solid rgba(255,255,255,0.22); }
.surface-banner strong { font-weight: 700; color: var(--brand-accent); }
.surface-banner .surface-url { font-family: var(--font-mono); font-size: 0.78rem; color: #fff; opacity: 0.92; overflow-wrap: anywhere; }
.container { width: min(100% - 32px, var(--max-width)); margin: -32px auto 0; position: relative; z-index: 1; background: var(--color-surface); border-radius: var(--radius); box-shadow: var(--shadow-card); padding: 20px; }
@media (min-width: 768px) { .container { padding: 32px; } }
.header-info { color: var(--color-text-muted); font-size: 0.9375rem; }
.header-info p { margin: 4px 0; }
.header-info strong { color: var(--color-text); font-weight: 600; }
.summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin: 20px 0 8px; }
.stat-card { background: var(--color-surface); color: var(--color-text); padding: 16px; border: 1px solid var(--color-border); border-radius: var(--radius-card); text-align: center; }
.stat-number { font-size: 2.25rem; font-weight: 700; line-height: 1; }
.stat-label { color: var(--color-text-muted); font-size: 0.875rem; font-weight: 600; margin-top: 8px; }
.stat-card.priority-critical { background: var(--severity-critical-bg); border-color: var(--severity-critical); color: var(--severity-critical); }
.stat-card.priority-serious  { background: var(--severity-serious-bg);  border-color: var(--severity-serious);  color: var(--severity-serious); }
.stat-card.priority-moderate { background: var(--severity-moderate-bg); border-color: var(--severity-moderate); color: var(--severity-moderate); }
.stat-card.priority-minor    { background: var(--severity-minor-bg);    border-color: var(--severity-minor);    color: var(--severity-minor); }
.stat-card[class*="priority-"] .stat-label { color: inherit; }
.finding { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-row); margin-bottom: 12px; box-shadow: var(--shadow); overflow: hidden; }
.finding > summary { cursor: pointer; padding: 14px 18px; list-style: none; display: flex; align-items: baseline; gap: 8px; }
.finding > summary::-webkit-details-marker { display: none; }
.finding > summary:hover { background: var(--color-bg); }
.finding > summary:focus-visible { outline: 3px solid var(--color-accent); outline-offset: -2px; }
.finding > summary::before { content: "\\25B8"; color: var(--color-text-muted); display: inline-block; transition: transform 0.15s; }
.finding[open] > summary::before { transform: rotate(90deg); }
.finding-head { margin: 0; font-size: 1.02em; color: var(--color-text); font-weight: 600; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; flex: 1; }
.finding-title { flex: 1; }
.finding-meta { color: var(--color-text-muted); font-size: 0.8em; font-weight: 400; }
.finding-body { padding: 0 18px 18px; }
.findings-hint { color: var(--color-text-muted); font-size: 0.9em; margin: 0 0 12px; }
.sev-badge { padding: 3px 10px; border-radius: 3px; font-size: 0.75em; font-weight: 700; text-transform: uppercase; }
.sev-critical { background: var(--severity-critical-bg); color: var(--severity-critical); }
.sev-serious  { background: var(--severity-serious-bg);  color: var(--severity-serious); }
.sev-moderate { background: var(--severity-moderate-bg); color: var(--severity-moderate); }
.sev-minor    { background: var(--severity-minor-bg);    color: var(--severity-minor); }
.finding-desc { color: var(--color-text); margin: 10px 0 6px; }
.finding-fix { background: var(--severity-pass-bg); border: 1px solid var(--color-border); padding: 8px 12px; border-radius: var(--radius-card); color: var(--color-text); margin: 8px 0; }
.finding-fix strong { color: var(--severity-pass); }
.finding-rule { color: var(--color-text-muted); font-size: 0.85em; margin: 6px 0; }
.finding h4 { margin: 12px 0 6px; font-size: 0.9em; color: var(--color-text-muted); }
.loc-list { list-style: none; padding: 0; margin: 0; }
.loc-list li { padding: 6px 10px; border-bottom: 1px solid var(--color-border); font-size: 0.875em; }
.loc-list li:last-child { border-bottom: none; }
.loc-list .count { color: var(--color-text-muted); }
.loc-selector { margin-top: 4px; }
.loc-selector code { background: var(--color-surface-2); border: 1px solid var(--color-border); border-radius: 3px; padding: 1px 6px; font-size: 0.85em; color: var(--color-text); overflow-wrap: anywhere; font-family: var(--font-mono); }
.loc-html { margin: 4px 0 0; background: #10202c; border-radius: var(--radius-card); padding: 8px 10px; overflow-x: auto; }
.loc-html code { color: #e6e6e6; font-size: 0.82em; white-space: pre-wrap; word-break: break-word; font-family: var(--font-mono); }
.page-group { margin: 6px 0; border: 1px solid var(--color-border); border-radius: var(--radius-card); }
.page-group > summary { cursor: pointer; padding: 8px 12px; list-style: none; font-size: 0.9em; }
.page-group > summary::-webkit-details-marker { display: none; }
.page-group > summary::before { content: "\\25B8"; color: var(--color-text-muted); margin-right: 6px; display: inline-block; transition: transform 0.15s; }
.page-group[open] > summary::before { transform: rotate(90deg); }
.page-group > summary:hover { background: var(--color-bg); }
.page-group > summary:focus-visible { outline: 3px solid var(--color-accent); outline-offset: -2px; }
.page-group .loc-list { padding: 2px 12px 10px; }
.page-plain { padding: 8px 12px; font-size: 0.9em; }
.no-findings { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-card); padding: 40px; text-align: center; box-shadow: var(--shadow); color: var(--severity-pass); }
.no-findings h2 { color: var(--severity-pass); margin-top: 0; }
.no-findings.incomplete { color: var(--severity-moderate); border-color: var(--severity-moderate); background: var(--severity-moderate-bg); }
.no-findings.incomplete h2 { color: var(--severity-moderate); }
.incomplete-note { background: var(--severity-moderate-bg); border: 1px solid var(--severity-moderate); border-radius: var(--radius-card); padding: 12px 16px; margin: 16px 0 0; color: var(--color-text); }
.incomplete-note strong { color: var(--severity-moderate); }
.site-footer { width: min(100% - 32px, var(--max-width)); margin: 32px auto 0; padding-top: 18px; border-top: 1px solid var(--color-border); color: var(--color-text-muted); font-size: 0.8125rem; text-align: center; }
.site-footer a { color: var(--color-accent); text-decoration: none; }
.site-footer a:hover { text-decoration: underline; }
.visually-hidden { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
</style>
</head>
<body>
<a href="#main" class="skip-link">Skip to results</a>
<header class="site-masthead">
  <div class="masthead-inner">
    <div class="surface-banner" role="status"><strong>${escapeHtml(surfaceText)}</strong>${surfaceUrl ? `<span class="surface-url">${escapeHtml(surfaceUrl)}</span>` : ""}${surfaceDetail ? `<span>${escapeHtml(surfaceDetail)}</span>` : ""}</div>
    <h1>${escapeHtml(title)}</h1>
    ${generated ? `<div class="meta">Generated ${escapeHtml(generated)}</div>` : ""}
  </div>
</header>
<main id="main" class="container">
  <div class="header-info">
    ${isA11yLane ? `<p><strong>Run profile:</strong> ${escapeHtml(runProfileName)}</p>` : ""}
    ${tags ? `<p><strong>Rule Tags:</strong> ${escapeHtml(tags)}</p>` : ""}
    ${successCriterion ? `<p><strong>Success Criterion:</strong> ${escapeHtml(successCriterion)}</p>` : ""}
    ${gateLine}
    ${isA11yLane && surfaceUrl ? `<p><strong>Base URL:</strong> ${escapeHtml(surfaceUrl)}</p>` : ""}
    ${isA11yLane && sitemap ? `<p><strong>Sitemap:</strong> ${escapeHtml(sitemap)}</p>` : ""}
  </div>
  ${incompleteNotice}
  <h2 class="visually-hidden">Summary</h2>
  <div class="summary" role="group" aria-label="Results summary">
    ${sevCards}
    <div class="stat-card"><div class="stat-number">${total}</div><div class="stat-label">Findings</div></div>
    <div class="stat-card"><div class="stat-number">${typeof scopeCount === "number" ? scopeCount : "—"}</div><div class="stat-label">${escapeHtml(scopeLabel)}</div></div>
  </div>
${body}
</main>
${backLink}
</body>
</html>`;
}

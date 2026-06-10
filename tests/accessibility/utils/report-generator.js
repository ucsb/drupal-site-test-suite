/**
 * HTML report generator for Alfa full-site accessibility audits.
 * Generates an interactive visual report with severity filtering,
 * WCAG criteria grouping, and detailed fix recommendations.
 */

import { getRuleInfo, getSeverityColor } from "../alfa/dynamic-rule-extractor.js";
import { getAccessibilityConfig } from "../config/a11y-profiles.js";
import { buildSurface } from "./findings-emitter.js";

const alfaConfig = getAccessibilityConfig('alfa');

// ─── Utilities ───────────────────────────────────────────────────────────────

/** HTML entity escaping to prevent XSS in generated reports */
export function escapeHtml(str) {
  if (typeof str !== 'string') return str;
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

/** Determine WCAG level from criteria string */
export function getWcagLevel(criteria) {
  const match = criteria.match(/(\d+\.\d+\.\d+)/);
  if (!match) return 'Unknown';

  const levelAACriteria = [
    '1.4.3', '1.4.4', '1.4.5', '1.4.10', '1.4.11', '1.4.12', '1.4.13',
    '2.4.5', '2.4.6', '2.4.7', '3.1.2', '3.2.3', '3.2.4', '3.3.3', '3.3.4'
  ];
  const levelAAACriteria = [
    '1.2.6', '1.2.7', '1.2.8', '1.2.9', '1.4.6', '1.4.7', '1.4.8', '1.4.9',
    '2.1.3', '2.2.3', '2.2.4', '2.2.5', '2.2.6', '2.3.2', '2.3.3',
    '2.4.8', '2.4.9', '2.4.10', '3.1.3', '3.1.4', '3.1.5', '3.1.6',
    '3.2.5', '3.3.5', '3.3.6'
  ];

  const criteriaNumber = match[1];
  if (levelAAACriteria.includes(criteriaNumber)) return 'AAA';
  if (levelAACriteria.includes(criteriaNumber)) return 'AA';
  return 'A';
}

/** Get WCAG level color */
export function getWcagLevelColor(level) {
  // White-on-color badges need ≥4.5:1 contrast (WCAG 1.4.3); each value
  // here was darkened from the previous brand palette so the badge text
  // passes against white.
  const colors = { 'A': '#1e7e34', 'AA': '#00538a', 'AAA': '#5a30a0', 'Unknown': '#495057' };
  return colors[level] || colors['Unknown'];
}

// ─── Report Generator ────────────────────────────────────────────────────────

/** Generate the full interactive HTML report */
export async function generateHtmlReport(report, profileInfo) {
  const { summary, results } = report;

  // Collect all unique violations across all pages
  const allViolations = new Map();
  results.forEach(result => {
    if (result.issues && result.issues.length > 0) {
      result.issues.forEach(issue => {
        const ruleId = issue.rule;
        if (!allViolations.has(ruleId)) {
          allViolations.set(ruleId, { ruleId, ruleInfo: null, totalFailures: 0, affectedPages: [] });
        }
        const violation = allViolations.get(ruleId);
        violation.totalFailures += issue.failed;
        violation.affectedPages.push({ url: result.url, path: result.path, failures: issue.failed });
      });
    }
  });

  // Fetch rule info for all violations
  const violationsWithRuleInfo = await Promise.all(
    Array.from(allViolations.values()).map(async v => { v.ruleInfo = await getRuleInfo(v.ruleId); return v; })
  );

  // Sort by severity then total failures
  const sortedViolations = violationsWithRuleInfo.sort((a, b) => {
    const severityOrder = { critical: 4, serious: 3, moderate: 2, minor: 1 };
    const diff = (severityOrder[b.ruleInfo.severity] || 2) - (severityOrder[a.ruleInfo.severity] || 2);
    return diff !== 0 ? diff : b.totalFailures - a.totalFailures;
  });

  // Surface banner context — matches the lint + unified report banners.
  const surface = buildSurface();
  const surfaceLabels = { local: 'Local', ci: 'CI', 'ci-multidev': 'CI · multidev' };
  const surfaceText = surfaceLabels[surface.context] || 'Local';
  const surfaceUrl = surface.base_url || '';
  const surfaceDetail = surface.label ? `(${surface.label})` : '';

  return `
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Siteimprove Alfa - Full Site Accessibility Report</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 20px; background: #f8f9fa; line-height: 1.6; }
        .container { max-width: 1200px; margin: 0 auto; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: white; padding: 30px; border-bottom: 3px solid #007cba; border-radius: 8px 8px 0 0; }
        /* Surface banner — same shape lint + unified reports use, so reviewers
           see the same context (Local / CI / CI · multidev) across all reports. */
        .surface-banner { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; background: #e8f0fb; color: #0b5cab; padding: 8px 12px; border-radius: 6px; font-size: 0.875em; margin-bottom: 16px; border: 1px solid #c9dcf2; }
        .surface-banner strong { font-weight: 600; }
        .surface-banner .surface-url { font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size: 0.8125em; overflow-wrap: anywhere; }
        .header h1 { margin: 0 0 10px 0; font-size: 1.8em; color: #333; }
        .header-info { color: #666; font-size: 0.9em; }
        .summary { display: grid; grid-template-columns: repeat(4, 1fr); grid-template-rows: repeat(2, 1fr); gap: 20px; padding: 30px; background: #ffffff; }
        @media (max-width: 1200px) { .summary { grid-template-columns: repeat(2, 1fr); grid-template-rows: repeat(4, 1fr); } }
        @media (max-width: 768px) { .summary { grid-template-columns: 1fr; grid-template-rows: repeat(8, 1fr); } }
        .stat-card { background: #f8f9fa; color: #495057; padding: 16px; border: 1px solid #dee2e6; border-radius: 8px; text-align: center; }
        .stat-number { font-size: 2.25rem; font-weight: 700; line-height: 1; margin-bottom: 0; }
        .stat-label { color: #5f6b7a; font-size: 0.875rem; font-weight: 600; margin-top: 8px; }
        /* Severity tiles mirror the unified report's --severity-* tiles: a solid
           colored box (background + matching border), with the number and label
           in the tile's own text color — no left-accent border. */
        .stat-card.priority-critical { background: #fdecea; border-color: #9b1c15; color: #9b1c15; }
        .stat-card.priority-serious  { background: #fceee0; border-color: #8b3409; color: #8b3409; }
        .stat-card.priority-moderate { background: #fdf3d4; border-color: #6a4900; color: #6a4900; }
        .stat-card.priority-minor    { background: #e3f0f6; border-color: #1f5c78; color: #1f5c78; }
        .stat-card[class*="priority-"] .stat-number,
        .stat-card[class*="priority-"] .stat-label { color: inherit; }
        .content { padding: 30px; }
        .section { margin-bottom: 40px; }
        .section h2 { color: #333; border-bottom: 2px solid #dee2e6; padding-bottom: 10px; margin-bottom: 25px; }
        .violation-card { border: 1px solid #ddd; border-radius: 8px; margin-bottom: 20px; overflow: hidden; }
        .violation-header { padding: 20px; cursor: pointer; transition: background-color 0.2s; }
        .violation-header:hover { background-color: #f8f9fa; }
        .violation-title { display: flex; align-items: center; gap: 10px; margin: 0; }
        .violation-icon { font-size: 1.5em; }
        .violation-meta { display: flex; gap: 20px; margin-top: 10px; font-size: 0.9em; color: #666; }
        .severity-badge { padding: 4px 8px; border-radius: 4px; color: white; font-weight: bold; text-transform: uppercase; font-size: 0.8em; }
        .violation-details { padding: 0 20px 20px 20px; display: none; }
        .violation-details.expanded { display: block; }
        .description { margin-bottom: 20px; color: #555; }
        .wcag-criteria { margin-bottom: 20px; }
        .wcag-criteria h4 { margin: 0 0 10px 0; color: #333; }
        .criteria-list { list-style: none; padding: 0; }
        .criteria-list li { background: #e3f2fd; padding: 8px 12px; margin: 5px 0; border-radius: 4px; }
        .fix-recommendations { margin-bottom: 20px; }
        .fix-recommendations h4 { margin: 0 0 15px 0; color: #333; }
        .recommendations-list { list-style: none; padding: 0; }
        .recommendations-list li { background: #f1f8e9; padding: 12px; margin: 8px 0; border-radius: 4px; border-left: 4px solid #4caf50; }
        .code-examples { margin-bottom: 20px; }
        .code-examples h4 { margin: 0 0 15px 0; color: #333; }
        .code-block { background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; margin: 10px 0; }
        .code-header { background: #e9ecef; padding: 8px 12px; font-weight: bold; border-bottom: 1px solid #ddd; }
        .code-header.bad { background: #f8d7da; color: #721c24; }
        .code-header.good { background: #d4edda; color: #155724; }
        .code-content { padding: 15px; font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace; font-size: 0.9em; white-space: pre-wrap; overflow-x: auto; }
        .affected-pages { margin-bottom: 20px; }
        .affected-pages h4 { margin: 0 0 15px 0; color: #333; }
        .page-list { list-style: none; padding: 0; }
        .page-item { background: #fff3cd; padding: 10px; margin: 5px 0; border-radius: 4px; border-left: 4px solid #ffc107; }
        .page-url { font-weight: bold; color: #856404; }
        .page-failures { color: #721c24; font-size: 0.9em; }
        .resources { margin-bottom: 20px; }
        .resources h4 { margin: 0 0 15px 0; color: #333; }
        .resource-link { display: block; color: #007cba; text-decoration: none; padding: 5px 0; }
        .resource-link:hover { text-decoration: underline; }
        .results-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .results-table th, .results-table td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        .results-table th { background-color: #f8f9fa; font-weight: bold; }
        .status-pass { color: #1e7e34; font-weight: bold; }
        .status-fail { color: #b3001a; font-weight: bold; }
        .visually-hidden { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); border: 0; }
        .url-cell { max-width: 300px; word-break: break-all; }
        .issues-cell { text-align: center; }
        .error-cell { color: #dc3545; font-style: italic; }
        .toggle-btn { background: none; border: none; color: #007cba; cursor: pointer; font-size: 0.9em; margin-left: 10px; }
        .toggle-btn:hover { text-decoration: underline; }
        .page-row.clickable { cursor: pointer; }
        .page-row.clickable:hover { background-color: #f8f9fa; }
        .click-hint { font-size: 0.8em; color: #007cba; font-style: italic; }
        .page-details-content { padding: 20px; background: #f8f9fa; border-radius: 6px; margin: 10px 0; }
        .page-details-content h4 { margin: 0 0 15px 0; color: #333; }
        .page-rules-list { display: flex; flex-direction: column; gap: 15px; }
        .page-rule-item { background: white; padding: 15px; border-radius: 6px; border-left: 4px solid #dc3545; }
        .page-rule-header { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
        .page-rule-title { font-weight: bold; color: #333; }
        .failure-count { background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 0.8em; }
        .page-rule-description { color: #666; margin-bottom: 8px; font-size: 0.9em; }
        .page-rule-fix { color: #155724; background: #d4edda; padding: 8px; border-radius: 4px; font-size: 0.9em; }
        .violation-card.priority-critical { border-left: 4px solid #dc3545 !important; background: #fff5f5; }
        .violation-card.priority-serious { border-left: 4px solid #fd7e14 !important; background: #fff8f0; }
        .violation-card.priority-moderate { border-left: 4px solid #ffc107 !important; }
        .violation-card.priority-minor { border-left: 4px solid #17a2b8 !important; }
        .priority-section { background: #fff5f5; color: #212529; margin: 0 0 30px 0; padding: 30px; border: 1px solid #f5c6cb; border-radius: 8px; }
        .priority-section h2 { color: #dc3545; border-bottom: 2px solid #f5c6cb; margin-bottom: 20px; }
        .priority-alert { background: #fff; border: 1px solid #f5c6cb; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
        .priority-stats { display: flex; gap: 30px; margin-bottom: 20px; }
        .priority-stat { text-align: center; }
        .priority-stat-number { font-size: 2em; font-weight: bold; margin-bottom: 5px; color: #dc3545; }
        .priority-stat-label { color: #495057; font-weight: 600; }
        .priority-actions { display: flex; gap: 15px; flex-wrap: wrap; }
        .priority-btn { background: #b3001a; color: white; border: 1px solid #b3001a; padding: 10px 20px; border-radius: 6px; cursor: pointer; transition: all 0.2s; font-weight: 600; }
        .priority-btn:hover { background: #8a0014; border-color: #8a0014; }
        .high-priority-indicator { display: inline-flex; align-items: center; gap: 5px; background: #dc3545; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.8em; font-weight: bold; }
        .serious-priority-indicator { display: inline-flex; align-items: center; gap: 5px; background: #fd7e14; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.8em; font-weight: bold; }
        /* No animations — flashing effects can cause issues for users with vestibular or seizure disorders */
    </style>
    <script>
        function toggleViolation(ruleId) {
            const details = document.getElementById('details-' + ruleId);
            const btn = document.getElementById('btn-' + ruleId);
            const header = btn ? btn.closest('.violation-header') : null;
            if (details.classList.contains('expanded')) { details.classList.remove('expanded'); btn.textContent = 'Show Details'; if (header) header.setAttribute('aria-expanded', 'false'); }
            else { details.classList.add('expanded'); btn.textContent = 'Hide Details'; if (header) header.setAttribute('aria-expanded', 'true'); }
        }
        function expandAll() { document.querySelectorAll('.violation-details').forEach(el => el.classList.add('expanded')); document.querySelectorAll('.toggle-btn').forEach(btn => btn.textContent = 'Hide Details'); document.querySelectorAll('.violation-header[aria-expanded]').forEach(h => h.setAttribute('aria-expanded', 'true')); }
        function collapseAll() { document.querySelectorAll('.violation-details').forEach(el => el.classList.remove('expanded')); document.querySelectorAll('.toggle-btn').forEach(btn => btn.textContent = 'Show Details'); document.querySelectorAll('.violation-header[aria-expanded]').forEach(h => h.setAttribute('aria-expanded', 'false')); }
        function togglePageDetails(pageIndex) { const r = document.getElementById('details-page-' + pageIndex); const isHidden = r.style.display === 'none'; r.style.display = isHidden ? 'table-row' : 'none'; const row = document.querySelector('[aria-controls="details-page-' + pageIndex + '"]'); if (row) row.setAttribute('aria-expanded', isHidden ? 'true' : 'false'); }
        function showOnlyHighPriority() { document.querySelectorAll('.violation-card').forEach(c => { c.style.display = 'none'; }); document.querySelectorAll('.violation-card[data-severity="critical"], .violation-card[data-severity="serious"]').forEach(c => { c.style.display = 'block'; }); updateFilterButtons('high-priority'); }
        function showAllViolations() { document.querySelectorAll('.violation-card').forEach(c => { c.style.display = 'block'; }); updateFilterButtons('all'); }
        function expandHighPriority() { document.querySelectorAll('.violation-card[data-severity="critical"] .violation-details, .violation-card[data-severity="serious"] .violation-details').forEach(d => { d.classList.add('expanded'); }); document.querySelectorAll('.violation-card[data-severity="critical"] .toggle-btn, .violation-card[data-severity="serious"] .toggle-btn').forEach(b => { b.textContent = 'Hide Details'; }); document.querySelectorAll('.violation-card[data-severity="critical"] .violation-header, .violation-card[data-severity="serious"] .violation-header').forEach(h => { if (h.hasAttribute('aria-expanded')) h.setAttribute('aria-expanded', 'true'); }); }
        function updateFilterButtons(activeFilter) { const btns = document.querySelectorAll('button[onclick*="show"]'); btns.forEach(b => { b.style.fontWeight = 'normal'; b.removeAttribute('aria-pressed'); }); const sel = activeFilter === 'high-priority' ? 'button[onclick="showOnlyHighPriority()"]' : 'button[onclick="showAllViolations()"]'; const el = document.querySelector(sel); if (el) { el.style.fontWeight = 'bold'; el.setAttribute('aria-pressed', 'true'); } }
        document.addEventListener('DOMContentLoaded', function() { updateFilterButtons('all'); });
    </script>
</head>
<body>
    <main class="container">
        <header class="header">
            <div class="surface-banner" role="status">
                <strong>${surfaceText}</strong>
                ${surfaceUrl ? `<span class="surface-url">${escapeHtml(surfaceUrl)}</span>` : ''}
                ${surfaceDetail ? `<span>${escapeHtml(surfaceDetail)}</span>` : ''}
            </div>
            <h1>Siteimprove Alfa — Full Site Accessibility Report</h1>
            <div class="header-info">
                <p><strong>Profile:</strong> ${profileInfo.name}</p>
                <p><strong>Rule Tags:</strong> ${alfaConfig.tags.join(', ')}</p>
                <p><strong>Severity Levels:</strong> ${(alfaConfig.severity || ['critical', 'serious']).join(', ')} (test fails only on these levels)</p>
                <p><strong>Base URL:</strong> ${report.baseUrl}</p>
                <p><strong>Sitemap:</strong> ${report.sitemapUrl}</p>
                <p><strong>Generated:</strong> ${new Date(report.generatedAt).toLocaleString()}</p>
            </div>
        </header>

        <section aria-labelledby="alfa-summary-heading" class="summary">
            <h2 id="alfa-summary-heading" class="visually-hidden">Summary</h2>
            <div class="stat-card"><div class="stat-number">${summary.totalPages}</div><div class="stat-label">Total Pages Tested</div></div>
            <div class="stat-card"><div class="stat-number">${summary.passedPages}</div><div class="stat-label">Pages Passed</div></div>
            <div class="stat-card"><div class="stat-number">${summary.failedPages}</div><div class="stat-label">Pages Failed</div></div>
            <div class="stat-card"><div class="stat-number">${sortedViolations.length}</div><div class="stat-label">Total Violations</div></div>
            <div class="stat-card priority-critical"><div class="stat-number">${sortedViolations.filter(v => v.ruleInfo.severity === 'critical').length}</div><div class="stat-label">Critical Issues</div></div>
            <div class="stat-card priority-serious"><div class="stat-number">${sortedViolations.filter(v => v.ruleInfo.severity === 'serious').length}</div><div class="stat-label">Serious Issues</div></div>
            <div class="stat-card priority-moderate"><div class="stat-number">${sortedViolations.filter(v => v.ruleInfo.severity === 'moderate').length}</div><div class="stat-label">Moderate Issues</div></div>
            <div class="stat-card priority-minor"><div class="stat-number">${sortedViolations.filter(v => v.ruleInfo.severity === 'minor').length}</div><div class="stat-label">Minor Issues</div></div>
        </section>

        <div class="content">
            ${renderHighPrioritySection(sortedViolations)}
            ${sortedViolations.length > 0 ? renderAllViolationsSection(sortedViolations) : renderNoViolationsSection()}
            ${await renderPageByPageSection(results)}
        </div>
    </main>
</body>
</html>`;
}

// ─── Section Renderers ───────────────────────────────────────────────────────

function renderHighPrioritySection(sortedViolations) {
  const criticalViolations = sortedViolations.filter(v => v.ruleInfo.severity === 'critical');
  const seriousViolations = sortedViolations.filter(v => v.ruleInfo.severity === 'serious');
  const highPriorityViolations = [...criticalViolations, ...seriousViolations];

  if (highPriorityViolations.length === 0) return '';

  return `
  <div class="priority-section">
      <h2>HIGH PRIORITY ISSUES - FIX THESE FIRST!</h2>
      <div class="priority-alert">
          <p><strong>IMMEDIATE ACTION REQUIRED</strong></p>
          <p>The following critical and serious accessibility issues require immediate attention.</p>
          <div class="priority-stats">
              <div class="priority-stat"><div class="priority-stat-number">${criticalViolations.length}</div><div class="priority-stat-label">Critical Issues</div></div>
              <div class="priority-stat"><div class="priority-stat-number">${seriousViolations.length}</div><div class="priority-stat-label">Serious Issues</div></div>
              <div class="priority-stat"><div class="priority-stat-number">${highPriorityViolations.reduce((sum, v) => sum + v.totalFailures, 0)}</div><div class="priority-stat-label">Total High-Priority Failures</div></div>
          </div>
          <div class="priority-actions">
              <button class="priority-btn" onclick="document.getElementById('high-priority-list').scrollIntoView({behavior: 'smooth'})">View Priority List</button>
              <button class="priority-btn" onclick="showOnlyHighPriority()">Filter High Priority Only</button>
              <button class="priority-btn" onclick="expandHighPriority()">Expand All Priority Issues</button>
          </div>
      </div>
  </div>
  <div class="section" id="high-priority-list">
      <h2>High Priority Issues (Critical &amp; Serious)</h2>
      <p>These issues must be addressed immediately.</p>
      ${highPriorityViolations.map(violation => renderPriorityViolationCard(violation)).join('')}
  </div>`;
}

function renderPriorityViolationCard(violation) {
  const ruleInfo = violation.ruleInfo;
  const severityColor = getSeverityColor(ruleInfo.severity);
  const priorityClass = ruleInfo.severity === 'critical' ? 'high-priority-indicator' : 'serious-priority-indicator';
  const ruleKey = `priority-${violation.ruleId.replace(/[^a-zA-Z0-9]/g, '-')}`;

  return `
  <div class="violation-card priority-${ruleInfo.severity}" data-severity="${ruleInfo.severity}">
      <div class="violation-header" role="button" tabindex="0" aria-expanded="false" aria-controls="details-${ruleKey}" onclick="toggleViolation('${ruleKey}')" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();toggleViolation('${ruleKey}');}">
          <h3 class="violation-title">
              <span>${ruleInfo.title}</span>
              <span class="${priorityClass}">${ruleInfo.severity.toUpperCase()}</span>
              <span class="severity-badge" style="background-color: ${severityColor};">${violation.totalFailures} failures</span>
          </h3>
          <div class="violation-meta">
              <span><strong>Rule:</strong> ${violation.ruleId}</span>
              <span><strong>Affected Pages:</strong> ${violation.affectedPages.length}</span>
              <span><strong>WCAG:</strong> ${ruleInfo.wcagCriteria.slice(0, 2).join(', ')}${ruleInfo.wcagCriteria.length > 2 ? '...' : ''}</span>
              <span id="btn-${ruleKey}" class="toggle-btn" aria-hidden="true">Show Details</span>
          </div>
      </div>
      <div id="details-${ruleKey}" class="violation-details">
          <div class="description"><strong>Why This Is ${ruleInfo.severity === 'critical' ? 'Critical' : 'Serious'}:</strong> ${ruleInfo.description}</div>
          <div class="fix-recommendations"><h4>Immediate Action Required</h4><ul class="recommendations-list">${ruleInfo.fixRecommendations.slice(0, 3).map(rec => `<li>${escapeHtml(rec)}</li>`).join('')}</ul></div>
          <div class="affected-pages"><h4>Pages Requiring Immediate Attention</h4><ul class="page-list">
              ${violation.affectedPages.slice(0, 5).map(page => `<li class="page-item"><div class="page-url">${escapeHtml(page.path)}</div><div class="page-failures">${page.failures} failure(s) — Fix immediately</div></li>`).join('')}
              ${violation.affectedPages.length > 5 ? `<li class="page-item"><em>...and ${violation.affectedPages.length - 5} more pages</em></li>` : ''}
          </ul></div>
          <div class="wcag-criteria"><h4>WCAG Success Criteria Violated</h4><ul class="criteria-list">${ruleInfo.wcagCriteria.map(c => `<li>${c} (Level ${getWcagLevel(c)})</li>`).join('')}</ul></div>
      </div>
  </div>`;
}

function renderAllViolationsSection(sortedViolations) {
  // Group violations by WCAG criteria
  const wcagGroups = new Map();
  sortedViolations.forEach(violation => {
    violation.ruleInfo.wcagCriteria.forEach(criteria => {
      if (!wcagGroups.has(criteria)) wcagGroups.set(criteria, { criteria, level: getWcagLevel(criteria), rules: [] });
      wcagGroups.get(criteria).rules.push(violation);
    });
  });

  const sortedWcagGroups = Array.from(wcagGroups.values()).sort((a, b) => {
    const levelOrder = { 'A': 1, 'AA': 2, 'AAA': 3, 'Unknown': 4 };
    const diff = (levelOrder[a.level] || 4) - (levelOrder[b.level] || 4);
    if (diff !== 0) return diff;
    const aMatch = a.criteria.match(/(\d+\.\d+\.\d+)/);
    const bMatch = b.criteria.match(/(\d+\.\d+\.\d+)/);
    if (aMatch && bMatch) {
      const aParts = aMatch[1].split('.').map(Number);
      const bParts = bMatch[1].split('.').map(Number);
      for (let i = 0; i < Math.max(aParts.length, bParts.length); i++) {
        if ((aParts[i] || 0) !== (bParts[i] || 0)) return (aParts[i] || 0) - (bParts[i] || 0);
      }
    }
    return a.criteria.localeCompare(b.criteria);
  });

  return `
  <div class="section">
      <h2>All Accessibility Violations</h2>
      <p>Organized by WCAG Success Criteria. Click to see details and fix recommendations.</p>
      <div style="margin-bottom: 20px;">
          <button type="button" onclick="expandAll()" style="margin-right: 10px; padding: 8px 16px; background: #00538a; color: white; border: none; border-radius: 4px; cursor: pointer;">Expand All</button>
          <button type="button" onclick="collapseAll()" style="margin-right: 10px; padding: 8px 16px; background: #495057; color: white; border: none; border-radius: 4px; cursor: pointer;">Collapse All</button>
          <button type="button" onclick="showOnlyHighPriority()" style="margin-right: 10px; padding: 8px 16px; background: #b3001a; color: white; border: none; border-radius: 4px; cursor: pointer;">Show High Priority Only</button>
          <button type="button" onclick="showAllViolations()" style="padding: 8px 16px; background: #1e7e34; color: white; border: none; border-radius: 4px; cursor: pointer;">Show All</button>
      </div>
      ${sortedWcagGroups.map(wcagGroup => renderWcagGroupCard(wcagGroup)).join('')}
  </div>`;
}

function renderWcagGroupCard(wcagGroup) {
  const levelColor = getWcagLevelColor(wcagGroup.level);
  const wcagId = wcagGroup.criteria.replace(/[^a-zA-Z0-9]/g, '-');

  return `
  <div class="violation-card">
      <div class="violation-header" role="button" tabindex="0" aria-expanded="false" aria-controls="details-${wcagId}" onclick="toggleViolation('${wcagId}')" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();toggleViolation('${wcagId}');}">
          <h3 class="violation-title">
              <span>${wcagGroup.criteria}</span>
              <span class="severity-badge" style="background-color: ${levelColor};">WCAG ${wcagGroup.level}</span>
          </h3>
          <div class="violation-meta">
              <span><strong>Failed Rules:</strong> ${wcagGroup.rules.length}</span>
              <span><strong>Total Failures:</strong> ${wcagGroup.rules.reduce((sum, r) => sum + r.totalFailures, 0)}</span>
              <span><strong>Affected Pages:</strong> ${new Set(wcagGroup.rules.flatMap(r => r.affectedPages.map(p => p.url))).size}</span>
              <span id="btn-${wcagId}" class="toggle-btn" aria-hidden="true">Show Details</span>
          </div>
      </div>
      <div id="details-${wcagId}" class="violation-details">
          <div class="description"><strong>WCAG Success Criterion:</strong> ${wcagGroup.criteria} (Level ${wcagGroup.level})</div>
          <div class="fix-recommendations">
              <h4>Failed Rules Under This Criterion</h4>
              <div class="recommendations-list">
                  ${wcagGroup.rules.map(violation => {
                    const ri = violation.ruleInfo;
                    const sc = getSeverityColor(ri.severity);
                    return `
                    <div style="background: white; padding: 15px; margin: 10px 0; border-radius: 6px; border-left: 4px solid ${sc};">
                      <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                        <strong style="color: #333;">${escapeHtml(ri.title)}</strong>
                        <span class="severity-badge" style="background-color: ${sc};">${ri.severity}</span>
                        <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 0.8em;">${violation.totalFailures} failures</span>
                      </div>
                      <div style="color: #666; margin-bottom: 10px; font-size: 0.9em;"><strong>Rule:</strong> ${violation.ruleId}</div>
                      <div style="color: #555; margin-bottom: 10px;">${ri.description}</div>
                      <div style="color: #155724; background: #d4edda; padding: 8px; border-radius: 4px; font-size: 0.9em;"><strong>Quick Fix:</strong> ${escapeHtml(ri.fixRecommendations[0] || 'See detailed recommendations')}</div>
                      <div style="margin-top: 10px;"><strong>Affected Pages:</strong> ${violation.affectedPages.length} page(s)
                        <div style="margin-top: 5px; font-size: 0.9em; color: #666;">${violation.affectedPages.slice(0, 3).map(p => p.path).join(', ')}${violation.affectedPages.length > 3 ? ` and ${violation.affectedPages.length - 3} more...` : ''}</div>
                      </div>
                    </div>`;
                  }).join('')}
              </div>
          </div>
          <div class="resources"><h4>WCAG Resources</h4>
              <a href="https://www.w3.org/WAI/WCAG21/Understanding/${wcagGroup.criteria.match(/(\d+\.\d+\.\d+)/)?.[1] || ''}" target="_blank" rel="noopener" class="resource-link">Understanding ${wcagGroup.criteria}</a>
              <a href="https://www.w3.org/WAI/WCAG21/Techniques/" target="_blank" rel="noopener" class="resource-link">WCAG Techniques and Failures</a>
          </div>
      </div>
  </div>`;
}

function renderNoViolationsSection() {
  return `<div class="section"><h2 style="color: #28a745;">No Accessibility Violations Found</h2><p>All tested pages passed the accessibility audit.</p></div>`;
}

async function renderPageByPageSection(results) {
  const rows = await Promise.all(results.map(async (result, index) => {
    let pageDetailsHtml = '';

    if (result.issues && result.issues.length > 0) {
      const pageRules = await Promise.all(result.issues.map(async (issue) => {
        const ruleInfo = await getRuleInfo(issue.rule);
        const sc = getSeverityColor(ruleInfo.severity);
        return `
          <div class="page-rule-item">
            <div class="page-rule-header"><span class="page-rule-title">${escapeHtml(ruleInfo.title)}</span><span class="severity-badge" style="background-color: ${sc};">${ruleInfo.severity}</span><span class="failure-count">${issue.failed} failures</span></div>
            <div class="page-rule-description">${ruleInfo.description}</div>
            <div class="page-rule-fix"><strong>Quick Fix:</strong> ${escapeHtml(ruleInfo.fixRecommendations[0] || 'See detailed recommendations above')}</div>
          </div>`;
      }));

      pageDetailsHtml = `
        <tr class="page-details-row" id="details-page-${index}" style="display: none;">
          <td colspan="4"><div class="page-details-content"><h4>Accessibility Issues on ${escapeHtml(result.path)}</h4><div class="page-rules-list">${pageRules.join('')}</div></div></td>
        </tr>`;
    }

    const hasIssues = result.issues && result.issues.length > 0;
    const interactiveAttrs = hasIssues
      ? `role="button" tabindex="0" aria-expanded="false" aria-controls="details-page-${index}" onclick="togglePageDetails(${index})" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();togglePageDetails(${index});}" class="page-row clickable"`
      : `class="page-row"`;

    return `
      <tr ${interactiveAttrs}>
          <td class="url-cell">${escapeHtml(result.url)}</td>
          <td class="${result.passed ? 'status-pass' : 'status-fail'}">${result.passed ? 'Pass' : 'Fail'}</td>
          <td class="issues-cell">${result.error ? `<span class="error-cell">Error: ${escapeHtml(result.error)}</span>` : (result.issues ? result.issues.length : 0)}${hasIssues ? ' <span class="click-hint">(click for details)</span>' : ''}</td>
          <td>${new Date(result.timestamp).toLocaleString()}</td>
      </tr>
      ${pageDetailsHtml}`;
  }));

  return `
  <div class="section">
      <h2>Page-by-Page Results</h2>
      <p>Click on any failed page to see detailed rule information and fix recommendations.</p>
      <table class="results-table">
          <thead><tr><th scope="col">URL</th><th scope="col">Status</th><th scope="col">Issues</th><th scope="col">Timestamp</th></tr></thead>
          <tbody>${rows.join('')}</tbody>
      </table>
  </div>`;
}

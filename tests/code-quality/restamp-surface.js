#!/usr/bin/env node
/**
 * Re-stamp the surface banner of a generated lint report so it reflects the
 * environment the report is *served from*, not where the linters happened to run.
 *
 * Why this exists: the `lint` CI job runs once, repo-wide, before any deploy to
 * the host/CI environment, so `buildSurface()` finds no MULTIDEV_URL and bakes
 * "Local (<branch>)" into lint-report.html. That single artifact is then shipped
 * to each theme's PR multidev unchanged. This script runs per-multidev in the
 * accessibility job (where MULTIDEV_URL / PR_NUMBER are known) and rewrites the
 * banner to the same "CI · multidev / pr-N" surface the unified index shows —
 * keeping the standalone lint report consistent with index.html.
 *
 * No-op unless the current surface resolves to `ci-multidev`, so running it
 * locally (or anywhere without MULTIDEV_URL) leaves the report untouched.
 *
 * Usage: node code-quality/restamp-surface.js <lint-report.html> [<test-suite-findings.json>]
 */
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { buildSurface } from '../accessibility/utils/findings-emitter.js';

// Mirrors the label map in lint-orchestrator.js generateSummaryReport() and the
// shell template (tests/reports/_shell/index.template.html) so all three views agree.
const SURFACE_LABELS = { local: 'Local', ci: 'CI', 'ci-multidev': 'CI · multidev' };

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

// Reproduces the exact .surface-banner markup lint-orchestrator emits so the
// report's existing CSS (.surface-banner / .surface-url) styles it identically.
function bannerHtml(surface) {
  const text = SURFACE_LABELS[surface.context] || 'Local';
  const url = surface.base_url || '';
  const detail = surface.label ? `(${surface.label})` : '';
  return `<div class="surface-banner" role="status">
                <strong>${text}</strong>
                ${url ? `<span class="surface-url">${escapeHtml(url)}</span>` : ''}
                ${detail ? `<span>${escapeHtml(detail)}</span>` : ''}
            </div>`;
}

const [htmlPath, jsonPath] = process.argv.slice(2);
if (!htmlPath) {
  console.error('Usage: node code-quality/restamp-surface.js <lint-report.html> [test-suite-findings.json]');
  process.exit(2);
}

const surface = buildSurface();

// Only re-stamp when we're actually on a multidev; otherwise the baked-in
// "Local (<branch>)" banner is already correct.
if (surface.context !== 'ci-multidev') {
  console.log(`restamp-surface: surface is "${surface.context}" (no MULTIDEV_URL) — leaving report unchanged`);
  process.exit(0);
}

if (existsSync(htmlPath)) {
  const html = readFileSync(htmlPath, 'utf8');
  // The banner has no nested <div>, so a non-greedy match to the first </div>
  // captures exactly the banner block and nothing else.
  const updated = html.replace(/<div class="surface-banner"[\s\S]*?<\/div>/, bannerHtml(surface));
  if (updated === html) {
    console.warn(`restamp-surface: no .surface-banner block found in ${htmlPath} — report left unchanged`);
  } else {
    writeFileSync(htmlPath, updated);
    console.log(`restamp-surface: ${htmlPath} → ${SURFACE_LABELS[surface.context]} ${surface.base_url} (${surface.label})`);
  }
} else {
  console.warn(`restamp-surface: ${htmlPath} not found — nothing to re-stamp`);
}

// Keep the machine-readable findings file consistent too. The unified index
// already prefers alfa-full's surface, but re-stamping lint's surface here
// makes index.html resilient if alfa-full ever fails to emit a surface.
if (jsonPath && existsSync(jsonPath)) {
  try {
    const data = JSON.parse(readFileSync(jsonPath, 'utf8'));
    data.surface = surface;
    writeFileSync(jsonPath, `${JSON.stringify(data, null, 2)}\n`);
    console.log(`restamp-surface: ${jsonPath} surface updated`);
  } catch (err) {
    console.warn(`restamp-surface: could not update ${jsonPath}: ${err.message}`);
  }
}

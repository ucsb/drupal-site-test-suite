#!/usr/bin/env node
/**
 * One-time maintenance tool — NOT part of the test run.
 *
 * Regenerates `rule-titles.json`, the static map of Siteimprove Alfa rule ids to
 * their human titles (e.g. SIA-R53 -> "Headings are structured"). The Alfa SDK
 * (@siteimprove/alfa-rules) ships each rule's URI and WCAG mapping but not its
 * title, so the titles are fetched once from the rule pages and committed as a
 * static file. At test time the report reads only that static file — there is no
 * runtime network.
 *
 * Run this after bumping @siteimprove/alfa-rules:
 *   node tests/accessibility/alfa/generate-rule-titles.mjs
 *
 * It prints a drift report (added / removed / changed rules) so it's obvious when
 * an SDK bump introduced or renamed rules. Unmapped rules degrade gracefully at
 * runtime (the report shows the rule id alone).
 */
import { readFileSync, writeFileSync } from "node:fs";
import { Rules } from "@siteimprove/alfa-rules";

const OUT = new URL("./rule-titles.json", import.meta.url);
const CONCURRENCY = 8;
// Decode the HTML entities the page <title> carries (e.g. "&lt;html&gt;",
// "&quot;") to plain text. The report renders the headline with plain
// escapeHtml(), so the stored title must be decoded — otherwise "&lt;html&gt;"
// would show literally instead of "<html>". Decode &amp; last.
const stripTitle = (s) =>
  s.replace(/\s*\|\s*Siteimprove.*$/i, "")
    .replace(/&lt;/g, "<")
    .replace(/&gt;/g, ">")
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/&rsquo;/g, "’")
    .replace(/&amp;/g, "&")
    .trim();

const targets = [];
for (const [id, rule] of Rules) {
  const uri = JSON.parse(JSON.stringify(rule)).uri;
  targets.push([`SIA-${String(id).toUpperCase()}`, uri]);
}

const titles = {};
let failed = 0;
async function worker(queue) {
  while (queue.length) {
    const [ruleId, uri] = queue.shift();
    try {
      const res = await fetch(uri, { signal: AbortSignal.timeout(15000) });
      const html = await res.text();
      const m = html.match(/<title>([^<]+)<\/title>/i) || html.match(/<h1[^>]*>([^<]+)<\/h1>/i);
      if (m) titles[ruleId] = stripTitle(m[1]);
      else failed++;
    } catch {
      failed++;
    }
  }
}
const queue = [...targets];
await Promise.all(Array.from({ length: CONCURRENCY }, () => worker(queue)));

// Drift report against the existing committed map.
let previous = {};
try {
  previous = JSON.parse(readFileSync(OUT, "utf8"));
} catch { /* first run */ }
const prevIds = new Set(Object.keys(previous));
const newIds = new Set(Object.keys(titles));
const added = [...newIds].filter((id) => !prevIds.has(id));
const removed = [...prevIds].filter((id) => !newIds.has(id));
const changed = [...newIds].filter((id) => prevIds.has(id) && previous[id] !== titles[id]);

const sorted = Object.fromEntries(
  Object.keys(titles).sort((a, b) => parseInt(a.slice(5), 10) - parseInt(b.slice(5), 10)).map((k) => [k, titles[k]]),
);
writeFileSync(OUT, JSON.stringify(sorted, null, 2) + "\n");

console.log(`Wrote ${Object.keys(sorted).length} rule titles (${failed} failed to fetch).`);
if (added.length) console.log(`  + added:   ${added.join(", ")}`);
if (removed.length) console.log(`  - removed: ${removed.join(", ")}`);
if (changed.length) console.log(`  ~ renamed: ${changed.join(", ")}`);
if (!added.length && !removed.length && !changed.length) console.log("  no drift — map already matches the SDK.");

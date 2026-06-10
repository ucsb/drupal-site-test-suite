/**
 * Alfa rule metadata, served from the in-process `@siteimprove/alfa-rules` SDK
 * — no network, no HTML scraping. Replaces the former HTTP/GitHub scraper while
 * preserving the public API and a subset of the previous `ruleInfo` shape, so
 * dynamic-rule-extractor.js and its callers are unchanged.
 *
 * The SDK exposes each rule's `uri`, WCAG `requirements`, and `tags` — but no
 * human title/description and no severity. So:
 *   - `title` is derived from the rule's primary WCAG criterion;
 *   - `wcagCriteria` come straight from `requirements[].chapter`;
 *   - severity is still inferred downstream from `wcagCriteria`
 *     (rule-fallbacks.js `inferSeverity`);
 *   - report headlines come from the override file
 *     (reports/_shell/rule-headlines.json).
 *
 * Rules the SDK doesn't ship (retired ids) resolve to `null` here so callers
 * fall back to rule-fallbacks.js.
 */
// The SDK is loaded lazily and defensively. If `@siteimprove/alfa-rules` is
// missing (a broken install), the map stays empty and every rule resolves to
// `null`, so callers fall back to rule-fallbacks.js — the Alfa audit still runs
// (it gets its rules from `@siteimprove/alfa-test-utils`, a separate package)
// instead of the whole lane crashing on a metadata dependency.
let mapPromise = null;
let loadedCount = 0;

function ruleMap() {
  if (!mapPromise) {
    mapPromise = (async () => {
      const map = new Map();
      try {
        const { Rules } = await import('@siteimprove/alfa-rules');
        for (const [id, rule] of Rules) {
          map.set(String(id).toUpperCase(), rule);
        }
        loadedCount = map.size;
      } catch (err) {
        console.warn(`⚠️  @siteimprove/alfa-rules unavailable — Alfa rule metadata will use static fallbacks: ${err.message}`);
      }
      return map;
    })();
  }
  return mapPromise;
}

/**
 * Normalize any rule reference to canonical `SIA-R<n>`:
 *   'R2' | 'SIA-R2' | 'sia-r2' | '…/rules/sia-r2' | 2  ->  'SIA-R2'
 */
export function extractRuleId(input) {
  if (input === null || input === undefined) return null;
  const s = String(input).trim();
  const m = s.match(/r-?(\d+)/i) || s.match(/(\d+)/);
  return m ? `SIA-R${m[1]}` : s.toUpperCase();
}

// 'SIA-R2' -> 'R2' (the SDK map key).
function bareId(siaId) {
  const m = String(siaId).match(/R(\d+)/i);
  return m ? `R${m[1]}` : String(siaId).toUpperCase();
}

/**
 * Resolve a rule's metadata from the SDK. Async to preserve the former API;
 * the lookup itself is synchronous and in-process.
 */
export async function fetchRuleFromAPI(ruleId) {
  const sia = extractRuleId(ruleId);
  const map = await ruleMap();
  const rule = map.get(bareId(sia));
  if (!rule) return null;

  const plain = JSON.parse(JSON.stringify(rule));
  const criteria = (plain.requirements || []).filter(r => r.type === 'criterion');
  const wcagCriteria = criteria.map(c => `${c.chapter}${c.title ? ` ${c.title}` : ''}`.trim());

  return {
    id: sia,
    uri: plain.uri || `https://alfa.siteimprove.com/rules/${bareId(sia).toLowerCase()}`,
    title: criteria[0]?.title || sia,
    description: null,
    wcagCriteria: wcagCriteria.length ? wcagCriteria : ['Unknown'],
    fixRecommendations: null,
    codeExamples: null,
    source: 'alfa-sdk',
  };
}

/** Stats shim — no network, so nothing is ever in flight. */
export function getApiStats() {
  return { activeRequests: 0, queuedRequests: 0, sdkRulesLoaded: loadedCount };
}

/** No-op — there are no pending network requests to await. */
export async function waitForPendingRequests() {
  /* intentionally empty */
}

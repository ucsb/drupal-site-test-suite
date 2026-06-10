/**
 * Rule metadata accessor for Alfa accessibility rules.
 *
 * Resolves rule metadata from the in-process SDK (rule-fetcher.js), falling back
 * to static data (rule-fallbacks.js) for rules the SDK doesn't ship. There is no
 * network and no persistent cache anymore — lookups are synchronous in-process,
 * memoized only to avoid re-deriving the same rule across a page set.
 */
import { extractRuleId, fetchRuleFromAPI, getApiStats, waitForPendingRequests } from './rule-fetcher.js';
import {
  getFallbackRuleInfo, enhanceWithFixRecommendations,
  getSeverityColor, getCategoryIcon,
} from './rule-fallbacks.js';

// In-memory memo: the same rule id is looked up many times across a crawl.
const memo = new Map();

// ─── Public API ──────────────────────────────────────────────────────────────

/**
 * Get comprehensive rule information, with fallback for retired/absent rules.
 */
export async function getRuleInfo(ruleInput) {
  const ruleId = extractRuleId(ruleInput);
  if (memo.has(ruleId)) return memo.get(ruleId);

  // SDK first (in-process); fall back to static data for retired ids.
  let ruleInfo = await fetchRuleFromAPI(ruleId);
  if (!ruleInfo) ruleInfo = getFallbackRuleInfo(ruleId);

  const enhanced = enhanceWithFixRecommendations(ruleInfo, ruleId);
  memo.set(ruleId, enhanced);
  return enhanced;
}

/**
 * Batch resolve multiple rules.
 */
export async function batchGetRuleInfo(ruleInputs) {
  return Promise.all(ruleInputs.map(r => getRuleInfo(r)));
}

/**
 * Preload is a no-op now — SDK lookups are instant.
 */
export async function preloadCommonRules() {
  /* intentionally empty */
}

// ─── Re-exports ──────────────────────────────────────────────────────────────

export { getSeverityColor, getCategoryIcon } from './rule-fallbacks.js';
export { getApiStats, waitForPendingRequests } from './rule-fetcher.js';

// Cache shims kept for backward compatibility (no persistent cache anymore).
export function clearRuleCache() { memo.clear(); }
export function clearPersistentCache() { /* no-op */ }
export function getCacheStats() { return { size: memo.size, persistent: false }; }

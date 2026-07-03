/**
 * Rule metadata accessor for Alfa accessibility rules.
 *
 * Resolves rule metadata from the in-process SDK (rule-fetcher.js), falling back
 * to static data (rule-fallbacks.js) for rules the SDK doesn't ship. There is no
 * network and no persistent cache anymore - lookups are synchronous in-process,
 * memoized only to avoid re-deriving the same rule across a page set.
 */
import { extractRuleId, fetchRuleFromAPI } from './rule-fetcher.js';
import {
  getFallbackRuleInfo, enhanceWithFixRecommendations,
} from './rule-fallbacks.js';

// In-memory memo: the same rule id is looked up many times across a crawl.
const memo = new Map();

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

export { getSeverityColor, getCategoryIcon } from './rule-fallbacks.js';

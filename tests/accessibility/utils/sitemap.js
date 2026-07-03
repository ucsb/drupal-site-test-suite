/**
 * Shared sitemap URL fetching utility.
 *
 * Used by alfa-full-site.spec.js and axe-watcher-full-site.spec.ts
 * to avoid duplicating the same fetch + parse + fallback logic.
 *
 * @module utils/sitemap
 */

/**
 * Fetch URLs from a sitemap XML, with a timeout.
 *
 * Throws a clear, actionable error when the sitemap is unreachable, empty, or
 * on the wrong host, so every full-site lane fails loudly (consistent with
 * pa11y) rather than silently testing a couple of fallback pages.
 *
 * @param {string} sitemapUrl  - Full URL to sitemap.xml
 * @param {Object} options
 * @param {string} options.baseUrl   - Base URL to filter URLs by domain
 * @param {number} [options.maxPages=50] - Maximum number of URLs to return; pass 0 for "no cap" (full sitemap)
 * @param {number} [options.timeoutMs=30000] - Fetch timeout in milliseconds
 * @param {Object} [options.headers] - Extra request headers (e.g. a hosting bot-challenge bypass in CI)
 * @returns {Promise<string[]>} Non-empty array of absolute URLs.
 * @throws {Error} If the sitemap is unreachable, has no URLs, or none match the host.
 */
export async function fetchSitemapUrls(sitemapUrl, {
  baseUrl,
  maxPages = 50,
  timeoutMs = 30000,
  headers,
} = {}) {
  // Treat 0 (or any non-positive) as "no cap" — drush surfaces this as
  // --max-pages=all so contributors can opt into a full-sitemap sweep.
  const noCap = !Number.isFinite(maxPages) || maxPages <= 0;
  const base = baseUrl || sitemapUrl.replace(/\/sitemap\.xml$/, '');

  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), timeoutMs);
  let response;
  try {
    response = await fetch(sitemapUrl, {
      signal: controller.signal,
      ...(headers ? { headers } : {}),
    });
  } catch (error) {
    throw new Error(`Sitemap not accessible: ${sitemapUrl} (${error.message}). Ensure the site is running and the sitemap exists.`);
  } finally {
    clearTimeout(timeoutId);
  }

  if (!response.ok) {
    throw new Error(`Sitemap not accessible: ${sitemapUrl} (HTTP ${response.status}). Ensure the site is running and the sitemap exists.`);
  }

  const sitemapText = await response.text();
  const urlMatches = sitemapText.match(/<loc>(.*?)<\/loc>/g);
  if (!urlMatches) {
    throw new Error(`Sitemap contains no URLs: ${sitemapUrl}. Check that the sitemap is properly generated.`);
  }

  const all = urlMatches.map(match => match.replace(/<\/?loc>/g, ''));
  // Same-origin only, compared on parsed origins. A prefix check would accept
  // cross-host lookalikes such as https://site.example@evil.com/.
  let baseOrigin;
  try {
    baseOrigin = new URL(base).origin;
  } catch (error) {
    throw new Error(`Base URL is not a valid URL: ${base} (${error.message}).`);
  }
  const filtered = all.filter(url => {
    try {
      return new URL(url).origin === baseOrigin;
    } catch {
      return false;
    }
  });
  if (filtered.length === 0) {
    throw new Error(`Sitemap has ${all.length} URL(s) but none match the origin of ${base} (host mismatch). Regenerate the sitemap with the correct base URL. Sample: ${all.slice(0, 3).join(', ')}`);
  }

  const urls = noCap ? filtered : filtered.slice(0, maxPages);
  console.log(noCap
    ? `Found ${urls.length} URLs in sitemap (no cap — full-sitemap sweep)`
    : `Found ${urls.length} URLs in sitemap (limited to ${maxPages})`);
  return urls;
}

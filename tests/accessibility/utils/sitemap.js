/**
 * Shared sitemap URL fetching utility.
 *
 * Used by alfa-full-site.spec.js and axe-watcher-full-site.spec.ts
 * to avoid duplicating the same fetch + parse + fallback logic.
 *
 * @module utils/sitemap
 */

/**
 * Fetch URLs from a sitemap XML, with timeout and fallback.
 *
 * @param {string} sitemapUrl  - Full URL to sitemap.xml
 * @param {Object} options
 * @param {string} options.baseUrl   - Base URL to filter URLs by domain
 * @param {number} [options.maxPages=50] - Maximum number of URLs to return; pass 0 for "no cap" (full sitemap)
 * @param {number} [options.timeoutMs=30000] - Fetch timeout in milliseconds
 * @param {string[]} [options.fallbackPaths=['/','/user/login']] - Fallback paths if sitemap fails
 * @returns {Promise<string[]>} Array of absolute URLs
 */
export async function fetchSitemapUrls(sitemapUrl, {
  baseUrl,
  maxPages = 50,
  timeoutMs = 30000,
  fallbackPaths = ['/', '/user/login'],
} = {}) {
  // Treat 0 (or any non-positive) as "no cap" — drush surfaces this as
  // --max-pages=all so contributors can opt into a full-sitemap sweep.
  const noCap = !Number.isFinite(maxPages) || maxPages <= 0;
  const base = baseUrl || sitemapUrl.replace(/\/sitemap\.xml$/, '');

  try {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), timeoutMs);
    const response = await fetch(sitemapUrl, { signal: controller.signal });
    clearTimeout(timeoutId);

    if (!response.ok) {
      throw new Error(`Failed to fetch sitemap: ${response.status}`);
    }

    const sitemapText = await response.text();

    // Extract URLs from sitemap XML
    const urlMatches = sitemapText.match(/<loc>(.*?)<\/loc>/g);
    if (!urlMatches) {
      throw new Error('No URLs found in sitemap');
    }

    const filtered = urlMatches
      .map(match => match.replace(/<\/?loc>/g, ''))
      .filter(url => url.startsWith(base)); // Only test URLs from the same domain
    const urls = noCap ? filtered : filtered.slice(0, maxPages);

    if (noCap) {
      console.log(`Found ${urls.length} URLs in sitemap (no cap — full-sitemap sweep)`);
    } else {
      console.log(`Found ${urls.length} URLs in sitemap (limited to ${maxPages})`);
    }
    return urls;
  } catch (error) {
    console.error('Error fetching sitemap:', error.message);
    // Fallback to standard pages if sitemap fails
    const fallbackUrls = fallbackPaths.map(p => `${base}${p}`);
    console.log(`Using fallback URLs: ${fallbackUrls.join(', ')}`);
    return fallbackUrls;
  }
}

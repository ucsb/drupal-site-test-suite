import { test, expect } from '@playwright/test';
import { chromium, BrowserContext } from 'playwright';
import {
  wrapPlaywrightPage,
  playwrightConfig,
  PlaywrightController
} from '@axe-core/watcher';
import { getAccessibilityConfig, getProfileInfo } from '../config/a11y-profiles.js';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8888';
const PATHS = (process.env.PLAYWRIGHT_PATHS || '/,/user/login').split(',').map(s => s.trim());
const API_KEY = process.env.AXE_API_KEY;

// Get axe-specific configuration
const axeConfig = getAccessibilityConfig('axe');
const profileInfo = getProfileInfo();

test.describe(`A11y (Playwright + axe Developer Hub) - ${profileInfo.name}`, () => {
  let browserContext: BrowserContext;

  test.beforeAll(async () => {
    if (!API_KEY) {
      throw new Error('AXE_API_KEY environment variable is required for axe Developer Hub integration');
    }

    // Prepare axe configuration for Developer Hub
    const axeHubConfig: any = {
      apiKey: API_KEY,
      // Include rule tags from accessibility profile
      tags: axeConfig.tags || ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'],
    };

    // Add runOnly configuration if available
    if (axeConfig.options && axeConfig.options.runOnly) {
      axeHubConfig.runOnly = axeConfig.options.runOnly;
    }

    console.log(`DEBUG: axe Developer Hub Configuration:`);
    console.log(`   API Key: ${API_KEY ? 'Set' : 'Not set'}`);
    console.log(`   Tags: ${axeHubConfig.tags.join(', ')}`);
    console.log(`   RunOnly: ${axeHubConfig.runOnly ? JSON.stringify(axeHubConfig.runOnly) : 'Not set'}`);

    // Create browser context with comprehensive axe Developer Hub configuration
    browserContext = await chromium.launchPersistentContext(
      '', // Use temporary directory for user data
      playwrightConfig({
        axe: axeHubConfig,
        headless: true,
        channel: 'chromium',
        args: ['--headless=new']
      })
    );
  });

  test.afterAll(async () => {
    if (browserContext) {
      await browserContext.close();
    }
  });

  for (const p of PATHS) {
    test(`axe Developer Hub ${p}`, async () => {
      console.log(`Testing accessibility for: ${BASE}${p}`);
      console.log(`Using ${profileInfo.name}: ${profileInfo.description}`);
      console.log(` Rule tags: ${axeConfig.tags ? axeConfig.tags.join(', ') : 'undefined'}`);
      console.log(`⚠️  Severity levels: ${axeConfig.severity ? axeConfig.severity.join(', ') : 'undefined'}`);
      
      // Create a page instance using the browser context
      let page = await browserContext.newPage();

      // Initialize the PlaywrightController
      const pageController = new PlaywrightController(page);

      // Wrap the Playwright page
      page = wrapPlaywrightPage(page, pageController);

      try {
        await page.goto(`${BASE}${p}`);
        
        // Wait for page to be fully loaded
        await page.waitForLoadState('networkidle');

        // The axe Developer Hub integration will automatically run
        // and send results to the Developer Hub dashboard
        // No local axe analysis needed - this would conflict with the Developer Hub integration
        
        // Basic page validation to ensure the page loaded successfully
        const title = await page.title();
        console.log(`Page title: ${title}`);
        
        // Simple assertion that the page loaded (has a title)
        expect(title).toBeTruthy();
        
        console.log(`✅ Page loaded successfully - results sent to axe Developer Hub`);
      } finally {
        // Ensure results are flushed to axe Developer Hub
        if (pageController) {
          await pageController.flush();
        }
        await page.close();
      }
    });
  }
});

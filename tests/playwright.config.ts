import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for Drupal site testing
 * Supports dynamic output directory configuration via environment variables
 */
export default defineConfig({
  testDir: './accessibility',
  
  /* Run tests in files in parallel */
  fullyParallel: true,
  
  /* Fail the build on CI if you accidentally left test.only in the source code. */
  forbidOnly: !!process.env.CI,
  
  /* Disable global retries - using page-specific retry logic instead */
  retries: 0,
  
  /* Opt out of parallel tests on CI. */
  workers: process.env.CI ? 1 : undefined,
  
  /* Reporter configuration - use environment variable for output directory if provided */
  reporter: [
    ['html', { 
      outputFolder: process.env.PLAYWRIGHT_REPORT_DIR || 'playwright-report',
      open: 'never'
    }],
    ['list']
  ],
  
  /* Shared settings for all the projects below. */
  use: {
    /* Base URL to use in actions like `await page.goto('/')`. */
    baseURL: process.env.BASE_URL || 'http://127.0.0.1:8888',
    
    /* Collect trace when retrying the failed test. */
    trace: 'on-first-retry',
    
    /* Take screenshot on failure */
    screenshot: 'only-on-failure',
    
    /* Set longer timeout for page navigation to handle slow-loading pages */
    navigationTimeout: 90000, // 90 seconds for page navigation
    actionTimeout: 30000,     // 30 seconds for actions like clicks, fills, etc.
  },

  /* Global test timeout - maximum time for entire test */
  timeout: 300000, // 5 minutes for full site accessibility tests

  /* Timeout for each individual expect() assertion */
  expect: {
    timeout: 10000 // 10 seconds for assertions
  },

  /* Configure projects for major browsers */
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],

  /* Run your local dev server before starting the tests */
  // webServer: {
  //   command: 'npm run start',
  //   url: 'http://127.0.0.1:3000',
  //   reuseExistingServer: !process.env.CI,
  // },
});

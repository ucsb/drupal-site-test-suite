/**
 * Accessibility Testing Profiles Configuration
 * 
 * This file defines testing profiles that work across both Siteimprove Alfa
 * and axe-core/axe Developer Hub frameworks. Each profile includes framework-specific
 * configurations to account for differences in rule engines and capabilities.
 */

const TESTING_PROFILES = {
  strict: {
    name: 'Strict Mode (WCAG Level A only)',
    description: 'Only WCAG 2.0/2.1 Level A compliance rules with all severity levels - most restrictive rule set',
    
    // Shared configuration for both frameworks
    shared: {
      tags: ['wcag2a', 'wcag21a'],
      severity: ['critical', 'serious', 'moderate', 'minor']
    },
    
    // axe-core specific configuration
    axe: {
      tags: ['wcag2a', 'wcag21a'],
      severity: ['critical', 'serious', 'moderate', 'minor'],
      options: {
        runOnly: {
          type: 'tag',
          values: ['wcag2a', 'wcag21a']
        }
      }
    },
    
    // Siteimprove Alfa specific configuration
    alfa: {
      tags: ['wcag2a', 'wcag21a'],
      options: {
        includeInconclusiveResults: false,
        waitForNetworkIdle: true,
        timeout: 30000
      }
    }
  },

  standard: {
    name: 'Standard Mode (WCAG Level A + AA)',
    description: 'WCAG 2.0/2.1 Level A and AA compliance rules with all severity levels - balanced testing',
    
    shared: {
      tags: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'],
      severity: ['critical', 'serious', 'moderate', 'minor']
    },
    
    axe: {
      tags: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'],
      severity: ['critical', 'serious', 'moderate', 'minor'],
      options: {
        runOnly: {
          type: 'tag',
          values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']
        }
      }
    },
    
    alfa: {
      tags: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'],
      options: {
        includeInconclusiveResults: false,
        waitForNetworkIdle: true,
        timeout: 30000
      }
    }
  },

  comprehensive: {
    name: 'Comprehensive Mode (All WCAG Levels + Best Practices)',
    description: 'Complete accessibility testing: All WCAG 2.0/2.1 levels (A/AA/AAA) + WCAG 2.2 (A/AA) + best practices with all severity levels - most thorough testing',

    shared: {
      // WCAG 2.2 A + AA included so 2.2-only rules (notably target-size,
      // SC 2.5.8) fire under the comprehensive profile. AAA from 2.2 is
      // intentionally omitted — strict superset of what we need for
      // mobile-target coverage without dragging in AAA noise from a
      // standard most sites haven't ratified yet.
      tags: ['wcag2a', 'wcag2aa', 'wcag2aaa', 'wcag21a', 'wcag21aa', 'wcag21aaa', 'wcag22a', 'wcag22aa', 'best-practice'],
      severity: ['critical', 'serious', 'moderate', 'minor']
    },

    axe: {
      tags: ['wcag2a', 'wcag2aa', 'wcag2aaa', 'wcag21a', 'wcag21aa', 'wcag21aaa', 'wcag22a', 'wcag22aa', 'best-practice'],
      severity: ['critical', 'serious', 'moderate', 'minor'],
      options: {
        runOnly: {
          // 'cat.aria' ensures ARIA-only rules run even when not also tagged
          // with a WCAG level (e.g. axe's `aria-text` rule). Most ARIA-related
          // axe rules are already covered via wcag2a/4.1.2, but pure best-
          // practice ARIA rules need this category tag too. Findings emitted
          // by these rules carry their own per-finding tags (incl. 'wai-aria')
          // so the report can filter to ARIA-only findings without matching
          // every finding from a lane that happens to run ARIA rules.
          type: 'tag',
          values: ['wcag2a', 'wcag2aa', 'wcag2aaa', 'wcag21a', 'wcag21aa', 'wcag21aaa', 'wcag22a', 'wcag22aa', 'best-practice', 'cat.aria']
        }
      }
    },

    alfa: {
      // Alfa's tag filter doesn't recognize 'best-practice' (its rule set
      // already includes best-practice rules in default coverage). WAI-ARIA
      // Authoring Practices coverage is also implicit in Alfa's default rule
      // set — findings from ARIA-targeted Alfa rules (SIA-R20, R21, R68 etc.)
      // get a per-finding 'wai-aria' tag at test-suite-findings.json emission time.
      // wcag22a/wcag22aa are passed through to Alfa as well; unknown tags are
      // silently ignored by Alfa's filter, so this is safe even on Alfa builds
      // that don't yet recognize 2.2.
      tags: ['wcag2a', 'wcag2aa', 'wcag2aaa', 'wcag21a', 'wcag21aa', 'wcag21aaa', 'wcag22a', 'wcag22aa'],
      options: {
        includeInconclusiveResults: false,
        waitForNetworkIdle: true,
        timeout: 30000
      }
    }
  },

  custom: {
    name: 'Custom Mode (User-defined)',
    description: 'User-defined rule combinations via environment variables with all severity levels',
    
    shared: {
      tags: [], // Populated from environment variables
      severity: ['critical', 'serious', 'moderate', 'minor']
    },
    
    axe: {
      tags: [],
      severity: ['critical', 'serious', 'moderate', 'minor'],
      options: {
        runOnly: {
          type: 'tag',
          values: []
        }
      }
    },
    
    alfa: {
      tags: [],
      options: {
        includeInconclusiveResults: false,
        waitForNetworkIdle: true,
        timeout: 30000
      }
    }
  }
};

/**
 * Get accessibility configuration for a specific framework
 * 
 * @param {string} framework - 'axe', 'alfa', or 'shared'
 * @returns {object} Configuration object for the specified framework
 */
function getAccessibilityConfig(framework = 'shared', profileOverride = null) {
  // Default to comprehensive mode (test all levels)
  const profile = profileOverride || process.env.A11Y_PROFILE || 'comprehensive';
  
  if (!TESTING_PROFILES[profile]) {
    console.warn(`Unknown accessibility profile: ${profile}. Using 'comprehensive' instead.`);
    return getAccessibilityConfig(framework, 'comprehensive');
  }
  
  const baseConfig = TESTING_PROFILES[profile];
  let config;
  
  // Get framework-specific configuration
  if (framework === 'axe') {
    config = { ...baseConfig.axe };
  } else if (framework === 'alfa') {
    config = { ...baseConfig.alfa };
  } else {
    config = { ...baseConfig.shared };
  }
  
  // Handle custom profile with environment variables
  if (profile === 'custom') {
    const customTags = (process.env.A11Y_CUSTOM_TAGS || 'wcag2a,wcag2aa,wcag21a,wcag21aa')
      .split(',')
      .map(t => t.trim())
      .filter(t => t.length > 0);
    
    config.tags = customTags;
    
    if (framework === 'axe' && config.options && config.options.runOnly) {
      config.options.runOnly.values = customTags;
    }
  }
  
  // Allow override of severity levels
  if (process.env.A11Y_SEVERITY_LEVELS) {
    config.severity = process.env.A11Y_SEVERITY_LEVELS
      .split(',')
      .map(s => s.trim())
      .filter(s => s.length > 0);
  }
  
  return config;
}

/**
 * Get profile information for display/logging
 * 
 * @param {string} profileName - Profile name to get info for
 * @returns {object} Profile information
 */
function getProfileInfo(profileName = null) {
  const profile = profileName || process.env.A11Y_PROFILE || 'comprehensive';
  
  if (!TESTING_PROFILES[profile]) {
    return {
      name: 'Unknown Profile',
      description: 'Profile not found'
    };
  }
  
  return {
    name: TESTING_PROFILES[profile].name,
    description: TESTING_PROFILES[profile].description,
    profile: profile
  };
}

/**
 * List all available profiles
 * 
 * @returns {array} Array of profile information objects
 */
function listProfiles() {
  return Object.keys(TESTING_PROFILES).map(key => ({
    key,
    name: TESTING_PROFILES[key].name,
    description: TESTING_PROFILES[key].description
  }));
}

export {
  TESTING_PROFILES,
  getAccessibilityConfig,
  getProfileInfo,
  listProfiles
};

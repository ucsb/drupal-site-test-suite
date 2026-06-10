/**
 * Accessibility Rule Mappings and Reference
 * 
 * This file provides reference information about available rule tags,
 * severity levels, and framework-specific rule differences between
 * axe-core and Siteimprove Alfa.
 */

/**
 * WCAG Rule Tag Descriptions
 * These tags are supported by both axe-core and Siteimprove Alfa
 */
const RULE_TAG_DESCRIPTIONS = {
  // WCAG 2.0 Tags
  'wcag2a': {
    description: 'WCAG 2.0 Level A compliance rules',
    level: 'A',
    version: '2.0',
    examples: ['Images have alt text', 'Form controls have labels', 'Page has title']
  },
  'wcag2aa': {
    description: 'WCAG 2.0 Level AA compliance rules',
    level: 'AA', 
    version: '2.0',
    examples: ['Color contrast 4.5:1', 'Text can resize to 200%', 'Focus is visible']
  },
  'wcag2aaa': {
    description: 'WCAG 2.0 Level AAA compliance rules',
    level: 'AAA',
    version: '2.0',
    examples: ['Color contrast 7:1', 'No flashing content', 'Context-sensitive help']
  },
  
  // WCAG 2.1 Tags
  'wcag21a': {
    description: 'WCAG 2.1 Level A compliance rules',
    level: 'A',
    version: '2.1',
    examples: ['Character key shortcuts', 'Pointer gestures', 'Motion actuation']
  },
  'wcag21aa': {
    description: 'WCAG 2.1 Level AA compliance rules',
    level: 'AA',
    version: '2.1',
    examples: ['Reflow content', 'Non-text contrast', 'Target size 44px']
  },
  'wcag21aaa': {
    description: 'WCAG 2.1 Level AAA compliance rules',
    level: 'AAA',
    version: '2.1',
    examples: ['Animation from interactions', 'Concurrent input mechanisms']
  },
  
  // WCAG 2.2 Tags (Future support)
  'wcag22a': {
    description: 'WCAG 2.2 Level A compliance rules',
    level: 'A',
    version: '2.2',
    examples: ['Page break navigation', 'Focus not obscured']
  },
  'wcag22aa': {
    description: 'WCAG 2.2 Level AA compliance rules',
    level: 'AA',
    version: '2.2',
    examples: ['Focus not obscured enhanced', 'Dragging movements']
  },
  
  // Best Practices and Categories
  'best-practice': {
    description: 'Accessibility best practices beyond WCAG requirements',
    level: 'Best Practice',
    version: 'N/A',
    examples: ['Descriptive link text', 'Logical tab order', 'Consistent navigation'],
    note: 'Supported by axe-core; Alfa includes best practices in comprehensive rule coverage'
  },
  
  // Category-specific tags (primarily axe-core)
  'cat.color': {
    description: 'Color and contrast related rules',
    framework: 'axe-core',
    examples: ['color-contrast', 'color-contrast-enhanced']
  },
  'cat.keyboard': {
    description: 'Keyboard accessibility rules',
    framework: 'axe-core',
    examples: ['accesskeys', 'tabindex', 'focus-order-semantics']
  },
  'cat.forms': {
    description: 'Form accessibility rules',
    framework: 'axe-core',
    examples: ['label', 'form-field-multiple-labels', 'select-name']
  },
  'cat.images': {
    description: 'Image accessibility rules',
    framework: 'axe-core',
    examples: ['image-alt', 'image-redundant-alt', 'object-alt']
  },
  'cat.headings': {
    description: 'Heading structure rules',
    framework: 'axe-core',
    examples: ['heading-order', 'empty-heading', 'page-has-heading-one']
  },
  'cat.tables': {
    description: 'Table accessibility rules',
    framework: 'axe-core',
    examples: ['table-headers', 'td-headers-attr', 'th-has-data-cells']
  }
};

/**
 * Severity Level Descriptions
 */
const SEVERITY_LEVELS = {
  'minor': {
    description: 'Minor accessibility issues that may affect some users',
    impact: 'Low',
    priority: 4,
    examples: ['Missing skip links', 'Non-descriptive link text']
  },
  'moderate': {
    description: 'Moderate accessibility issues that affect user experience',
    impact: 'Medium',
    priority: 3,
    examples: ['Poor color contrast', 'Missing form labels']
  },
  'serious': {
    description: 'Serious accessibility violations that significantly impact users',
    impact: 'High',
    priority: 2,
    examples: ['Missing alt text', 'Keyboard traps', 'No focus indicators']
  },
  'critical': {
    description: 'Critical accessibility violations that prevent access',
    impact: 'Very High',
    priority: 1,
    examples: ['No keyboard access', 'Missing page titles', 'Broken ARIA']
  }
};

/**
 * Framework-Specific Rule Differences
 */
const FRAMEWORK_DIFFERENCES = {
  'axe-core': {
    name: 'axe-core 4.10 (Deque Systems)',
    ruleCount: '90+',
    ruleFormat: 'kebab-case (e.g., color-contrast, heading-order)',
    strengths: [
      'Fast automated testing',
      'Low false positive rate',
      'Excellent CI/CD integration',
      'Comprehensive category tags',
      'Active community support'
    ],
    limitations: [
      'Some manual testing still required',
      'Limited complex interaction testing',
      'May miss some edge cases'
    ],
    uniqueFeatures: [
      'Category-based rule filtering',
      'Custom rule development',
      'Browser extension available',
      'Real-time testing in DevTools'
    ]
  },
  
  'siteimprove-alfa': {
    name: 'Siteimprove Alfa',
    ruleCount: '100+',
    ruleFormat: 'SIA-R format (e.g., SIA-R61, SIA-R72, SIA-R111)',
    strengths: [
      'Comprehensive rule coverage',
      'Detailed violation reporting',
      'Advanced ARIA support',
      'Complex interaction testing',
      'Enterprise-grade accuracy'
    ],
    limitations: [
      'Slower execution than axe-core',
      'More complex setup',
      'Fewer community resources'
    ],
    uniqueFeatures: [
      'Dynamic rule information fetching',
      'Detailed fix recommendations',
      'WCAG criteria mapping',
      'Advanced parsing capabilities'
    ]
  }
};

/**
 * Get rule tag information
 * 
 * @param {string} tag - Rule tag to get information for
 * @returns {object} Tag information object
 */
function getRuleTagInfo(tag) {
  return RULE_TAG_DESCRIPTIONS[tag] || {
    description: 'Unknown rule tag',
    level: 'Unknown',
    version: 'Unknown'
  };
}

/**
 * Get severity level information
 * 
 * @param {string} level - Severity level to get information for
 * @returns {object} Severity information object
 */
function getSeverityInfo(level) {
  return SEVERITY_LEVELS[level] || {
    description: 'Unknown severity level',
    impact: 'Unknown',
    priority: 0
  };
}

/**
 * Get framework comparison information
 * 
 * @param {string} framework - Framework to get information for
 * @returns {object} Framework information object
 */
function getFrameworkInfo(framework) {
  const key = framework === 'axe' ? 'axe-core' : 'siteimprove-alfa';
  return FRAMEWORK_DIFFERENCES[key] || {
    name: 'Unknown Framework',
    ruleCount: 'Unknown',
    ruleFormat: 'Unknown'
  };
}

/**
 * Get recommended tags for a specific compliance level
 * 
 * @param {string} level - Compliance level ('a', 'aa', 'aaa', 'best-practice')
 * @param {string} version - WCAG version ('2.0', '2.1', '2.2', 'all')
 * @returns {array} Array of recommended rule tags
 */
function getRecommendedTags(level = 'aa', version = 'all') {
  const tags = [];
  
  if (version === 'all' || version === '2.0') {
    if (level === 'a' || level === 'aa' || level === 'aaa') {
      tags.push('wcag2a');
    }
    if (level === 'aa' || level === 'aaa') {
      tags.push('wcag2aa');
    }
    if (level === 'aaa') {
      tags.push('wcag2aaa');
    }
  }
  
  if (version === 'all' || version === '2.1') {
    if (level === 'a' || level === 'aa' || level === 'aaa') {
      tags.push('wcag21a');
    }
    if (level === 'aa' || level === 'aaa') {
      tags.push('wcag21aa');
    }
    if (level === 'aaa') {
      tags.push('wcag21aaa');
    }
  }
  
  if (version === 'all' || version === '2.2') {
    if (level === 'a' || level === 'aa' || level === 'aaa') {
      tags.push('wcag22a');
    }
    if (level === 'aa' || level === 'aaa') {
      tags.push('wcag22aa');
    }
  }
  
  if (level === 'best-practice') {
    tags.push('best-practice');
  }
  
  return tags;
}

export {
  RULE_TAG_DESCRIPTIONS,
  SEVERITY_LEVELS,
  FRAMEWORK_DIFFERENCES,
  getRuleTagInfo,
  getSeverityInfo,
  getFrameworkInfo,
  getRecommendedTags
};

/**
 * Fallback rule data, severity inference, fix recommendations,
 * code examples, category mapping, and display utilities.
 */

// ─── Fallback Rule Data ──────────────────────────────────────────────────────

export function getFallbackRuleInfo(ruleId) {
  const commonRules = {
    'SIA-R2': { title: 'Images must have accessible names', description: 'Images that convey information must have alternative text.', wcagCriteria: ['1.1.1 Non-text Content'], category: 'Images' },
    'SIA-R4': { title: 'Page must have a valid language', description: 'The html element must have a valid lang attribute so assistive tech pronounces content correctly.', wcagCriteria: ['3.1.1 Language of Page'], category: 'Structure' },
    'SIA-R16': { title: 'Form elements must have accessible names', description: 'Form controls must have accessible names that describe their purpose.', wcagCriteria: ['4.1.2 Name, Role, Value'], category: 'Forms' },
    'SIA-R78': { title: 'Headings must have accessible names', description: 'Heading elements must contain text content or have accessible names.', wcagCriteria: ['1.3.1 Info and Relationships'], category: 'Structure' }
  };

  return commonRules[ruleId] || {
    title: `Rule ${ruleId}`,
    description: 'Accessibility rule violation detected.',
    wcagCriteria: ['Unknown'],
    category: 'General'
  };
}

// ─── Severity Inference ──────────────────────────────────────────────────────

/**
 * Infer severity from rule ID and WCAG criteria.
 *
 * WCAG Level A → 'serious', Level AA → 'moderate', Level AAA → 'minor'.
 * Explicit overrides for well-known critical rules take priority.
 */
export function inferSeverity(ruleId, wcagCriteria) {
  const severityOverrides = {
    'SIA-R2': 'serious', 'SIA-R4': 'serious', 'SIA-R16': 'serious', 'SIA-R78': 'serious',
    'SIA-R1': 'critical', 'SIA-R11': 'serious', 'SIA-R12': 'serious', 'SIA-R14': 'serious',
    'SIA-R53': 'serious', 'SIA-R64': 'serious', 'SIA-R87': 'moderate',
  };

  if (severityOverrides[ruleId]) return severityOverrides[ruleId];

  if (wcagCriteria && wcagCriteria.length > 0) {
    const levelACriteria = [
      '1.1.1', '1.2.1', '1.2.2', '1.2.3', '1.3.1', '1.3.2', '1.3.3',
      '1.4.1', '1.4.2', '2.1.1', '2.1.2', '2.1.4', '2.2.1', '2.2.2',
      '2.3.1', '2.4.1', '2.4.2', '2.4.3', '2.4.4', '2.5.1', '2.5.2',
      '2.5.3', '2.5.4', '3.1.1', '3.2.1', '3.2.2', '3.3.1', '3.3.2',
      '4.1.1', '4.1.2', '4.1.3'
    ];

    for (const criteria of wcagCriteria) {
      const match = criteria.match(/(\d+\.\d+\.\d+)/);
      if (match && levelACriteria.includes(match[1])) return 'serious';
    }

    if (wcagCriteria.some(c => c.match(/\d+\.\d+\.\d+/))) return 'moderate';
  }

  return 'moderate';
}

// ─── Enhancement ─────────────────────────────────────────────────────────────

/**
 * Enhance rule info with fix recommendations, code examples, resources, severity, category.
 */
export function enhanceWithFixRecommendations(ruleInfo, ruleId) {
  let fixRecommendations = ruleInfo.fixRecommendations;
  if (!fixRecommendations || fixRecommendations.length === 0) {
    fixRecommendations = getFixRecommendations(ruleId, ruleInfo.category);
  }

  let codeExamples = ruleInfo.codeExamples;
  if (!codeExamples || (!codeExamples.bad && !codeExamples.good)) {
    codeExamples = getCodeExamples(ruleId, ruleInfo.category);
  }

  const resources = getResources(ruleInfo.wcagCriteria);

  return {
    ...ruleInfo,
    severity: ruleInfo.severity || inferSeverity(ruleId, ruleInfo.wcagCriteria),
    category: ruleInfo.category || getCategoryFromRuleId(ruleId),
    fixRecommendations,
    codeExamples,
    resources
  };
}

// ─── Fix Recommendations ────────────────────────────────────────────────────

function getFixRecommendations(ruleId, category) {
  const specificRecommendations = {
    'SIA-R2': [
      'Add meaningful alt text that describes the image content',
      'Use empty alt="" for decorative images',
      'For complex images, provide detailed descriptions via aria-describedby'
    ],
    'SIA-R4': [
      'Provide descriptive link text that makes sense out of context',
      'Avoid generic text like "click here" or "read more"',
      'Use aria-label for links with non-descriptive visible text'
    ],
    'SIA-R16': [
      'Add a <label> element associated with the form control',
      'Use aria-label attribute to provide an accessible name',
      'Use aria-labelledby to reference other elements that describe the control'
    ],
    'SIA-R78': [
      'Ensure heading elements contain descriptive text',
      'Use aria-label if the heading needs additional context',
      'Avoid empty headings or headings with only whitespace'
    ]
  };

  if (specificRecommendations[ruleId]) return specificRecommendations[ruleId];

  const categoryRecommendations = {
    'Images': [
      'Provide meaningful alternative text for informative images',
      'Use empty alt="" for decorative images that don\'t convey information',
      'Ensure alt text is concise and describes the image\'s purpose',
      'For complex images, consider using aria-describedby for detailed descriptions'
    ],
    'Navigation': [
      'Ensure link text clearly describes the destination or purpose',
      'Avoid generic link text like "click here" or "read more"',
      'Use aria-label to provide additional context when needed',
      'Make sure links are keyboard accessible and have visible focus indicators'
    ],
    'Forms': [
      'Associate form controls with descriptive labels using <label> elements',
      'Use aria-label or aria-labelledby when visual labels aren\'t sufficient',
      'Provide clear instructions and error messages',
      'Ensure form controls are keyboard accessible'
    ],
    'Structure': [
      'Use proper heading hierarchy (h1, h2, h3, etc.) to organize content',
      'Ensure headings contain meaningful text that describes the section',
      'Use semantic HTML elements (nav, main, section, article) appropriately',
      'Provide skip links for keyboard navigation'
    ],
    'ARIA': [
      'Use ARIA attributes correctly according to the ARIA specification',
      'Ensure ARIA labels and descriptions are meaningful and accurate',
      'Test with screen readers to verify ARIA implementation',
      'Prefer semantic HTML over ARIA when possible'
    ],
    'Color': [
      'Ensure sufficient color contrast between text and background',
      'Don\'t rely solely on color to convey important information',
      'Test color combinations with contrast checking tools',
      'Consider users with color vision deficiencies'
    ],
    'Keyboard': [
      'Ensure all interactive elements are keyboard accessible',
      'Provide visible focus indicators for keyboard navigation',
      'Implement logical tab order throughout the page',
      'Test navigation using only the keyboard'
    ],
    'HTML': [
      'Use valid, semantic HTML markup',
      'Ensure proper nesting and structure of HTML elements',
      'Include required attributes for accessibility',
      'Validate HTML markup for compliance'
    ]
  };

  if (category && categoryRecommendations[category]) return categoryRecommendations[category];

  return [
    'Review the element that failed this accessibility rule',
    'Check the element\'s HTML structure and attributes',
    'Ensure the element follows accessibility best practices',
    'Test the fix with keyboard navigation and screen readers',
    'Consult WCAG documentation for specific guidance on this rule'
  ];
}

// ─── Code Examples ───────────────────────────────────────────────────────────

function getCodeExamples(ruleId, category) {
  const examples = {
    'SIA-R2': { bad: '<img src="chart.png">', good: '<img src="chart.png" alt="Sales increased 25% from Q1 to Q2">' },
    'SIA-R4': { bad: '<a href="/article1">Read more</a>', good: '<a href="/article1">Read more about accessibility testing</a>' },
    'SIA-R16': { bad: '<input type="text" placeholder="Enter your name">', good: '<label for="name">Full Name</label>\n<input type="text" id="name" placeholder="Enter your name">' },
    'SIA-R78': { bad: '<h2></h2>', good: '<h2>Contact Information</h2>' }
  };

  return examples[ruleId] || { bad: 'No example available', good: 'No example available' };
}

// ─── Category & Resources ────────────────────────────────────────────────────

export function getCategoryFromRuleId(ruleId) {
  if (ruleId.includes('R2') || ruleId.includes('R14')) return 'Images';
  if (ruleId.includes('R4') || ruleId.includes('R11')) return 'Navigation';
  if (ruleId.includes('R16')) return 'Forms';
  if (ruleId.includes('R78') || ruleId.includes('R57')) return 'Structure';
  return 'General';
}

function getResources(wcagCriteria) {
  const baseResources = ['https://www.w3.org/WAI/WCAG21/', 'https://webaim.org/'];
  if (wcagCriteria.some(c => c.includes('1.1.1'))) baseResources.unshift('https://www.w3.org/WAI/WCAG21/Understanding/non-text-content.html');
  if (wcagCriteria.some(c => c.includes('2.4.4'))) baseResources.unshift('https://www.w3.org/WAI/WCAG21/Understanding/link-purpose-in-context.html');
  if (wcagCriteria.some(c => c.includes('4.1.2'))) baseResources.unshift('https://www.w3.org/WAI/WCAG21/Understanding/name-role-value.html');
  return baseResources;
}

// ─── Display Utilities ───────────────────────────────────────────────────────

export function getSeverityColor(severity) {
  // Darker shades pick the AA-compliant ratio against white badge text
  // (WCAG 1.4.3 — 4.5:1 minimum for normal text). The lighter pastels we
  // had before failed contrast for `serious`, `moderate`, and `minor`.
  const colors = { critical: '#9b1c15', serious: '#8a4500', moderate: '#7a5a00', minor: '#1e6b22' };
  return colors[severity] || colors.moderate;
}

export function getCategoryIcon(category) {
  // Category icon disabled — drush console output keeps only ✅ / ❌ / ⚠️
  // as status markers. Callers may keep using this as a prefix; it just
  // resolves to empty.
  return '';
}

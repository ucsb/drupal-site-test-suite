#!/usr/bin/env node

/**
 * Simple test script to verify the accessibility configuration system
 * Run with: node tests/accessibility/config/test-config.js
 */

import { getAccessibilityConfig, getProfileInfo, listProfiles } from './a11y-profiles.js';
import { getRuleTagInfo, getSeverityInfo, getFrameworkInfo } from './rule-mappings.js';

console.log('Testing Accessibility Configuration System\n');

// Test 1: List all available profiles
console.log('Available Profiles:');
const profiles = listProfiles();
profiles.forEach(profile => {
  console.log(`   ${profile.key}: ${profile.name}`);
  console.log(`      ${profile.description}\n`);
});

// Test 2: Test each profile with different frameworks
const testProfiles = ['strict', 'standard', 'comprehensive', 'custom'];

testProfiles.forEach(profileName => {
  console.log(`\nTesting Profile: ${profileName.toUpperCase()}`);
  
  // Set environment variable for testing
  process.env.A11Y_PROFILE = profileName;
  
  if (profileName === 'custom') {
    process.env.A11Y_CUSTOM_TAGS = 'wcag2a,wcag21aa,best-practice';
  }
  
  // Test profile info
  const profileInfo = getProfileInfo();
  console.log(`   Profile Info: ${profileInfo.name}`);
  console.log(`   Description: ${profileInfo.description}`);
  
  // Test framework-specific configurations
  ['axe', 'alfa', 'shared'].forEach(framework => {
    const config = getAccessibilityConfig(framework);
    console.log(`   ${framework.toUpperCase()} Config:`);
    console.log(`      Tags: ${config.tags.join(', ')}`);
    console.log(`      Severity: ${config.severity ? config.severity.join(', ') : 'default'}`);
    
    if (config.options) {
      console.log(`      Options: ${Object.keys(config.options).join(', ')}`);
    }
  });
});

// Test 3: Test rule tag information
console.log('\nRule Tag Information:');
const testTags = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'];
testTags.forEach(tag => {
  const tagInfo = getRuleTagInfo(tag);
  console.log(`   ${tag}: ${tagInfo.description} (Level ${tagInfo.level}, WCAG ${tagInfo.version})`);
});

// Test 4: Test severity information
console.log('\n⚠️  Severity Levels:');
const testSeverities = ['critical', 'serious', 'moderate', 'minor'];
testSeverities.forEach(severity => {
  const severityInfo = getSeverityInfo(severity);
  console.log(`   ${severity}: ${severityInfo.description} (Priority ${severityInfo.priority})`);
});

// Test 5: Test framework information
console.log('\nFramework Information:');
['axe', 'siteimprove-alfa'].forEach(framework => {
  const frameworkInfo = getFrameworkInfo(framework);
  console.log(`   ${frameworkInfo.name}:`);
  console.log(`      Rule Count: ${frameworkInfo.ruleCount}`);
  console.log(`      Rule Format: ${frameworkInfo.ruleFormat}`);
  if (frameworkInfo.strengths) {
    console.log(`      Strengths: ${frameworkInfo.strengths.slice(0, 2).join(', ')}...`);
  }
});

// Test 6: Test environment variable overrides
console.log('\nTesting Environment Variable Overrides:');
process.env.A11Y_PROFILE = 'standard';
process.env.A11Y_SEVERITY_LEVELS = 'critical,serious,moderate';

const overrideConfig = getAccessibilityConfig('axe');
console.log(`   Override Test - Profile: ${getProfileInfo().name}`);
console.log(`   Override Test - Severity: ${overrideConfig.severity.join(', ')}`);

// Clean up environment variables
delete process.env.A11Y_PROFILE;
delete process.env.A11Y_CUSTOM_TAGS;
delete process.env.A11Y_SEVERITY_LEVELS;

console.log('\n✅ Configuration system test completed successfully!');
console.log('\nUsage Examples:');
console.log('   # Use comprehensive profile (default)');
console.log('   drush utest:a11y:alfa --base-url=https://example.com');
console.log('');
console.log('   # Use standard profile');
console.log('   A11Y_PROFILE=standard drush utest:a11y:axe-watcher --base-url=https://example.com');
console.log('');
console.log('   # Use custom profile');
console.log('   A11Y_PROFILE=custom A11Y_CUSTOM_TAGS=wcag2a,wcag21aa drush utest:a11y:alfa');

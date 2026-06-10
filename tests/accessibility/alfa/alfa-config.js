// Legacy configuration file - maintained for backward compatibility
// New projects should use the centralized configuration in ../config/a11y-profiles.js

import { getAccessibilityConfig, getProfileInfo } from '../config/a11y-profiles.js';

// Get current configuration from the centralized system
const currentConfig = getAccessibilityConfig('alfa');
const profileInfo = getProfileInfo();

// Legacy format for backward compatibility
const rules = {
  tags: currentConfig.tags,
  description: `${profileInfo.name}: ${profileInfo.description}`
};

// Enhanced configuration options
const options = currentConfig.options || {
  waitForNetworkIdle: true,
  timeout: 30000,
  includeInconclusiveResults: false
};

// Current profile information
const profile = {
  name: profileInfo.name,
  description: profileInfo.description,
  tags: currentConfig.tags
};

export {
  rules,
  options,
  getAccessibilityConfig,
  getProfileInfo,
  profile
};

export default { rules, options, getAccessibilityConfig, getProfileInfo, profile };

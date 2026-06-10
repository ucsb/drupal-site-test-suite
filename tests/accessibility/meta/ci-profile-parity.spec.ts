/**
 * CI profile-parity regression guard.
 *
 * CI is scoped to WCAG 2.0/2.1 A + AA — no AAA, no best-practice, no
 * cat.aria. Expanding the `comprehensive` preset has, in the past, silently
 * tightened CI because the workflow used to pin `A11Y_PROFILE: comprehensive`.
 * CI is now on `standard`. This test asserts both halves of that decision:
 *
 *   1. Every `A11Y_PROFILE` assignment in pr-tests.yml uses the expected
 *      preset name (CI doesn't accidentally inherit a stricter or laxer
 *      profile via a per-step override).
 *   2. The named preset still resolves to the expected WCAG scope (no one
 *      added AAA / cat.aria / best-practice to it without updating CI).
 *
 * If you intentionally change CI's gating scope, update EXPECTED_* below.
 */

import { test, expect } from "@playwright/test";
import { readFileSync } from "fs";
import { resolve, dirname } from "path";
import { fileURLToPath } from "url";

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const REPO_ROOT = resolve(__dirname, "..", "..", "..");
const WORKFLOW_PATH = resolve(REPO_ROOT, ".github/workflows/pr-tests.yml");

const EXPECTED_CI_PROFILE = "standard";
const EXPECTED_TAGS = ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"];
const FORBIDDEN_TAGS = ["wcag2aaa", "wcag21aaa", "cat.aria", "best-practice"];

test.describe("CI profile parity", () => {
  test("every A11Y_PROFILE assignment in pr-tests.yml uses the expected preset", () => {
    const yaml = readFileSync(WORKFLOW_PATH, "utf8");
    const assignments: Array<{ raw: string; value: string }> = [];

    // YAML env / step `env:` blocks → `A11Y_PROFILE: <value>`. Skip GitHub
    // Actions expressions like `${{ env.A11Y_PROFILE }}` — those are forwards
    // of the top-level env into nested step scopes, not new assignments.
    for (const m of yaml.matchAll(/^\s*A11Y_PROFILE:\s*(\S+)/gm)) {
      if (m[1].startsWith("${{")) continue;
      assignments.push({ raw: m[0].trim(), value: m[1] });
    }
    // Inline shell exports inside `run:` blocks → `export A11Y_PROFILE=<value>`.
    for (const m of yaml.matchAll(/export\s+A11Y_PROFILE=(\S+)/g)) {
      assignments.push({ raw: m[0].trim(), value: m[1] });
    }
    // JS fallbacks in inline scripts → `process.env.A11Y_PROFILE || 'value'`.
    for (const m of yaml.matchAll(/process\.env\.A11Y_PROFILE\s*\|\|\s*['"]([^'"]+)['"]/g)) {
      assignments.push({ raw: m[0].trim(), value: m[1] });
    }

    expect(assignments.length, "no A11Y_PROFILE assignments found in workflow — regex drifted?").toBeGreaterThan(0);
    for (const a of assignments) {
      expect(a.value, `${a.raw} must equal "${EXPECTED_CI_PROFILE}"`).toBe(EXPECTED_CI_PROFILE);
    }
  });

  test("the configured CI preset resolves to the expected WCAG scope", async () => {
    // Pin the env so getAccessibilityConfig returns the CI preset, not whatever
    // the local shell happened to have set.
    process.env.A11Y_PROFILE = EXPECTED_CI_PROFILE;
    delete process.env.A11Y_CUSTOM_TAGS;
    delete process.env.A11Y_SEVERITY_LEVELS;

    const { getAccessibilityConfig } = await import("../config/a11y-profiles.js");

    // Check shared, axe-runtime, and alfa-runtime configs all stay in scope.
    // axe's runOnly.values is what actually filters axe at runtime, so a
    // mismatch there is the most likely silent-drift vector.
    const surfaces = [
      { name: "shared", tags: getAccessibilityConfig("shared").tags },
      { name: "axe.tags", tags: getAccessibilityConfig("axe").tags },
      { name: "axe.runOnly.values", tags: getAccessibilityConfig("axe").options.runOnly.values },
      { name: "alfa.tags", tags: getAccessibilityConfig("alfa").tags },
    ];

    for (const surface of surfaces) {
      for (const required of EXPECTED_TAGS) {
        expect(surface.tags, `${surface.name} must include "${required}"`).toContain(required);
      }
      for (const forbidden of FORBIDDEN_TAGS) {
        expect(surface.tags, `${surface.name} must not include "${forbidden}" — CI is A + AA only`).not.toContain(forbidden);
      }
    }
  });
});

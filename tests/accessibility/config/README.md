# Accessibility Testing Configuration

Shared configuration for the profile-driven accessibility lanes (Siteimprove
Alfa and axe-core / axe Developer Hub). Both tools read the same profile,
so a run tests the same WCAG scope no matter which tool executes it.

For commands and reports, see the [main README](../../README.md) and the
[Cheat Sheet](../../README_cheatsheet.md). This document covers only the
configuration system in this directory.

## Files

- `a11y-profiles.js` - profile definitions: rule tags, severity levels, and
  tool options per profile
- `rule-mappings.js` - rule tag reference and tool differences
- `test-config.js` - prints the resolved configuration for every profile
  (validation utility)
- `README.md` - this file

## Profiles

| Profile | Rule tags | Use |
| --- | --- | --- |
| `comprehensive` (default) | WCAG 2.0 and 2.1 A / AA / AAA, WCAG 2.2 A / AA, `best-practice` | Full visibility; the CI default |
| `standard` | WCAG 2.0 and 2.1 A / AA | Conformance-focused runs |
| `strict` | WCAG 2.0 and 2.1 A only | Minimum baseline |
| `custom` | From `A11Y_CUSTOM_TAGS` | Special cases |

Every profile reports all four severity levels (critical, serious, moderate,
minor). Gating on critical/serious happens in the lanes and CI, not here.

## Lanes that do not read this config

- **pa11y** honors the same `A11Y_PROFILE`, but the drush command maps it to
  a pa11y-ci standard instead (`strict` = WCAG2A, `standard` = WCAG2AA,
  `comprehensive` = WCAG2AAA), because pa11y uses named standards, not
  rule tags. To adjust other pa11y-ci settings, create
  `tests/accessibility/pa11y/.pa11yci.base.json`; it merges over the
  generated defaults.
- **reflow** and **meta-viewport** each test one fixed WCAG criterion
  (1.4.10 and 1.4.4) and take no profile.

## Selecting a profile

Via drush options:

```bash
drush utest:alfa --a11y-profile=standard
drush utest:axe --a11y-profile=custom --a11y-custom-tags=wcag2a,wcag21aa
```

Via environment variables (used by CI, works locally too):

```bash
export A11Y_PROFILE=comprehensive       # comprehensive|standard|strict|custom
export A11Y_CUSTOM_TAGS=wcag2a,wcag21aa # custom profile only
export A11Y_SEVERITY_LEVELS=critical,serious  # severities that fail the run
```

An unknown profile falls back to `comprehensive` with a warning.

## Tool differences

Both tools consume the same profile, but implement rules differently:

- **axe-core**: kebab-case rule ids (`color-contrast`), 90+ rules, supports
  the `best-practice` tag and `cat.*` category tags.
- **Siteimprove Alfa**: `SIA-R` rule ids (`SIA-R61`), 100+ rules, no
  `best-practice` tag (equivalent checks are part of its normal coverage).

Different findings between the tools are expected; that is why the suite
runs both.

## Rule tag reference

- WCAG 2.0: `wcag2a`, `wcag2aa`, `wcag2aaa`
- WCAG 2.1: `wcag21a`, `wcag21aa`, `wcag21aaa`
- WCAG 2.2: `wcag22a`, `wcag22aa`
- Best practices (axe-core only): `best-practice`
- Categories (axe-core only): `cat.color`, `cat.keyboard`, `cat.forms`,
  `cat.images`, `cat.headings`, `cat.tables`

## Validating changes

After editing `a11y-profiles.js` or `rule-mappings.js`, print the resolved
configuration for every profile and tool:

```bash
cd tests/accessibility/config
node test-config.js
```

Test an override the same way:

```bash
A11Y_PROFILE=custom A11Y_CUSTOM_TAGS=wcag2a,wcag21aa node test-config.js
```

## Adding a profile

1. Add the profile to `a11y-profiles.js` (tags, severity, tool options).
2. Run `node test-config.js` and check both tool configs resolve.
3. Document the profile in the table above.

## Troubleshooting

- **"Unknown accessibility profile"**: check the `A11Y_PROFILE` spelling; the
  run falls back to `comprehensive`.
- **Custom tags ignored**: `A11Y_PROFILE=custom` must be set for
  `A11Y_CUSTOM_TAGS` to apply.
- **Tools disagree**: expected; rule implementations differ. Findings
  reported by both tools are the highest-confidence ones.

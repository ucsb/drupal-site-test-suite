# Drupal AI Tooling

A copy of the Drupal **skills**, **agents**, and **prompts** used to drive AI
assistants (Claude Code, GitHub Copilot) against a Drupal 10 / 11 site. They
pair with the `drush utest:*` test suite in this repo: the suite **produces**
findings, these tools **read, prioritize, and remediate** them - plus scaffold
new modules/themes, write PHPUnit tests, manage config and secrets, and more.

This folder is a **copy-in** distribution. You copy the pieces you want into
your AI tool's skills/agents/prompts folders (user-level or per-repo), and the
assistant discovers them automatically.

## Disclaimer

We built these tools for our own Drupal work and are sharing them in case they're
useful to others. They are provided **as-is, without warranty of any kind** and
without any commitment to support, maintenance, or future updates. Use at your own
risk under the terms of this repository's [LICENSE](../../LICENSE).

They are AI assistants, not authorities: review every change before you commit it,
and treat their output as a starting point, not a guarantee of correctness,
security, or compliance. In particular, a clean accessibility or code-quality
report does **not** certify a site as accessible or compliant - see
[Caveats](#caveats). Nothing here is legal advice.

## What's here

```text
drupal-ai/
├── skills/      - 11 Drupal skills (each a folder with a SKILL.md)
├── agents/      - 3 read-only / specialist subagents
└── prompts/     - 3 end-to-end workflow prompts (slash commands)
```

### Skills (`skills/`)

| Skill | What it does |
| --- | --- |
| `drupal-coding-standards` | Shared modern Drupal 10/11 coding + documentation standards (the base other skills defer to) |
| `drupal-code-quality-audit` | Read-only triage of PHPCS / PHPStan / ESLint / cspell / composer findings into a prioritized report |
| `drupal-code-quality-remediation` | Applies only safe, mechanical lint fixes (phpcbf, eslint --fix, …); flags the rest for human review |
| `drupal-accessibility-audit` | Read-only WCAG audit - aggregates axe/Alfa/pa11y/reflow lanes, classifies legal vs aspirational |
| `drupal-accessibility-remediation` | Fixes accessibility findings in custom code (Twig/CSS/config), dry-run + confirm, never commits |
| `drupal-module-scaffold` | Scaffolds a new custom module with proper DI, attributes, tests, and standards from the start |
| `drupal-theme-scaffold` | Scaffolds a new custom sub-theme (Starterkit-based) - info.yml, libraries, templates, preprocess |
| `drupal-phpunit-tests` | Writes Unit + Kernel tests for custom modules (service logic, access, config, entity validation) |
| `drupal-hook-update-n` | Writes `hook_update_N` / `hook_post_update_NAME` update functions + paired update-path tests |
| `drupal-config-management` | The config sync workflow (export/import), config_split, config_ignore, drift diagnosis |
| `drupal-secrets-management` | Handling API keys/tokens via the Key module without committing secrets |

### Agents (`agents/`)

| Agent | What it does |
| --- | --- |
| `drupal-code-quality-diagnostic` | Read-only engine behind the code-quality audit skill (dedup, group, enrich, prioritize) |
| `drupal-accessibility-diagnostic` | Read-only engine behind the accessibility audit skill (classify legal/aspirational, attribute custom/upstream) |
| `a11y` | WCAG 2.2 AA correction patterns. **Dependency** of the accessibility skills - they cite it for *how* to fix issues. Include it if you install either accessibility skill. |

### Prompts (`prompts/`)

| Prompt | What it does |
| --- | --- |
| `drupal-audit` | Read-only audit (code-quality and/or accessibility) → prioritized findings report. No changes. |
| `drupal-remediate` | Audit, then apply **only safe** fixes - dry-run, confirm, working tree only, re-verify |
| `drupal-ready` | Pre-merge go/no-go - runs the gate (audits + tests) and reviews changed code against standards |

## How discovery works (and why naming matters)

- **Claude Code** supports nested folders, so each skill stays a self-contained
  folder with its `adapters/`, `reference/`, etc.
- **GitHub Copilot** discovers content **only at the top level** of its
  `skills/`, `agents/`, and `prompts/` folders and does **not** recurse into
  category subfolders. That's why everything is grouped by the `drupal-` **name
  prefix** rather than nested directories. Keep the prefix when you copy.

The accessibility skills reference the **`a11y`** agent for fix patterns. On a
runtime with registered subagents (Claude Code) it's dispatched as a subagent;
on Copilot the skills fall back to instructions bundled inside the skill. Copy
`a11y.agent.md` alongside the accessibility skills either way.

## Install - Claude Code

Pick a scope and copy the folders in. Claude Code auto-discovers them on the
next session.

**User-level (available in every project):**

```bash
SRC="$(pwd)"   # run from examples/drupal-ai

mkdir -p ~/.claude/skills ~/.claude/agents ~/.claude/commands

# Skills (nested folders are fine for Claude Code)
cp -R "$SRC"/skills/drupal-* ~/.claude/skills/

# Agents (include a11y if you use the accessibility skills)
cp "$SRC"/agents/*.agent.md ~/.claude/agents/

# Prompts → Claude slash commands
cp "$SRC"/prompts/*.prompt.md ~/.claude/commands/
```

**Project-level (commit to a specific repo, shared with the team):**

```bash
mkdir -p .claude/skills .claude/agents .claude/commands
cp -R "$SRC"/skills/drupal-* .claude/skills/
cp    "$SRC"/agents/*.agent.md .claude/agents/
cp    "$SRC"/prompts/*.prompt.md .claude/commands/
```

Then in a Claude Code session:

- Skills load automatically when relevant, or invoke explicitly (e.g.
  `/drupal-module-scaffold`).
- Run a workflow prompt as a slash command: `/drupal-audit`, `/drupal-remediate`,
  `/drupal-ready`.
- Confirm they're loaded with `/skills` and `/agents`.

Note: these prompt files use `{{args}}` placeholder syntax. Claude Code passes
what you type after the command name as the argument string, so they work as-is.

## Install - GitHub Copilot

Copilot needs a **flat** top level - copy skill folders and the `.md` files
directly into the top of each folder (no extra nesting).

**User-level:**

```bash
SRC="$(pwd)"   # run from examples/drupal-ai

mkdir -p ~/.copilot/skills ~/.copilot/agents ~/.copilot/prompts

cp -R "$SRC"/skills/drupal-* ~/.copilot/skills/
cp    "$SRC"/agents/*.agent.md ~/.copilot/agents/
cp    "$SRC"/prompts/*.prompt.md ~/.copilot/prompts/
```

**Organization / repo-level (Copilot Custom Agents + skills):** place the files
under your repo's `.github/` so Copilot picks them up org-wide:

```bash
mkdir -p .github/agents .github/prompts .github/skills
cp    "$SRC"/agents/*.agent.md .github/agents/
cp    "$SRC"/prompts/*.prompt.md .github/prompts/
cp -R "$SRC"/skills/drupal-* .github/skills/
```

Copilot references:

- [About agent skills](https://docs.github.com/en/copilot/concepts/agents/about-agent-skills)
- [Create custom agents](https://docs.github.com/en/copilot/how-tos/copilot-on-github/customize-copilot/customize-cloud-agent/create-custom-agents)
- [Your first prompt file](https://docs.github.com/en/copilot/tutorials/customization-library/prompt-files/your-first-prompt-file)

## How to use them

You don't need to memorize the skill names. Open the assistant in your site's
folder and ask in plain English; the right skill activates from what you typed.
Examples:

- *"Audit my Drupal site for accessibility problems."*
- *"What coding-standards issues are in my custom modules?"*
- *"Fix the safe lint errors, but show me a diff first."*
- *"Scaffold a new custom module called `acme_events`."*
- *"Scaffold a new sub-theme called `acme_theme`."*
- *"Write Unit and Kernel tests for my `acme_events` service."*
- *"Write an update hook to rename my module on existing sites."*
- *"Export my config changes / set up a dev-only config split."*
- *"How do I use an API key in my module without committing it?"*

If you prefer a menu, in **Claude Code** type `/` and pick a workflow prompt:

- `/drupal-audit` - read-only audit (code-quality and/or accessibility) into a
  prioritized findings report. Changes nothing.
- `/drupal-remediate` - audits, then applies only the safe fixes after showing a
  diff and asking. Working tree only.
- `/drupal-ready` - runs the gate (audits + tests) and reviews the changed code
  against Drupal standards, then gives a GO / NO-GO verdict.

On **GitHub Copilot** the `/` shortcuts aren't available - just ask in plain
words; everything else works the same.

## Where your findings come from

The audit skills are flexible about input and pick automatically:

- **Your site has this test suite** → they run it (`drush utest:*`) and read the
  results. Best fidelity, since it's the same checks CI runs.
- **Your site has no test suite** → they run the standard tools directly on your
  custom code (accessibility runs against your live site). Best-effort; the skill
  tells you what it couldn't run.
- **You already have a report** → hand the assistant a results file or a report
  URL and it reads that instead of re-running.

## Safety guarantees

- **Auditing never changes anything.** It only reads and reports.
- **Fixes are always preview-first.** You see a diff and confirm before any file
  is written.
- **It never commits and never pushes.** Changes sit in your working tree for you
  to review and commit yourself.
- **Custom code only.** The skills don't modify core, contrib, `vendor/`, or
  `node_modules/`.
- **Secrets are never committed.** The secrets skill keeps keys out of the repo;
  if one was already committed, it tells you to rotate it.

## Caveats

- **Automated checks are not complete.** A clean report covers only what the
  tools check - for accessibility that's roughly a third of WCAG.
- **Accessibility still needs human testing.** After fixes, test with a screen
  reader, keyboard-only navigation, and 200% zoom. The skills never claim a site
  is "fully accessible" or "WCAG compliant."
- **A near-empty report isn't always good news.** If tools were skipped (not
  installed, site unreachable), the skill says so - read the coverage line, not
  just the totals.
- **You're always in control.** Review every change before committing.

## Keeping in sync

These are a point-in-time **copy** of the Drupal skills, agents, and prompts that
ship with this repo. They have no install step beyond the `cp` commands above and
no dependency on any external CLI or private tooling. To update, re-copy the
`drupal-*` items (plus `a11y.agent.md`) from this folder into your AI tool's
skills/agents/prompts directories. Edit the copies in your tool folders for
site-local tweaks; the versions here stay as the clean baseline.

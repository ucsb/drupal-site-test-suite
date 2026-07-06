---
name: drupal-secrets-management
description: Handle secrets (API keys, tokens, credentials) in custom Drupal 10/11 code without committing them, the Key module as the abstraction, reading keys at runtime via dependency injection, and provider setup (Pantheon Secrets, environment variables, file-based). Use when a custom module needs a credential at runtime, or when removing a hard-coded/committed secret. Vendor-neutral core with hosting-specific provider sections; follows drupal-coding-standards.
metadata:
  version: "2026.07.06"
---

# Drupal Secrets Management

Keeps secrets (API keys, tokens, passwords) **out of the codebase** while still letting custom modules use them at runtime. The model: a secret lives in a **provider** (a host secret store, an environment variable, or a private file), the **Key module** abstracts where it lives, and code reads it through an injected service. Never a literal in PHP/YAML/JS.

> **Rule #1; never commit a secret to version control, at any time, in any file** (code, config, fixtures, a `.env` that isn't git-ignored; and remember git *history* keeps it even after deletion). A secret in the repo is a leaked secret: rotate it.

Vendor-neutral core; the provider details are hosting-specific. Follows **`drupal-coding-standards`** (DI, no static `\Drupal::` in classes).

**Key vs Encrypt:** this skill uses the **Key module** to *read a credential*; that's all most modules need. The **Encrypt module** (`drupal/encrypt`) is a *different* need: encrypting **data at rest** inside Drupal (e.g. stored field values), and it uses a Key for its encryption key. Reach for Encrypt only when you must encrypt stored data, not to consume an API key.

## Prerequisites

- A custom module that needs a credential at runtime.
- The **Key module** (`drupal/key`), the standard abstraction (`composer require drupal/key`).
- A place to store the secret value (see providers).

## When To Use

- A custom module integrates with an external API and needs a key/token.
- You're removing a hard-coded or committed secret (the `drupal-code-quality-*` suite flagged a `gitleaks` finding).
- You're choosing/standardizing how this site stores secrets.

## Core principle: secrets never live in the repo

- **Never** commit or hard-code a secret (PHP literal, `settings.php` value in VCS, YAML, JS, fixture).
- Store the value in a **provider**; reference it in Drupal as a **Key**; read it in code via the injected `key.repository` service.
- If a secret was committed, it's compromised: **remove it AND rotate it** (a deleted git line still lives in history). The `drupal-code-quality-*` skills detect committed secrets (`gitleaks`) as `critical`/`security`.
- **Never log secret values** or echo them in messages/exceptions.

## How it fits together

- The **Key module** abstraction; defining a key, choosing a key type + provider, and reading it in code → `information/key-module.md`
- **Providers**: Pantheon Secrets, environment variables, file-based (each with setup) → `information/providers.md`
- **CI/CD pipeline secrets** (deploy tokens, SSH keys, test API keys like `AXE_API_KEY`) are a *separate* category; stored in your CI platform's secret store (e.g. GitHub Actions repo secrets), **not** the Key module → `information/providers.md`

## Guardrails

- Custom code only; never edit core/contrib to wire secrets.
- Read secrets through `key.repository` (injected), not `getenv()` scattered through classes, not `\Drupal::service()` in a class method.
- `.gitignore` any local secret/credential files; keep private files **outside the docroot**.
- Provisioning a secret in a host store is an **ops action**, often not something to automate blindly; surface the command, let a human run it where credentials belong.

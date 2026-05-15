# AGENTS.md

## What This Repo Is

A WordPress 7.0 plugin that registers [Dario](https://github.com/askalf/dario) as an AI provider via the Connectors API and AI Client. Dario is a local LLM router that proxies requests to Claude, GPT, and any OpenAI-compatible backend.

## Architecture

```
procyon-dario-provider.php              Entry point: autoloader, autoupdates, provider registration on init
src/
  autoload.php                     PSR-4 autoloader for Dario\ namespace
  Provider/DarioProvider.php       Extends AbstractApiProvider (from wordpress/php-ai-client)
  Metadata/DarioModelMetadataDirectory.php  Curated model list (Dario has no /v1/models endpoint)
  Models/DarioTextGenerationModel.php       OpenAI-compatible /v1/chat/completions implementation
assets/images/dario.svg            Provider icon
vendor/                            Composer deps (plugin-update-checker)
```

## Key Conventions

- **Indent style**: tabs (WordPress coding standards, enforced in `.editorconfig`)
- **PHP**: 7.4+ compatible, strict types declared
- **Namespace**: `Dario\` (PSR-4, loaded via custom autoloader — not Composer's)
- **WordPress AI Client interfaces**: `AbstractApiProvider`, `AbstractApiBasedModel`, `ModelMetadataDirectoryInterface`
- **No frontend build step**: PHP plugin with a Node sidecar script for Dario; no JS/CSS compilation

## Developer Commands

### One-time setup (any machine)
```bash
composer install
npm ci
```

### Boot the local dev WordPress (single command)
```bash
lando start          # Boots Lando + auto-installs WP, theme, and plugin
```
On first run this downloads WP 7.0 RC4 (without bundled themes/plugins via `wp core download --skip-content`), installs core, installs the `twentytwentyfive` theme, and activates `procyon-dario-provider`. Idempotent on subsequent starts and on `lando rebuild -y`. The site is at http://wp-dario-test.lndo.site/ (admin/admin).

### Tests
```bash
npm test             # PHP unit-style tests (host or container)
lando ssh -s appserver -c 'cd /app && npm test'   # same tests, in the container
```

### Deploy plugin code changes into the running WP
```bash
lando deploy-plugin
```

### Plugin Check (PCP) — wp.org submission readiness
```bash
lando wp plugin install plugin-check --activate
lando wp plugin check procyon-dario-provider
```
CI runs this on every PR with the deferred-exception list applied. See [docs/plugin-check.md](docs/plugin-check.md) for the deferred items and which Jira tickets track them.

## Development Rules

### Red-Green-TDD (MANDATORY)

All code changes MUST follow strict Red-Green TDD:

1. **RED**: Write a failing test first. Run it. See it fail.
2. **GREEN**: Write the minimum code to make the test pass.
3. **REFACTOR**: Clean up while keeping tests green.

No implementation without a test. No refactoring without green tests.

### Git & Commit Rules

- **NEVER commit directly to `main`.** Always create a feature branch and merge via PR.
- **NEVER use `git push` without asking first.**
- **NEVER add co-author credits or AI attribution to commit messages.**
- **Conventional commits** enforced via commitlint + husky:
  - `feat:`, `fix:`, `chore:`, `docs:`, `refactor:`, `test:`, `ci:` — standard prefixes
  - Commit messages are linted on `commit-msg` hook
  - PHP files are linted on `pre-commit` hook via lint-staged

### GitHub Actions Rules

- **Pin `procyon-creative/jira-action-man` to a specific stable tag** (currently `v1.0.0`; no moving `v1` major tag is published). Re-check on each `/jira-setup` run.
- **`.github/workflows/ci.yml`** runs on every PR + push to `main`. It runs unit tests + lint, then boots Lando via `lando/setup-lando@v3` and verifies the dry plugin list + active theme + plugin activation. Same `npm test` runs locally and in CI.
- **`.github/workflows/jira.yml`** syncs ticket metadata to PRs and transitions tickets to `Done` on merge. See [docs/jira.md](docs/jira.md) for required secrets and board-column notes.
- **`.github/workflows/main.yml`** builds the release zip and attaches it to the GitHub release on tag push (`v*`).

## Version Release Process

Three places must have matching version bumps:
1. `readme.txt` — stable tag
2. `plugin.json` — version field
3. `procyon-dario-provider.php` — header comment version

Then tag + push: `git tag v0.1.x -m "message" && git push origin v0.1.x`

The GitHub Actions workflow builds the zip and attaches it to the release automatically. Releases live at https://github.com/procyon-creative/wp-dario-provider/releases.

## Issue Tracking

- Jira board: [WPD project](https://procyoncreative.atlassian.net/jira/software/c/projects/WPD/boards/201)
- See [docs/jira.md](docs/jira.md) for ticket conventions, required secrets, and the workflow that syncs PRs ↔ tickets.

## Admin UX

The Settings → Dario AI Connector page renders sections in this order: **Status → Claude Authentication → Sidecar Settings → OpenAI Backend**. Claude auth is at position 2 because nothing else works without it.

When Claude is not authenticated and the user can `manage_options`, a site-wide admin notice fires on every admin page (suppressed on the settings page itself). The notice is `notice-error` for missing credentials, `notice-warning` for present-but-invalid credentials. Status is cached in the `procyon_dario_auth_status_cache` transient with a 60s TTL; the cache is busted when OAuth completes or credentials.json is imported.

In the Status table, the Claude auth row gets red text + warning dashicon when severity is `error`, orange + dashicon when `warning`. Severity is computed by `DarioSettingsPage::claudeStatusSeverity()` from the Dario `getStatus()` enum (`healthy`/`expiring`/`broken`/`none`).

Secret fields render as empty `password` inputs with `placeholder="*****"` whenever a value exists (stored or supplied via `DARIO_*` constant/env override). Stored bytes are never echoed back. Override-controlled fields render with the `disabled` attribute and a description naming the constant.

## Things an Agent Might Get Wrong

- **This is not a block/frontend plugin.** It has a Node sidecar runtime for Dario, but no `wp-scripts build` and no blocks.
- **The AI Client is bundled in WordPress 7.0+.** Do not install `wordpress/php-ai-client` as a Composer dependency — it will conflict with Core's bundled version.
- **Dario base URL is configurable.** Check `DARIO_BASE_URL` constant or env var before assuming `localhost:3456`.
- **Model list is hardcoded.** Dario doesn't expose a `/v1/models` endpoint, so models are curated in `DarioModelMetadataDirectory::DEFAULT_MODELS`.
- **Custom autoloader, not Composer's.** The `src/autoload.php` handles PSR-4 for the `Dario\` namespace. Composer's autoloader only handles `vendor/` dependencies.

# AGENTS.md

## What This Repo Is

A WordPress 7.0 plugin that registers [Dario](https://github.com/askalf/dario) as an AI provider via the Connectors API and AI Client. Dario is a local LLM router that proxies requests to Claude, GPT, and any OpenAI-compatible backend.

## Architecture

```
wp-dario-provider.php              Entry point: autoloader, autoupdates, provider registration on init
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
- **No build step**: pure PHP plugin, no JS/CSS compilation

## Developer Commands

```bash
composer install                   # Install PHP dependencies
composer lint                      # Run phpcs (if configured)
npm test                          # Run tests (if configured)
```

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

- **ALWAYS use `procyon-creative/jira-action-man@v1`** (latest tagged major version) for any workflow that integrates with Jira on PRs.
- CI workflows should lint and test on PRs/pushes to `main`.
- Release workflow builds zip and attaches to GitHub release on tag push.

## Version Release Process

Three places must have matching version bumps:
1. `readme.txt` — stable tag
2. `plugin.json` — version field
3. `wp-dario-provider.php` — header comment version

Then tag + push: `git tag v0.1.x -m "message" && git push origin v0.1.x`

The GitHub Actions workflow builds the zip and attaches it to the release automatically.

## Things an Agent Might Get Wrong

- **This is a pure PHP plugin.** No node build step, no `wp-scripts build`, no blocks.
- **The AI Client is bundled in WordPress 7.0+.** Do not install `wordpress/php-ai-client` as a Composer dependency — it will conflict with Core's bundled version.
- **Dario base URL is configurable.** Check `DARIO_BASE_URL` constant or env var before assuming `localhost:3456`.
- **Model list is hardcoded.** Dario doesn't expose a `/v1/models` endpoint, so models are curated in `DarioModelMetadataDirectory::DEFAULT_MODELS`.
- **Custom autoloader, not Composer's.** The `src/autoload.php` handles PSR-4 for the `Dario\` namespace. Composer's autoloader only handles `vendor/` dependencies.

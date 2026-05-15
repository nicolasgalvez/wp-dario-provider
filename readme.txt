=== Dario AI Connector ===
Contributors:      nicolasgalvez
Tags:              ai, llm, connector, openai, claude
Tested up to:      7.0
Stable tag:        0.2.3
License:           GPL-2.0-or-later
License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html

Connects WordPress to AI models via the Dario local LLM router using the WordPress 7.0 Connectors API.

== Description ==

This plugin registers [Dario](https://github.com/askalf/dario) as an AI provider in WordPress 7.0. Dario is a local LLM router that supports Claude, GPT, and any OpenAI-compatible backend through a single endpoint.

**Features:**

* Registers as a WordPress 7.0 AI provider via the Connectors API
* Appears in Settings > Connectors with API key management
* Supports text generation through any backend Dario routes to
* Configurable base URL (defaults to `http://localhost:3456/v1`)
* Auto-updates from GitHub releases
* Optionally starts a bundled Dario Node sidecar on plugin activation

**Configuration:**

1. Ensure Node.js 18+ is available if the plugin should manage Dario, or install and run [Dario](https://github.com/askalf/dario) externally
2. Set your API key in Settings > Connectors
3. Optionally define `DARIO_BASE_URL` in wp-config.php if Dario runs on a non-default host/port
4. Define `DARIO_MANAGE_SIDECAR` as `false` to disable sidecar startup
5. Authenticate Dario as the same OS user that runs WordPress/PHP; sidecar failures are logged to `wp-content/dario-provider/dario-sidecar.log`

**Requires WordPress 7.0+** with the Connectors API and AI Client.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/procyon-dario-provider`, or install through the WordPress plugins screen.
1. Activate the plugin.
1. Configure your Dario API key in Settings > Connectors.

== Upgrading from 0.1.x ==

0.2.0 renames the plugin slug, namespace, and option prefixes from `wp-dario-provider` / `Dario\` / `dario_*` to `procyon-dario-provider` / `Procyon\Dario\` / `procyon_dario_*`. WordPress sees this as a different plugin because the directory name changed.

To upgrade:
1. Install or auto-update to 0.2.0 (the new plugin appears alongside the old).
2. Deactivate `wp-dario-provider` (the old version).
3. Activate `procyon-dario-provider` (the new version). The activation hook copies your existing settings, sidecar PID, and connector API key to the new option names.
4. Optionally delete the old `wp-dario-provider/` plugin directory.

Your Dario credentials at `~/.dario/credentials.json` and OpenAI backend files at `~/.dario/backends/*.json` are unaffected.

== Changelog ==

= 0.2.3 =
* HTTP safe-request filters now whitelist the host and port from `DARIO_BASE_URL` (when overridden), not just the sidecar settings. Without this, pointing the AI Client at a remote Dario instance via `DARIO_BASE_URL` would surface "A valid URL was not provided" because `wp_safe_remote_request` blocked the request (WPD-27).

= 0.2.2 =
* Admin notice now also fires when the Dario sidecar is down (not just when Claude OAuth is missing). Consumers were failing silently on `connection refused` (WPD-20).
* Add PHPStan static analysis at level 5 with WP stubs to CI. Surfaced and fixed: dead defensive isset() checks, an unreachable version_compare, an isset() on a non-nullable WP_Screen property, a PUC method-on-trait narrowing miss (WPD-17).
* Bump declared minimum PHP from 7.4 to 8.0 — the code already used `match`, so 7.4 installs would have crashed.
* Extract `MigrationShim` to its own class and add unit tests covering the four 0.1.x → 0.2.0 option migration scenarios (WPD-18).
* Add unit tests for the sidecar start-command construction (DARIO_API_KEY conditional, host/port flow-through) and connector-key sync logic (WPD-21).
* Add unit tests for the WPD-1 secret-preservation rule on empty form submit (WPD-22).
* Pre-commit hook now also lints staged `.mjs` and `.yml` files (WPD-19).

= 0.2.1 =
* Bump GitHub Actions to Node 24 (`actions/checkout@v6`, `setup-node@v6`) before the September 2026 Node 20 removal (WPD-9).
* Single CI job that runs the same `npm run check` developers run locally; drop bespoke verification steps (WPD-10, WPD-11).
* Branch protection on main requires the CI check to pass before merge (WPD-14).
* PCP gate tightened: rename last unprefixed global, fail on any warning, not just errors (WPD-15).
* Refresh composer.lock content-hash to silence the lock-out-of-date warning on every CI run (WPD-12).
* Update README Development + Releases sections to match the current npm scripts (WPD-13).

= 0.2.0 =
* Rename plugin slug to `procyon-dario-provider`, namespace to `Procyon\Dario\`, text domain to `procyon-dario-provider`, options to `procyon_dario_*`, and provider ID to `procyon_dario`. Migration shim copies legacy options on activation.
* Refactor file I/O to `WP_Filesystem` for Plugin Check (PCP) compliance (WPD-4).
* Add Plugin Check CI gate (WPD-1).
* Document Option A distribution decision (stay off wp.org, keep PUC) in docs/plugin-check.md (WPD-5).

= 0.1.5 =
* Add Settings > Dario AI Connector admin page with sidecar status, restart, and proxy/backend configuration.
* Persist Dario sidecar settings (host, port, proxy API key, node binary) in WordPress options with constant/env overrides.
* Write OpenAI-compatible backend credentials to ~/.dario/backends/<name>.json from the admin UI.
* Drive Dario's manual Claude OAuth flow from the admin page so admins can authenticate without SSH.

= 0.1.4 =
* Start a bundled Dario Node sidecar on plugin activation when Node.js is available.

= 0.1.3 =
* Include Composer vendor files in the GitHub release zip.

= 0.1.2 =
* Fix GitHub Actions release asset upload permissions.

= 0.1.1 =
* Fix compatibility with the WordPress 7.0 RC4 AI Client model metadata directory interface.

= 0.1.0 =
* Initial release
* Registers Dario as an AI provider via the WP 7.0 Connectors API
* Supports text generation through OpenAI-compatible chat completions endpoint
* Curated model list: Claude Sonnet 4.6, Claude Opus 4.7, GPT-4o, o3-mini, GPT-4.1, GPT-4.1 Mini, GPT-4.1 Nano

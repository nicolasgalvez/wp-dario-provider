=== Dario AI Connector ===
Contributors:      nicolasgalvez
Tags:              ai, llm, connector, openai, claude
Tested up to:      7.0
Stable tag:        0.2.0
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

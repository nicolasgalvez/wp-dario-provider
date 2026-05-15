=== Dario AI Connector ===
Contributors:      nicolasgalvez
Tags:              ai, dario, llm, connector, openai, claude
Tested up to:      7.0
Stable tag:        0.1.1
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

**Configuration:**

1. Install and run [Dario](https://github.com/askalf/dario) locally
2. Set your API key in Settings > Connectors
3. Optionally define `DARIO_BASE_URL` in wp-config.php if Dario runs on a non-default host/port

**Requires WordPress 7.0+** with the Connectors API and AI Client.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/wp-dario-provider`, or install through the WordPress plugins screen.
1. Activate the plugin.
1. Configure your Dario API key in Settings > Connectors.

== Changelog ==

= 0.1.1 =
* Fix compatibility with the WordPress 7.0 RC4 AI Client model metadata directory interface.

= 0.1.0 =
* Initial release
* Registers Dario as an AI provider via the WP 7.0 Connectors API
* Supports text generation through OpenAI-compatible chat completions endpoint
* Curated model list: Claude Sonnet 4.6, Claude Opus 4.7, GPT-4o, o3-mini, GPT-4.1, GPT-4.1 Mini, GPT-4.1 Nano

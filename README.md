# Dario AI Connector

Connects WordPress to AI models via the [Dario](https://github.com/askalf/dario) local LLM router using the WordPress 7.0 Connectors API.

## Requirements

- WordPress 7.0+
- PHP 7.4+
- [Dario](https://github.com/askalf/dario) running locally or on an accessible host

## Installation

1. Download the latest release zip from [GitHub Releases](https://github.com/nicolasgalvez/wp-dario-provider/releases)
2. Upload to `/wp-content/plugins/wp-dario-provider/` or install via the WordPress plugins screen
3. Activate the plugin
4. Configure your Dario API key in **Settings > Connectors**

## Configuration

### Base URL

Defaults to `http://localhost:3456/v1`. Override with:

```php
// wp-config.php
define( 'DARIO_BASE_URL', 'http://192.168.1.100:3456/v1' );
```

Or via environment variable:

```
DARIO_BASE_URL=http://192.168.1.100:3456/v1
```

### API Key

Set via **Settings > Connectors** in wp-admin, or:

```php
// wp-config.php
define( 'DARIO_API_KEY', 'your-key-here' );
```

Or via environment variable:

```
DARIO_API_KEY=your-key-here
```

## Supported Models

The plugin provides a curated list of models that Dario commonly routes:

| Model | Backend |
|-------|---------|
| Claude Sonnet 4.6 | Anthropic |
| Claude Opus 4.7 | Anthropic |
| GPT-4o | OpenAI |
| o3-mini | OpenAI |
| GPT-4.1 | OpenAI |
| GPT-4.1 Mini | OpenAI |
| GPT-4.1 Nano | OpenAI |

You can use any model name Dario supports by specifying it via `using_model_preference()` or custom options.

## Usage

After configuration, any plugin using the WordPress AI Client can route through Dario:

```php
$text = wp_ai_client_prompt( 'Write a haiku about WordPress.' )
    ->using_model_preference( 'claude-sonnet-4-6' )
    ->generate_text();
```

## Development

### Setup

```bash
composer install
```

### Testing

```bash
npm test
```

### Linting

PHP linting runs via husky + lint-staged on commit.

### Conventional Commits

This project uses [conventional commits](https://www.conventionalcommits.org/) enforced by commitlint:

```
feat: add new model to directory
fix: correct response parsing for streaming
chore: bump dependencies
```

## Releases

1. Bump version in `readme.txt`, `plugin.json`, and `wp-dario-provider.php`
2. Tag and push: `git tag v0.1.x -m "message" && git push origin v0.1.x`
3. GitHub Actions builds the zip and attaches it to the release

## License

GPL-2.0-or-later

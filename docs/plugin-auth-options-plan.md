# Plugin Authentication Options Plan

## Goal

Make the Dario provider usable by normal WordPress admins without SSH access by adding a WordPress admin options page for:

- Dario sidecar management settings.
- OpenAI-compatible backend credentials.
- Proxy/API key settings.
- Sidecar status and logs.
- Clear guidance for Claude OAuth authentication, which is not just an API key.

## Current State

- The plugin can register as a WordPress AI Client provider.
- The plugin can auto-update from GitHub releases.
- Version `0.1.4` adds a Node sidecar that starts `@askalf/dario` on plugin activation when Node.js is available.
- The sidecar starts mechanically, but Dario exits if it is not authenticated:

  ```text
  [dario] Not authenticated. Run `dario login` first.
  ```

- Lando now has Node and a `lando dario` helper, but production users usually will not have SSH access or know how to run CLI login commands.

## Important Authentication Distinction

Dario has more than one credential concern:

1. **Proxy access key**
   - The key WordPress sends to the local Dario proxy.
   - This can be configured by the plugin and passed to the sidecar as `DARIO_API_KEY`.

2. **OpenAI-compatible backend key**
   - A server-side upstream key for OpenAI, OpenRouter, Groq, LiteLLM, etc.
   - Dario stores these as JSON files under `~/.dario/backends/*.json`.
   - This can be implemented from a WordPress options page by writing backend config files for the PHP runtime user.

3. **Claude subscription/OAuth credentials**
   - Dario’s `dario login` flow is OAuth/browser based and writes credentials under `~/.dario`.
   - This is not equivalent to pasting an API key.
   - A full admin-browser OAuth flow may be possible, but it is a larger security-sensitive integration and should not be faked as a simple API key field.

## Proposed UX

Add a WordPress admin page:

```text
Settings > Dario AI Connector
```

Sections:

### Status

Show:

- Plugin version.
- Node availability and version.
- Sidecar enabled/disabled.
- Sidecar running/not running.
- Proxy URL, e.g. `http://127.0.0.1:3456/v1`.
- Last sidecar start error.
- Link/button to restart the sidecar.

### Sidecar Settings

Fields:

- `Manage Dario sidecar` checkbox.
- `Node binary` text input, default `node`.
- `Proxy host` text input, default `127.0.0.1`.
- `Proxy port` number input, default `3456`.
- `Proxy API key` password input.

Behavior:

- Save settings to WordPress options.
- Pass sidecar settings as environment variables:

  ```text
  DARIO_PROXY_HOST
  DARIO_PROXY_PORT
  DARIO_API_KEY
  DARIO_LOG_FILE
  ```

- Update provider base URL to follow sidecar settings unless `DARIO_BASE_URL` constant/env overrides it.

### OpenAI-Compatible Backend

Fields:

- Enable backend checkbox.
- Backend name, default `wordpress`.
- Base URL, default `https://api.openai.com/v1`.
- API key password input.
- Optional default model.

Behavior:

- On save, write Dario backend JSON to the runtime user’s Dario config dir:

  ```text
  ~/.dario/backends/<name>.json
  ```

- File shape should match Dario’s `saveBackend()` output:

  ```json
  {
    "name": "wordpress",
    "provider": "openai",
    "apiKey": "sk-...",
    "baseUrl": "https://api.openai.com/v1"
  }
  ```

- Use mode `0600` where possible.
- Never display stored API keys back in full.
- Add a “Test backend” button that performs a minimal generation request through the sidecar.

### Claude Authentication

Show clear text:

- Claude subscription auth requires Dario OAuth login.
- API-key entry here does not replace Claude OAuth.

Phase 1 behavior:

- Display status only: authenticated/not authenticated if detectable.
- If not authenticated, show instructions for hosts with SSH.
- For Lando, show:

  ```bash
  lando dario login
  lando wp plugin deactivate wp-dario-provider
  lando wp plugin activate wp-dario-provider
  ```

Phase 2 possible behavior:

- Build an admin-triggered OAuth flow that reuses Dario internals or shells out to Dario in manual/headless mode.
- This needs a separate design because it involves browser redirects, token storage, nonce validation, and admin capability checks.

## Implementation Plan

### 1. Add Settings Storage

Create:

```text
src/Admin/DarioSettings.php
```

Responsibilities:

- Define option name, e.g. `dario_provider_settings`.
- Provide defaults.
- Sanitize settings.
- Expose typed getters for sidecar/provider code.

Suggested settings:

```php
[
  'manage_sidecar' => true,
  'node_binary' => 'node',
  'proxy_host' => '127.0.0.1',
  'proxy_port' => 3456,
  'proxy_api_key' => '',
  'openai_backend_enabled' => false,
  'openai_backend_name' => 'wordpress',
  'openai_base_url' => 'https://api.openai.com/v1',
  'openai_api_key' => '',
  'openai_default_model' => '',
]
```

Constants/env vars should continue to override options for deploy automation.

### 2. Add Admin Page

Create:

```text
src/Admin/DarioSettingsPage.php
```

Hook:

```php
add_action( 'admin_menu', ... );
add_action( 'admin_init', ... );
```

Capability:

```php
manage_options
```

Security:

- Use WordPress Settings API or explicit nonce checks.
- Escape all output.
- Never render saved secret values in full.

### 3. Wire Settings Into Sidecar

Update:

```text
src/Sidecar/DarioSidecar.php
```

Changes:

- Read sidecar settings from `DarioSettings`.
- Pass `DARIO_API_KEY` to sidecar environment if configured.
- Add `restart()` method for admin page action.
- Add `status()` method returning:

  ```php
  [
    'node_available' => bool,
    'node_version' => ?string,
    'sidecar_running' => bool,
    'pid' => ?int,
    'proxy_url' => string,
    'last_log_lines' => array,
  ]
  ```

### 4. Wire Settings Into Provider Base URL

Update:

```text
src/Provider/DarioProvider.php
```

Resolution order:

1. `DARIO_BASE_URL` constant.
2. `DARIO_BASE_URL` environment variable.
3. Settings-derived sidecar URL.
4. Default `http://127.0.0.1:3456/v1`.

### 5. Write Dario Backend Config

Create:

```text
src/Sidecar/DarioBackendConfig.php
```

Responsibilities:

- Resolve Dario config directory for the PHP runtime user.
- Ensure `~/.dario/backends`.
- Write/remove OpenAI-compatible backend JSON.
- Validate backend name with Dario-compatible regex:

  ```text
  /^[A-Za-z0-9][A-Za-z0-9_\-.]{0,63}$/
  ```

Security:

- Write files with `0600` where possible.
- Do not log API keys.
- Store WordPress option secret using normal options for v1; note future enhancement for encryption if available.

### 6. Add Admin Actions

Actions:

- Save settings.
- Restart sidecar.
- Test proxy.
- Test OpenAI-compatible backend.
- Clear sidecar log.

All actions require:

- `manage_options`.
- Nonce.

### 7. Tests

Add focused PHP tests:

```text
tests/test-dario-settings.php
tests/test-dario-backend-config.php
```

Coverage:

- Defaults.
- Sanitization.
- Constant/env override behavior.
- Backend name validation.
- Backend JSON generation does not expose secrets.
- Sidecar command includes configured environment.

Keep tests self-contained and avoid requiring a live Dario auth session.

### 8. Lando Verification

After implementation:

```bash
npm test
php -l wp-dario-provider.php
find src tests -name '*.php' -print0 | xargs -0 -n1 php -l
node --check sidecar/dario-sidecar.mjs
lando rebuild -y
lando deploy-plugin
lando wp plugin deactivate wp-dario-provider
lando wp plugin activate wp-dario-provider
```

Then verify:

```bash
lando wp option get dario_provider_settings --format=json
lando ssh -s appserver -c 'curl -sS http://127.0.0.1:3456/v1/models'
```

If an OpenAI-compatible backend API key is configured, test generation through WordPress AI Client.

## Release Notes Needed

When complete, bump:

- `wp-dario-provider.php`
- `plugin.json`
- `readme.txt`
- `package.json`
- `package-lock.json`

Suggested version: `0.1.5`.

Suggested changelog:

```text
= 0.1.5 =
* Add admin settings for Dario sidecar and OpenAI-compatible backend authentication.
```

## Open Questions

- Should the plugin store upstream API keys in plain WordPress options, or require constants/env vars for production?
- Should Claude OAuth be phase 2, or is OpenAI-compatible backend setup enough for the first normal-user authentication flow?
- Should the admin page expose a Dario CLI-like manual OAuth paste flow, or only status/instructions?
- Should sidecar startup happen only on activation, or also lazily when settings are saved/restarted?

# Plugin Check (PCP)

[Plugin Check](https://wordpress.org/plugins/plugin-check/) is the official WordPress.org tool that runs the same lints the wordpress.org Plugins Team uses on submissions.

## Run locally

```bash
lando wp plugin install plugin-check --activate
lando wp plugin check wp-dario-provider
```

## CI

`.github/workflows/ci.yml` runs PCP on every PR with the deferred-exception list applied (see below). The job fails if any error appears outside that list.

To reproduce the exact CI invocation locally:

```bash
lando wp plugin check wp-dario-provider \
  --exclude-checks=plugin_updater \
  --ignore-codes=WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.rename_rename,WordPress.WP.AlternativeFunctions.file_system_operations_is_writable,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen \
  --ignore-warnings \
  --format=json
```

## Deferred exceptions (tracked in Jira)

The plugin currently fails PCP on two categories that are deferred to follow-up tickets. Each is listed here with its rationale and the ticket where the work happens.

### File operations should use `WP_Filesystem`

PCP rule family: `WordPress.WP.AlternativeFunctions.file_system_operations_*`, `unlink_unlink`, `rename_rename`.

| Where | What it does | Why direct PHP |
|---|---|---|
| `src/Sidecar/DarioBackendConfig.php` | Writes `~/.dario/backends/<name>.json` (mode 0600) | Atomic temp+rename pattern matches Dario's own `saveBackend()` for byte compatibility |
| `src/Sidecar/DarioClaudeAuth.php` | Writes `credentials.json`, manages FIFOs + log files for the manual OAuth flow | Named pipes have no `WP_Filesystem` equivalent; OAuth log + fifo are local-only |
| `src/Sidecar/DarioSidecar.php` | `fsockopen` port liveness check, `is_writable` for log dir | No `WP_Filesystem` equivalent for socket connect |

**Tracked in:** [WPD-4 — Refactor filesystem ops to WP_Filesystem (PCP compliance)](https://procyoncreative.atlassian.net/browse/WPD-4).

After WPD-4 lands, only the `fsockopen` (port liveness) and the FIFO ops will remain as permanent exceptions, with phpcs:ignore comments and a note in this file.

### Plugin updater detected

PCP rule: `plugin_updater_detected`.

The plugin uses [`yahnis-elsts/plugin-update-checker`](https://github.com/YahnisElsts/plugin-update-checker) plus a custom `Update URI` header in the plugin file to deliver updates from GitHub releases. Both are explicitly disallowed in plugins hosted on wordpress.org (the wp.org repo IS the update mechanism for hosted plugins).

We currently distribute via GitHub releases, not wordpress.org, so this is intentional. The decision of whether to ever submit to wordpress.org (and the migration plan) is captured in:

**Tracked in:** [WPD-5 — Plugin distribution + updater strategy (wp.org vs GitHub releases)](https://procyoncreative.atlassian.net/browse/WPD-5).

If WPD-5 lands with a wp.org submission decision, PUC and the `Update URI` header are removed and this exception goes away.

### Plugin slug contains "wp"

PCP warning: `trademarked_term`. The slug `wp-dario-provider` contains the restricted prefix `wp` which wp.org will not accept.

Tied to the same wp.org distribution decision as the updater above. **Tracked in:** [WPD-5](https://procyoncreative.atlassian.net/browse/WPD-5).

## Permanent exceptions (after WPD-4 lands)

These will stay even after the WP_Filesystem refactor:

- `fsockopen` + paired `fclose` in `DarioSidecar::isPortOpen()` — port liveness check, no equivalent in `WP_Filesystem`.
- `posix_mkfifo` and read-side fifo `fopen`/`fclose` in `DarioClaudeAuth::startManualLogin()` — required for the cross-request FIFO orchestration of `dario login --manual`.

When WPD-4 lands, mark these with `// phpcs:ignore WordPress.WP.AlternativeFunctions.* -- ...reason...` and remove the corresponding entries from the `--ignore-codes` list above.

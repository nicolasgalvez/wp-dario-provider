# Plugin Check (PCP)

[Plugin Check](https://wordpress.org/plugins/plugin-check/) is the official WordPress.org tool that runs the same lints the wordpress.org Plugins Team uses on submissions.

## Run locally

```bash
lando start          # if not already running; the run-block installs plugin-check
npm run check:pcp    # same script CI runs
```

`npm run check:pcp` invokes `scripts/check-pcp.sh`, which calls `lando wp plugin check` with the documented exclusions and counts ERROR rows. Exits non-zero if any error is reported outside the deferred list.

## CI

`.github/workflows/ci.yml` `pcp` job runs `npm run check:pcp` after `lando start`. There is no CI-specific PCP logic — it's the same script and exit-code semantics as local.

## Deferred exceptions (tracked in Jira)

Only one category of PCP error is currently deferred.

### Plugin updater detected

PCP rule: `plugin_updater_detected` (3 errors).

The plugin uses [`yahnis-elsts/plugin-update-checker`](https://github.com/YahnisElsts/plugin-update-checker) plus a custom `Update URI` header in the plugin file to deliver updates from GitHub releases. Both are explicitly disallowed in plugins hosted on wordpress.org (the wp.org repo IS the update mechanism for hosted plugins).

We currently distribute via GitHub releases, not wordpress.org, so this is intentional. The decision of whether to ever submit to wordpress.org (and the migration plan) is captured in:

**Tracked in:** [WPD-5 — Plugin distribution + updater strategy (wp.org vs GitHub releases)](https://procyoncreative.atlassian.net/browse/WPD-5).

If WPD-5 lands with a wp.org submission decision, PUC and the `Update URI` header are removed and this exception goes away.

### Plugin slug contains "wp"

PCP warning: `trademarked_term`. The slug `wp-dario-provider` contains the restricted prefix `wp` which wp.org will not accept. (Resolved in 0.2.0 — slug is now `procyon-dario-provider`. See WPD-7.)

Tied to the same wp.org distribution decision as the updater above. **Tracked in:** [WPD-5](https://procyoncreative.atlassian.net/browse/WPD-5).

## Permanent exceptions (already applied as `phpcs:ignore`)

These three call sites have no `WP_Filesystem` equivalent and are marked with inline `// phpcs:ignore` comments. They are accepted permanent exceptions and do **not** appear in PCP output.

| Where | What it does | Why no WP_Filesystem |
|---|---|---|
| `src/Sidecar/DarioSidecar.php` `isPortOpen()` | TCP socket connect to check if the sidecar's port is listening | Not file I/O. `WP_Filesystem` is for files, not sockets |
| `src/Sidecar/DarioClaudeAuth.php` `startManualLogin()` | `posix_mkfifo()` to create the named pipe | FIFOs are not files; `WP_Filesystem` has no equivalent |
| `src/Sidecar/DarioClaudeAuth.php` `submitManualCode()` | `fopen` / `fwrite` / `fclose` to push the OAuth code through the FIFO | `WP_Filesystem::put_contents` would truncate-and-write, which doesn't work on a non-seekable FIFO target |

If any new `phpcs:ignore` comments get added, document the rationale here so reviewers don't have to guess.

## Implementation notes

The `Dario\Sidecar\Concerns\WithFilesystem` trait lazily instantiates a `WP_Filesystem_Direct` (not the global `$wp_filesystem` singleton) for plugin code that needs file I/O. We bypass `WP_Filesystem()` auto-detection because:

- All our writes target `~/.dario/`, owned by the WP runtime user, so the `direct` method always works.
- `WP_Filesystem()` can prompt for FTP credentials in environments where it can't write directly. We don't want a UI prompt for our internal file ops.
- PCP treats both forms as equivalent for the `WordPress.WP.AlternativeFunctions.file_system_operations_*` rules.

Tests that exercise the file-writing paths bootstrap a minimal WP environment via `tests/bootstrap.php` (defines `ABSPATH`, stubs `WP_Error`, and a handful of WP helper functions like `untrailingslashit`, `wp_delete_file`, `mbstring_binary_safe_encoding`). This lets `npm test` run without spinning up a full WP install.

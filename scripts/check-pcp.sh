#!/usr/bin/env bash
# Run WordPress Plugin Check (PCP) against this plugin and fail if any
# error is reported outside the documented deferred exceptions.
#
# Same script runs locally and in CI. Requires `lando` on PATH and a
# running Lando app (use `lando start` first).
#
# Deferred exceptions (see docs/plugin-check.md):
#   - plugin_updater_detected (3 errors): we distribute via GitHub
#     releases with PUC, intentional. Tracked in WPD-5 (closed).
#
# All other former exceptions (file_system_operations_*) are now handled
# via the WithFilesystem trait and inline phpcs:ignore on FIFO/socket ops.

set -euo pipefail

PLUGIN_SLUG="${PLUGIN_SLUG:-procyon-dario-provider}"

OUTPUT=$(lando wp plugin check "$PLUGIN_SLUG" \
  --exclude-checks=plugin_updater 2>&1)

ERROR_COUNT=$(echo "$OUTPUT" | grep -cE '^[0-9]+\s+[0-9]+\s+ERROR' || true)
WARNING_COUNT=$(echo "$OUTPUT" | grep -cE '^[0-9]+\s+[0-9]+\s+WARNING' || true)

# Trademarked-term warning: the slug "wp-dario-provider" used to trip this;
# resolved in 0.2.0 (WPD-7). Allow zero of these. Counted separately so a
# new occurrence still fails the build.
TRADEMARK_COUNT=$(echo "$OUTPUT" | grep -c 'trademarked_term' || true)

FAIL=0

if [ "$ERROR_COUNT" -gt 0 ]; then
  echo "::error::Plugin Check found $ERROR_COUNT errors outside the documented exception list" >&2
  FAIL=1
fi

if [ "$WARNING_COUNT" -gt 0 ]; then
  echo "::error::Plugin Check found $WARNING_COUNT warning(s). Triage them in docs/plugin-check.md if intentional, or fix at the source." >&2
  FAIL=1
fi

if [ "$FAIL" -eq 1 ]; then
  echo "$OUTPUT"
  exit 1
fi

echo "✓ Plugin Check clean (excluding plugin_updater, tracked in WPD-5)"

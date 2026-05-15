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
  --exclude-checks=plugin_updater \
  --ignore-warnings 2>&1)

ERROR_COUNT=$(echo "$OUTPUT" | grep -cE '^[0-9]+\s+[0-9]+\s+ERROR' || true)

if [ "$ERROR_COUNT" -gt 0 ]; then
  echo "::error::Plugin Check found $ERROR_COUNT errors outside the documented exception list" >&2
  echo "$OUTPUT"
  echo ""
  echo "If these are intentional, add the codes to docs/plugin-check.md and to the --exclude-checks/--ignore-codes list in scripts/check-pcp.sh."
  exit 1
fi

echo "✓ Plugin Check clean (excluding plugin_updater, tracked in WPD-5)"

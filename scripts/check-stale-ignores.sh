#!/bin/bash
# check-stale-ignores.sh — detect phpstan.neon ignoreErrors entries that no
# longer match anything.
#
# The identifier-scoped policy ignores in phpstan.neon carry
# `reportUnmatched: false` so multi-site patterns don't fail when one site is
# fixed. The cost is that an entry whose *last* matching site is fixed goes
# silently stale and outlives its rationale. This check flips every
# `reportUnmatched: false` to `true` in a scratch config and runs the
# analysis: any "Ignored error pattern ... was not matched" output names a
# stale entry that should be deleted (or its rationale re-examined).
#
# The baseline (phpstan-baseline.neon) needs no equivalent: its entries carry
# exact match counts, so staleness already fails the normal `composer phpstan`
# run.
#
# Exit codes: 0 = all ignores still match; 1 = stale ignores found (listed).

set -u
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PLUGIN_DIR"

SCRATCH=".stale-ignore-check.neon"
trap 'rm -f "$SCRATCH"' EXIT

# Scratch config must live in the plugin root so relative includes resolve.
sed 's/reportUnmatched: false/reportUnmatched: true/' phpstan.neon > "$SCRATCH"

OUTPUT=$(vendor/bin/phpstan analyse -c "$SCRATCH" --memory-limit=2G --no-progress --error-format=raw 2>&1)
STATUS=$?

STALE=$(printf '%s\n' "$OUTPUT" | grep -i "was not matched in reported errors" || true)

if [ -n "$STALE" ]; then
    echo "STALE phpstan.neon ignores (no longer match anything):"
    printf '%s\n' "$STALE"
    exit 1
fi

if [ "$STATUS" -ne 0 ]; then
    # Analysis failed for some other reason — surface it rather than pass silently (INV-M1).
    echo "phpstan run failed for a reason other than stale ignores:"
    printf '%s\n' "$OUTPUT" | tail -20
    exit 1
fi

echo "All phpstan.neon ignore entries still match at least one site."
exit 0

#!/bin/bash
# check-coverage.sh — line-coverage floor gate.
#
# Runs the unit suite with PCOV coverage and fails when clover line coverage
# drops below the floor. The floor is deliberately below the observed value
# at gate creation (81.35% on 2026-06-12, 6,346 tests) — it exists to catch
# regression drift, not to force coverage growth. Raise it as coverage rises;
# never lower it to absorb a drop (same shrink-only policy as the PHPStan
# baseline, ADR-018).
#
# Exit codes: 0 = at/above floor; 1 = below floor or run failure.

set -u
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PLUGIN_DIR"

FLOOR_PCT=78

CLOVER="build/logs/clover.xml"

if ! vendor/bin/phpunit --testsuite=Unit --coverage-clover "$CLOVER" >/tmp/nte-coverage-run.log 2>&1; then
    echo "Unit suite failed under coverage — see /tmp/nte-coverage-run.log"
    exit 1
fi

if [ ! -f "$CLOVER" ]; then
    echo "Clover report missing at $CLOVER — coverage did not run (PCOV absent?)"
    exit 1
fi

read -r COVERED TOTAL < <(php -r '
    $xml = simplexml_load_file( $argv[1] );
    $m = $xml->project->metrics;
    echo (int) $m["coveredstatements"], " ", (int) $m["statements"];
' "$CLOVER")

if [ -z "$TOTAL" ] || [ "$TOTAL" -eq 0 ]; then
    echo "Clover report contains no statements — refusing to pass an empty measurement (INV-M1)."
    exit 1
fi

PCT=$(php -r 'printf( "%.2f", $argv[1] / $argv[2] * 100 );' "$COVERED" "$TOTAL")
MEETS=$(php -r 'echo ( $argv[1] / $argv[2] * 100 ) >= (float) $argv[3] ? "yes" : "no";' "$COVERED" "$TOTAL" "$FLOOR_PCT")

if [ "$MEETS" = "yes" ]; then
    echo "Line coverage ${PCT}% (${COVERED}/${TOTAL} statements) — floor ${FLOOR_PCT}% met."
    exit 0
fi

echo "Line coverage ${PCT}% (${COVERED}/${TOTAL} statements) is BELOW the ${FLOOR_PCT}% floor."
exit 1

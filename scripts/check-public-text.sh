#!/usr/bin/env bash
# Public-text gate: shipped and published text must assume the reader knows
# nothing about the developer's environment or clients. Fails on any reference
# to internal dev-site names, client sites, local hostnames, or the developer's
# filesystem in files that ship in the dist zip or are published (CHANGELOG,
# readme, docs). See ticket NTE-216. bash 3.2 compatible.
#
# Usage: scripts/check-public-text.sh [root-dir]   (default: repo root)
# Exit 1 on any hit, printing file:line: context.

set -uo pipefail
ROOT="${1:-$(cd "$(dirname "$0")/.." && pwd)}"
cd "$ROOT" || exit 2

# Terms that must never appear in public text (case-insensitive ERE).
DENY='\boz\b|oz\.local|bellwright|celtic ?junction|cjac|celticjunction|flywheel|local by|\.local\b|localhost|basictestbed|beoga|harp (resonance )?sessions|Local_Sites|/Users/shawnmcburnie|s2m5my6gd8'
# Legitimate phrases stripped from a hit line before re-matching (generic
# product name, placeholder domains, fictional example host, loopback).
ALLOW='Local[ -]by[ -]Flywheel|localwp\.com|imported\.local|nettertech-events-dev\.local|example\.local|127\.0\.0\.1|localhost:(\$PORT|[0-9]+)|\.env\.local'

# Public surfaces = shipped code + published docs; dev-only dirs excluded.
HITS=0
while IFS= read -r line; do
    [ -n "$line" ] || continue
    stripped=$(printf '%s' "$line" | sed -E "s/($ALLOW)//Ig")
    if printf '%s' "$stripped" | grep -qiE "$DENY"; then
        HITS=$((HITS + 1))
        printf '%s\n' "$line" | cut -c1-200
    fi
done < <(grep -rnIiE "$DENY" . \
    --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=tests --exclude-dir=specs \
    --exclude-dir=.git --exclude-dir=.claude --exclude-dir=build --exclude-dir=dist --exclude-dir=.wordpress-org --exclude-dir=test-results --exclude-dir=playwright-report --exclude-dir=coverage --exclude-dir=.infection \
    --include='*.php' --include='*.js' --include='*.css' --include='*.md' --include='*.txt' --include='*.json' --include='*.html' --include='*.pot' --include='*.sh' --include='*.yaml' --include='*.yml' --include='*.xml' \
    --exclude='*.min.js' --exclude='package-lock.json' --exclude='composer.lock' --exclude='check-public-text.sh' 2>/dev/null || true)

if [ "$HITS" -gt 0 ]; then
    echo ""
    echo "Public-text gate FAILED: $HITS line(s) reference internal environments/clients."
    echo "Rewrite in the reader's frame (they installed the plugin on their own WordPress)."
    exit 1
fi
echo "Public-text gate passed."
exit 0

#!/bin/bash
# Release gates — INV-PE1, INV-IC1, INV-F1, INV-P3, INV-P4 (.coherence-invariants.md / ADR-018).
# Run via: composer release:check
# Every gate is a hard gate. A skipped or silently-passing gate is the
# anti-pattern INV-M1 exists to prevent; missing tooling FAILS the run.

set -u
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PLUGIN_DIR"

RED='\033[0;31m'; GREEN='\033[0;32m'; CYAN='\033[0;36m'; NC='\033[0m'
FAILED=0

note()  { echo -e "${CYAN}[release]${NC} $1"; }
pass()  { echo -e "${GREEN}  ✓${NC} $1"; }
fail()  { echo -e "${RED}  ✗${NC} $1"; FAILED=1; }

# ----------------------------------------------------------------------------
# Gate 1 (INV-PE1a): frontend asset budgets — JS < 100KB, CSS < 50KB per file.
# Per-page aggregates are approximated per-file: the plugin enqueues at most
# one bundle of each type per page, so the largest single file bounds the page.
# ----------------------------------------------------------------------------
note "Gate 1/7: asset budgets (JS<100KB, CSS<50KB per file)"
JS_BUDGET=102400
CSS_BUDGET=51200
BUDGET_FAIL=0
while IFS= read -r f; do
    size=$(wc -c < "$f" | tr -d ' ')
    case "$f" in
        *.js)  [ "$size" -gt "$JS_BUDGET" ]  && { fail "$(basename "$f") ${size}B exceeds JS budget ${JS_BUDGET}B"; BUDGET_FAIL=1; } ;;
        *.css) [ "$size" -gt "$CSS_BUDGET" ] && { fail "$(basename "$f") ${size}B exceeds CSS budget ${CSS_BUDGET}B"; BUDGET_FAIL=1; } ;;
    esac
done < <(find assets/dist assets/js assets/css -name "*.js" -o -name "*.css" 2>/dev/null | grep -v vendor)
[ "$BUDGET_FAIL" -eq 0 ] && pass "all first-party assets within budget"

# ----------------------------------------------------------------------------
# Gate 2 (INV-P4): translation template freshness.
# Regenerates the pot to a temp file and compares msgids against the
# committed pot (creation dates/revision headers excluded).
# ----------------------------------------------------------------------------
note "Gate 2/7: i18n pot freshness"
if ! command -v wp >/dev/null 2>&1 && [ ! -f "$HOME/bin/wp" ]; then
    fail "wp-cli not found — needed for 'wp i18n make-pot'"
else
    WP_BIN="php -d memory_limit=1G $HOME/bin/wp"
    command -v wp >/dev/null 2>&1 && WP_BIN="wp"
    TMP_POT=$(mktemp /tmp/nte-pot.XXXXXX)
    # memory_limit: Peast (JS parser) exhausts 128M on the blocks bundle.
    if php -d memory_limit=1G "$HOME/bin/wp" i18n make-pot . "$TMP_POT" --skip-audit --exclude=node_modules,vendor,tests,build,deprecated --quiet 2>/dev/null; then
        committed_ids=$(grep -c '^msgid' languages/nettertech-events.pot 2>/dev/null || echo 0)
        fresh_ids=$(grep -c '^msgid' "$TMP_POT" || echo 0)
        if ! diff <(grep '^msgid' languages/nettertech-events.pot | sort) <(grep '^msgid' "$TMP_POT" | sort) >/dev/null 2>&1; then
            fail "pot is stale: committed ${committed_ids} msgids vs regenerated ${fresh_ids} — run: wp i18n make-pot . languages/nettertech-events.pot"
        else
            pass "pot is fresh (${committed_ids} msgids)"
        fi
        rm -f "$TMP_POT"
    else
        fail "wp i18n make-pot failed"
    fi
fi

# ----------------------------------------------------------------------------
# Gate 3 (INV-P3): CycloneDX SBOM generation.
# ----------------------------------------------------------------------------
note "Gate 3/7: SBOM (CycloneDX)"
mkdir -p build
if composer CycloneDX:make-sbom --output-format=JSON --output-file=build/nettertech-events.sbom.json --omit=dev >/dev/null 2>&1; then
    pass "SBOM written to build/nettertech-events.sbom.json"
else
    fail "SBOM generation failed (composer CycloneDX:make-sbom)"
fi

# ----------------------------------------------------------------------------
# Gate 4 (INV-F1): Playwright E2E suite.
# ----------------------------------------------------------------------------
note "Gate 4/7: E2E suite"
# Pre-clean E2E artifacts from prior runs: the suite creates ~20 events per
# run and has no teardown, so leftovers paginate later runs' assertions off
# page 1 of the admin list (observed 2026-06-11). Deletes only E2E-named
# artifacts via the plugin's own repository (cascades occurrences/tickets).
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"
php "$HOME/bin/wp" eval '
$repo = NetterTechEvents\Core\ServiceRegistry::event_repository();
global $wpdb;
$t = NetterTechEvents\Database\Schema::table( "events" );
$ids = $wpdb->get_col( "SELECT id FROM {$t} WHERE title LIKE \"E2E Test Event%\" OR title LIKE \"PO Smoke Test%\" OR title LIKE \"Weekly Event 1%\" OR title LIKE \"Chunk8%\" OR title LIKE \"E2E Regulars%\" OR title LIKE \"Recurring E2E%\"" );
foreach ( $ids as $id ) { $repo->delete( (int) $id ); }
echo count( $ids ) . " prior E2E artifacts cleaned\n";
' --path="$WP_ROOT" 2>/dev/null | tail -1 || echo "  (E2E pre-clean skipped — wp eval unavailable)"
E2E_LOG=$(mktemp /tmp/nte-e2e.XXXXXX)
# Scope notes:
# - fresh-install-matrix flows ("Flow N:") assert a base-only fresh install
#   and run in the release protocol on a fresh test site, not here — oz
#   carries the full suite, so those assertions legitimately fail on it.
# - chromium, single worker: matches scripts/nte-submission-gate.sh. The
#   multi-browser matrix shares one WP database across parallel projects and
#   interferes with itself (created events paginate each other off list
#   pages); per-project DB isolation is future work.
if npx playwright test --project=chromium --workers=1 --reporter=line --grep-invert "Flow [0-9]+:" > "$E2E_LOG" 2>&1; then
    tail -3 "$E2E_LOG"
    pass "E2E suite green"
else
    tail -10 "$E2E_LOG"
    fail "E2E suite failed (full log: $E2E_LOG)"
fi

# ----------------------------------------------------------------------------
# Gate 5 (INV-IC1): axe-core accessibility (runs as a Playwright project tag).
# The a11y spec lives in tests/E2E/playwright/accessibility.spec.ts and is included in
# Gate 4's run; this gate asserts the spec file exists so the a11y coverage
# cannot silently disappear from the suite.
# ----------------------------------------------------------------------------
note "Gate 5/7: a11y spec presence"
if [ -f tests/E2E/playwright/accessibility.spec.ts ]; then
    pass "a11y spec present (runs within Gate 4)"
else
    fail "tests/E2E/playwright/accessibility.spec.ts missing"
fi

# ----------------------------------------------------------------------------
# Gate 6 (ADR-018): phpstan.neon ignore staleness.
# Identifier-scoped policy ignores carry reportUnmatched:false and can
# silently outlive their rationale; this re-runs the analysis with unmatched
# reporting forced on and fails if any entry no longer matches anything.
# ----------------------------------------------------------------------------
note "Gate 6/7: phpstan.neon ignore staleness"
if bash scripts/check-stale-ignores.sh >/tmp/nte-stale-ignores.log 2>&1; then
    pass "all phpstan.neon ignore entries still match"
else
    fail "stale phpstan.neon ignores (or analysis failure) — see /tmp/nte-stale-ignores.log"
fi

# ----------------------------------------------------------------------------
# Gate 7 (ADR-018): unit-test line-coverage floor.
# Floor lives in scripts/check-coverage.sh; raise as coverage rises, never
# lower to absorb a drop (shrink-only, like the PHPStan baseline).
# ----------------------------------------------------------------------------
note "Gate 7/7: line-coverage floor"
if COVERAGE_OUT=$(bash scripts/check-coverage.sh 2>&1); then
    pass "$COVERAGE_OUT"
else
    fail "$COVERAGE_OUT"
fi

echo ""
if [ "$FAILED" -eq 1 ]; then
    echo -e "${RED}Release gates FAILED.${NC}"
    exit 1
fi
echo -e "${GREEN}All release gates passed.${NC}"

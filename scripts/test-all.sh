#!/bin/bash
#
# Comprehensive test runner for nettertech-events plugin.
#
# Runs all quality checks: PHPCS, PHPUnit, and PHP syntax.
# Usage: ./scripts/test-all.sh [--quick]
#
# Options:
#   --quick    Skip PHPCS, run only unit tests
#   --phpcs    Run only PHPCS
#   --unit     Run only unit tests
#   --syntax   Run only PHP syntax check
#   --help     Show this help message
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Track failures
PHPCS_RESULT=0
UNIT_RESULT=0
SYNTAX_RESULT=0

# Parse arguments
RUN_PHPCS=true
RUN_UNIT=true
RUN_SYNTAX=true

show_help() {
    echo "Usage: $0 [OPTIONS]"
    echo ""
    echo "Options:"
    echo "  --quick    Skip PHPCS, run only unit tests"
    echo "  --phpcs    Run only PHPCS"
    echo "  --unit     Run only unit tests"
    echo "  --syntax   Run only PHP syntax check"
    echo "  --help     Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0              # Run all checks"
    echo "  $0 --quick      # Quick test (unit tests only)"
    echo "  $0 --phpcs      # Check coding standards only"
}

for arg in "$@"; do
    case $arg in
        --quick)
            RUN_PHPCS=false
            RUN_SYNTAX=false
            ;;
        --phpcs)
            RUN_UNIT=false
            RUN_SYNTAX=false
            ;;
        --unit)
            RUN_PHPCS=false
            RUN_SYNTAX=false
            ;;
        --syntax)
            RUN_PHPCS=false
            RUN_UNIT=false
            ;;
        --help)
            show_help
            exit 0
            ;;
    esac
done

cd "$PLUGIN_DIR"

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}NetterTech Events - Test Suite${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

# ============================================================================
# PHPCS
# ============================================================================
if [ "$RUN_PHPCS" = true ]; then
    echo -e "${YELLOW}📝 Running PHPCS...${NC}"
    echo ""

    if composer phpcs; then
        echo -e "${GREEN}✓ PHPCS passed${NC}"
        PHPCS_RESULT=0
    else
        echo -e "${RED}❌ PHPCS failed${NC}"
        PHPCS_RESULT=1
    fi
    echo ""
fi

# ============================================================================
# PHPUnit
# ============================================================================
if [ "$RUN_UNIT" = true ]; then
    echo -e "${YELLOW}🧪 Running PHPUnit...${NC}"
    echo ""

    if composer test; then
        echo -e "${GREEN}✓ Unit tests passed${NC}"
        UNIT_RESULT=0
    else
        echo -e "${RED}❌ Unit tests failed${NC}"
        UNIT_RESULT=1
    fi
    echo ""
fi

# ============================================================================
# PHP Syntax
# ============================================================================
if [ "$RUN_SYNTAX" = true ]; then
    echo -e "${YELLOW}🔎 Checking PHP syntax...${NC}"
    echo ""

    SYNTAX_ERRORS=0
    while IFS= read -r -d '' file; do
        if ! php -l "$file" > /dev/null 2>&1; then
            echo -e "${RED}Syntax error: $file${NC}"
            SYNTAX_ERRORS=$((SYNTAX_ERRORS + 1))
        fi
    done < <(find includes templates -name "*.php" -print0 2>/dev/null)

    if [ "$SYNTAX_ERRORS" -eq 0 ]; then
        echo -e "${GREEN}✓ All PHP files have valid syntax${NC}"
        SYNTAX_RESULT=0
    else
        echo -e "${RED}❌ Found $SYNTAX_ERRORS syntax errors${NC}"
        SYNTAX_RESULT=1
    fi
    echo ""
fi

# ============================================================================
# Summary
# ============================================================================
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}Summary${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

TOTAL_FAILURES=$((PHPCS_RESULT + UNIT_RESULT + SYNTAX_RESULT))

if [ "$RUN_PHPCS" = true ]; then
    if [ "$PHPCS_RESULT" -eq 0 ]; then
        echo -e "PHPCS:      ${GREEN}PASS${NC}"
    else
        echo -e "PHPCS:      ${RED}FAIL${NC}"
    fi
fi

if [ "$RUN_UNIT" = true ]; then
    if [ "$UNIT_RESULT" -eq 0 ]; then
        echo -e "Unit Tests: ${GREEN}PASS${NC}"
    else
        echo -e "Unit Tests: ${RED}FAIL${NC}"
    fi
fi

if [ "$RUN_SYNTAX" = true ]; then
    if [ "$SYNTAX_RESULT" -eq 0 ]; then
        echo -e "PHP Syntax: ${GREEN}PASS${NC}"
    else
        echo -e "PHP Syntax: ${RED}FAIL${NC}"
    fi
fi

echo ""

if [ "$TOTAL_FAILURES" -eq 0 ]; then
    echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${GREEN}All checks passed! ✓${NC}"
    echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    exit 0
else
    echo -e "${RED}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${RED}$TOTAL_FAILURES check(s) failed${NC}"
    echo -e "${RED}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    exit 1
fi

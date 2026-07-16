# ADR-017: Push Validation Strategy

**Date:** 2026-02-17
**Status:** Accepted
**Deciders:** Human + Engineering
**Category:** Development Infrastructure
**References:** ADR-016 (Local-Repo Strategy)

## Context

ADR-016 established that the NetterTech Events suite uses a local-repo strategy with Dropbox sync and no hosted CI/CD platform. Quality enforcement relies on local git hooks, manual validation, and AI-assisted validation.

This decision formalizes the layered validation strategy, consolidates competing hook mechanisms, and defines the `composer push` workflow for use across all plugins in the suite.

### Problem

NetterTech Events (core) has accumulated three competing hook mechanisms:

| Location | Mechanism | What It Runs | Active? |
|----------|-----------|-------------|---------|
| `.husky/_/` + `.husky/` | Husky (npm) | `npx lint-staged` (pre-commit), `composer test` (pre-push) | Yes — `core.hooksPath = .husky/_` |
| `.githooks/` | Native git hooks | PHPCS, PHPStan, PHPUnit, syntax (pre-commit only) | No — overridden |
| `hooks/` | Legacy copied hooks | Pre-commit only | No — overridden |

The result: the most comprehensive hook (`.githooks/pre-commit`) is not running, and the active hook system (Husky) introduces an npm dependency unnecessary for a PHP plugin with no build step. There is no standardized approach for the suite.

## Decision

### 1. Consolidate on `.githooks/` (Native Git Hooks)

All plugins in the suite use `.githooks/` as the canonical hook directory. No npm-based hook managers (Husky, lint-staged).

**Setup:** Each plugin configures git to use `.githooks/`:
```bash
git config core.hooksPath .githooks
```

**Automation:** `composer hook:install` sets `core.hooksPath` and verifies hook executability:
```json
{
    "hook:install": "git config core.hooksPath .githooks && echo 'Git hooks active (.githooks/)'",
    "hook:remove": "git config --unset core.hooksPath && echo 'Git hooks deactivated'"
}
```

**Cleanup (nettertech-events core):** Remove `.husky/`, `hooks/`, `.git/hooks/pre-commit` (copied artifact), and any Husky npm dependencies. Other plugins adopt `.githooks/` from the start.

### 2. Layered Validation (Speed-Based Split)

Validation is split across three layers based on execution speed and frequency:

```
┌─────────────────────────────────────────────────────┐
│  Layer 1: Pre-Commit (every commit, fast)           │
│  PHPCS · PHPStan · PHPUnit (no coverage) · Syntax   │
│  Target: < 60 seconds                               │
├─────────────────────────────────────────────────────┤
│  Layer 2: Pre-Push (every push, thorough)           │
│  Infection (mutation testing) · Coverage threshold   │
│  Target: < 5 minutes                                │
├─────────────────────────────────────────────────────┤
│  Layer 3: Manual / On-Demand                        │
│  Playwright E2E · Full coverage report · /validate   │
│  /audit · Browser verification                      │
│  Target: as needed                                  │
└─────────────────────────────────────────────────────┘
```

**Layer 1 — Pre-Commit** (``.githooks/pre-commit``):
Runs on every commit. Must be fast enough that developers don't bypass it.
- PHPCS (coding standards)
- PHPStan (static analysis, baseline-aware)
- PHPUnit (unit tests, no coverage generation)
- PHP syntax check (staged files only)
- Blocks commit on failure. Bypass: `git commit --no-verify`

**Layer 2 — Pre-Push** (`.githooks/pre-push`):
Runs on every push. Heavier checks that would slow down individual commits.
- Infection mutation testing (configured thresholds: minMsi 70, minCoveredMsi 80)
- PHPUnit with coverage threshold enforcement (72% minimum line coverage; lowered from 75% aspirational target after PCOV measurement on 2026-04-12 produced a 73.01% baseline -- see improvement backlog item B-3 for the path back to 75%+)
- Blocks push on failure. Bypass: `git push --no-verify`

**Layer 3 — Manual / On-Demand:**
Not automated in hooks. Run by developer or AI assistant as needed.
- Playwright E2E tests (requires site to be running)
- Full HTML coverage report generation
- `/validate` skill (comprehensive suite)
- `/audit` skill (periodic assessment)
- Browser verification (`/browser-verify`)

### 3. `composer push` Workflow

Each plugin defines a `push` script in `composer.json` that runs the full automated validation suite, then pushes:

```json
{
    "scripts": {
        "infection": "vendor/bin/infection --threads=4",
        "push": [
            "@phpcs",
            "@phpstan",
            "@test",
            "@infection",
            "git push"
        ]
    }
}
```

`composer push` is the **recommended workflow** for pushing code. It runs Layer 1 + Layer 2 checks explicitly, then `git push` (which triggers the pre-push hook — redundant but harmless as a safety net).

When `composer push` is not available (new/unconfigured plugin), the pre-push hook ensures minimum validation still runs.

### 4. `/push` Skill

A Claude Code skill that wraps the push workflow with detection and reporting:

```
/push → detect repo → composer push (if available) → report
       └→ fallback: git push (pre-push hook handles validation)
```

The skill:
- Detects whether the current working directory is a git repo within a plugin
- Checks for a `push` script in `composer.json`
- If present: invokes `composer push`, reports results
- If absent: warns, falls back to `git push` (pre-push hook still fires)
- Reports validation results and push status

### 5. Composer Script Cleanup

Remove scripts that reference non-existent CI infrastructure:

| Remove | Reason |
|--------|--------|
| `ci` | No CI platform (ADR-016) |
| `ci:full` | No CI platform (ADR-016) |

Rename/keep:
| Script | Purpose |
|--------|---------|
| `check` | Quick local check (PHPCS + test) |
| `quality` | Full quality suite (PHPCS + PHPStan + test) |
| `push` | **New** — full validation + push |
| `infection` | **New** — run Infection standalone |
| `hook:install` | **Updated** — sets core.hooksPath |
| `hook:remove` | **Updated** — unsets core.hooksPath |

## Rationale

### Why `.githooks/` over Husky?

1. **No npm dependency for PHP hooks.** Husky requires Node.js and npm. The plugin suite uses IIFE blocks with no build step — npm is not otherwise needed.
2. **Version-controlled hooks.** `.githooks/` is committed to the repo. `core.hooksPath` makes them active without copying to `.git/hooks/`.
3. **Transparency.** Plain shell scripts in a visible directory. No dispatcher layer, no config files, no `npx`.
4. **Suite consistency.** Every plugin uses the same mechanism. No per-plugin decisions about hook managers.

### Why a speed-based split?

The failure mode of slow pre-commit hooks is `--no-verify`. If pre-commit takes 3+ minutes (Infection alone can take that), developers bypass it. Splitting heavy checks to pre-push (less frequent) preserves the fast commit loop while still gating the push.

### Why `composer push` on top of pre-push hooks?

1. **Explicit intent.** `composer push` is a deliberate "I'm ready to push" action. It runs everything in order and reports clearly.
2. **Terminal-friendly.** Works outside Claude, in any terminal.
3. **Discoverability.** `composer list` shows it. New developers find it immediately.
4. **Extensible.** Adding a check means editing `composer.json`, not a shell script.

## Standard Plugin Setup

When adding a new plugin to the suite or aligning an existing one:

### Directory Structure
```
{plugin}/
├── .githooks/
│   ├── pre-commit        # Layer 1: PHPCS, PHPStan, PHPUnit, syntax
│   └── pre-push          # Layer 2: Infection, coverage threshold
├── composer.json          # Scripts: push, infection, hook:install, hook:remove
├── infection.json5        # Mutation testing config (if applicable)
└── phpunit.xml.dist       # Test config with coverage settings
```

### Setup Steps
1. Create `.githooks/pre-commit` and `.githooks/pre-push` (use templates below)
2. Add `infection`, `push`, updated `hook:install`/`hook:remove` to `composer.json`
3. Remove competing hook mechanisms (`.husky/`, `hooks/`, copied `.git/hooks/`)
4. Run `composer hook:install` to activate
5. Verify: `git config core.hooksPath` should output `.githooks`

### Pre-Commit Template
```bash
#!/bin/bash
# Pre-commit hook: fast checks that run on every commit.
# Install: composer hook:install
# Bypass:  git commit --no-verify

set -e
PLUGIN_DIR="$(git rev-parse --show-toplevel)"
cd "$PLUGIN_DIR"

RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m'
FAILED=0

# PHPCS
echo "Checking coding standards..."
if ! composer phpcs --quiet 2>/dev/null; then
    echo -e "${RED}PHPCS failed${NC} — run 'composer phpcbf' to auto-fix"
    FAILED=1
else
    echo -e "${GREEN}PHPCS passed${NC}"
fi

# PHPStan
echo "Running static analysis..."
if ! composer phpstan --quiet 2>/dev/null; then
    echo -e "${RED}PHPStan failed${NC} — run 'composer phpstan' for details"
    FAILED=1
else
    echo -e "${GREEN}PHPStan passed${NC}"
fi

# PHPUnit (no coverage)
echo "Running tests..."
if ! composer test --quiet 2>/dev/null; then
    echo -e "${RED}Tests failed${NC} — run 'composer test' for details"
    FAILED=1
else
    echo -e "${GREEN}Tests passed${NC}"
fi

# PHP Syntax (staged files only)
echo "Checking PHP syntax..."
STAGED_PHP=$(git diff --cached --name-only --diff-filter=ACM | grep '\.php$' || true)
if [ -n "$STAGED_PHP" ]; then
    for FILE in $STAGED_PHP; do
        if [ -f "$PLUGIN_DIR/$FILE" ] && ! php -l "$PLUGIN_DIR/$FILE" > /dev/null 2>&1; then
            echo -e "${RED}Syntax error: $FILE${NC}"
            FAILED=1
        fi
    done
    [ "$FAILED" -eq 0 ] && echo -e "${GREEN}Syntax OK${NC}"
fi

if [ "$FAILED" -eq 1 ]; then
    echo -e "\n${RED}Pre-commit checks failed.${NC} Fix issues or bypass with: git commit --no-verify"
    exit 1
fi
echo -e "\n${GREEN}All pre-commit checks passed.${NC}"
```

### Pre-Push Template
```bash
#!/bin/bash
# Pre-push hook: thorough checks that run before pushing.
# Install: composer hook:install
# Bypass:  git push --no-verify

set -e
PLUGIN_DIR="$(git rev-parse --show-toplevel)"
cd "$PLUGIN_DIR"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'
FAILED=0

# Infection (mutation testing) — skip if not configured
if [ -f "infection.json5" ] || [ -f "infection.json" ]; then
    echo "Running mutation testing (Infection)..."
    if ! vendor/bin/infection --threads=4 --no-progress 2>/dev/null; then
        echo -e "${RED}Infection failed${NC} — mutation score below threshold"
        FAILED=1
    else
        echo -e "${GREEN}Infection passed${NC}"
    fi
else
    echo -e "${YELLOW}Infection not configured — skipping${NC}"
fi

# Coverage threshold
echo "Checking coverage threshold..."
COVERAGE_OUTPUT=$(php vendor/bin/phpunit --coverage-text --colors=never 2>/dev/null | tail -5)
LINE_COVERAGE=$(echo "$COVERAGE_OUTPUT" | grep -oP 'Lines:\s+\K[\d.]+' || echo "0")

if [ -n "$LINE_COVERAGE" ]; then
    THRESHOLD=72
    PASS=$(echo "$LINE_COVERAGE >= $THRESHOLD" | bc -l 2>/dev/null || echo "1")
    if [ "$PASS" -eq 0 ]; then
        echo -e "${RED}Coverage ${LINE_COVERAGE}% below threshold ${THRESHOLD}%${NC}"
        FAILED=1
    else
        echo -e "${GREEN}Coverage ${LINE_COVERAGE}% (threshold: ${THRESHOLD}%)${NC}"
    fi
else
    echo -e "${YELLOW}Could not determine coverage — skipping threshold check${NC}"
fi

if [ "$FAILED" -eq 1 ]; then
    echo -e "\n${RED}Pre-push checks failed.${NC} Fix issues or bypass with: git push --no-verify"
    exit 1
fi
echo -e "\n${GREEN}All pre-push checks passed.${NC}"
```

## Consequences

### For active development
- **Commit loop stays fast** — pre-commit runs PHPCS, PHPStan, PHPUnit, syntax
- **Push is gated** — Infection and coverage threshold run before code reaches the remote
- **`composer push` is the recommended workflow** — runs everything in sequence with clear reporting
- **`/push` skill** wraps this for Claude-assisted sessions

### For suite alignment
- All plugins adopt `.githooks/` with the same hook templates
- `composer.json` scripts are standardized across the suite
- No npm dependency for hook management in any plugin

### For the audit
- DevOps assessment evaluates hook coverage, `composer push` availability, and validation tooling — not hosted CI
- Dead CI configuration (`.github/workflows/`, `.husky/`) flagged for removal

## Trade-offs

| Benefit | Cost |
|---------|------|
| No npm dependency for PHP hook management | Manual setup per-clone (`composer hook:install`) |
| Consistent across suite | Must maintain hook templates across plugins |
| Pre-push catches mutation/coverage issues | Adds minutes to push (Infection is slow) |
| `composer push` explicit and terminal-friendly | Developers can still `git push` and bypass `composer push` (hook still fires) |
| Version-controlled hooks in `.githooks/` | `core.hooksPath` must be set per-clone |

## References

- ADR-016: Local-Repo (Dropbox) Strategy
- Git `core.hooksPath`: https://git-scm.com/docs/git-config#Documentation/git-config.txt-corehooksPath
- Infection PHP: https://infection.github.io/

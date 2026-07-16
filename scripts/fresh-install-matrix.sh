#!/usr/bin/env bash
#
# fresh-install-matrix.sh — run the 14-flow fresh-install matrix against a
# disposable, base-only WordPress instance.
#
# The matrix (tests/E2E/playwright/fresh-install-matrix.spec.ts) asserts the
# BASE plugin's behaviour on a pristine site: activation menu, no satellite
# items (Flow 2), event/category/organizer/space CRUD, RSVP, shortcodes, and
# blocks. It only passes on a fresh, base-only install — historically that
# meant manually resetting a shared Local site. This harness
# provisions an ephemeral wp-env instance instead: pristine on every run, no
# WooCommerce, no satellites, torn down (or reset) between runs.
#
# Environment: wp-env (Docker), pinned to the same WP core / PHP version as the
# plugin's committed .wp-env.json (so the matrix runs on the version we ship
# "Tested up to"). Runs on a dedicated port so it never collides with the dev
# env (8888) or the submission gate (8988/8988).
#
# Usage:
#   scripts/fresh-install-matrix.sh            # reset to pristine, run, leave env up
#   scripts/fresh-install-matrix.sh --rebuild  # destroy + recreate first (config/core change)
#   scripts/fresh-install-matrix.sh --destroy  # tear the env down after the run
#   scripts/fresh-install-matrix.sh --keep     # alias for "leave env up" (default)
#
# Exit code is the Playwright run's exit code (0 = matrix green).

set -uo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
ENV_DIR="${NTE_FRESH_MATRIX_ENV_DIR:-$HOME/.nte-fresh-matrix-env}"
PORT=8890
WP_ENV="$PLUGIN_DIR/node_modules/.bin/wp-env"

RED='\033[0;31m'; GREEN='\033[0;32m'; CYAN='\033[0;36m'; NC='\033[0m'
note() { echo -e "${CYAN}[fresh-matrix]${NC} $1"; }
ok()   { echo -e "${GREEN}  ✓${NC} $1"; }
die()  { echo -e "${RED}  ✗${NC} $1"; exit 1; }

REBUILD=0
DESTROY_AFTER=0
case "${1:-}" in
	--rebuild) REBUILD=1 ;;
	--destroy) DESTROY_AFTER=1 ;;
	--keep|"") ;;
	*) die "unknown flag '${1}' (use --rebuild, --destroy, or --keep)" ;;
esac

command -v docker >/dev/null 2>&1 || die "docker not found — wp-env requires Docker."
docker info >/dev/null 2>&1 || die "Docker is not running — start Docker and retry."
[[ -x "$WP_ENV" ]] || die "wp-env not installed — run 'npm install' in the plugin dir."
command -v jq >/dev/null 2>&1 || die "jq not found — needed to build the env config."

# ---------------------------------------------------------------------------
# 1. Base-only env config, pinned to the plugin's committed core/PHP version.
#    Map the working tree (not the dist zip) so the matrix tests current code.
# ---------------------------------------------------------------------------
note "Building base-only wp-env config (core/PHP pinned from committed config)"
mkdir -p "$ENV_DIR"
jq \
	--arg plug "$PLUGIN_DIR" \
	--argjson port "$PORT" \
	'. + {
		"plugins": [ $plug ],
		"themes": [],
		"port": $port
	} | del( .testsEnvironment, .lifecycleScripts, .mysqlPort, .mappings )' \
	"$PLUGIN_DIR/.wp-env.json" > "$ENV_DIR/.wp-env.json" \
	|| die "failed to generate $ENV_DIR/.wp-env.json"
ok "config written ($(jq -r '.core' "$ENV_DIR/.wp-env.json"), PHP $(jq -r '.phpVersion' "$ENV_DIR/.wp-env.json"), port $PORT)"

# ---------------------------------------------------------------------------
# 2. Bring up a pristine instance.
#    --rebuild fully recreates; otherwise start (idempotent) + clean all,
#    which resets the database to a fresh WordPress install.
# ---------------------------------------------------------------------------
if [[ "$REBUILD" -eq 1 ]]; then
	note "Rebuilding from scratch (destroy + start)"
	( cd "$ENV_DIR" && "$WP_ENV" destroy --force ) >/dev/null 2>&1 || true
fi

note "Starting wp-env"
( cd "$ENV_DIR" && "$WP_ENV" start ) || die "wp-env start failed"

note "Resetting database to a fresh install"
( cd "$ENV_DIR" && "$WP_ENV" clean all ) >/dev/null 2>&1 || die "wp-env clean failed"

# ---------------------------------------------------------------------------
# 3. Activate the base plugin only, enable pretty permalinks.
# ---------------------------------------------------------------------------
note "Activating base plugin + enabling permalinks"
( cd "$ENV_DIR" && "$WP_ENV" run cli wp plugin activate nettertech-events ) >/dev/null 2>&1 \
	|| die "could not activate nettertech-events"
( cd "$ENV_DIR" && "$WP_ENV" run cli wp option update permalink_structure '/%postname%/' ) >/dev/null 2>&1 || true
( cd "$ENV_DIR" && "$WP_ENV" run cli wp rewrite flush --hard ) >/dev/null 2>&1 || true
ok "base plugin active on a pristine, satellite-free install"

# ---------------------------------------------------------------------------
# 4. Run the matrix against the ephemeral instance.
# ---------------------------------------------------------------------------
note "Running the 14-flow fresh-install matrix"
cd "$PLUGIN_DIR"
WP_BASE_URL="http://localhost:$PORT" \
WP_ADMIN_USERNAME=admin \
WP_ADMIN_PASSWORD=password \
	npx playwright test fresh-install-matrix --project=chromium --workers=1
RESULT=$?

# ---------------------------------------------------------------------------
# 5. Teardown (default: leave up for a fast next run).
# ---------------------------------------------------------------------------
if [[ "$DESTROY_AFTER" -eq 1 ]]; then
	note "Destroying the ephemeral instance"
	( cd "$ENV_DIR" && "$WP_ENV" destroy --force ) >/dev/null 2>&1 || true
else
	note "Leaving env up at http://localhost:$PORT (admin/password); next run resets it. --destroy to remove."
fi

[[ "$RESULT" -eq 0 ]] && ok "fresh-install matrix passed on a disposable $(jq -r '.core' "$ENV_DIR/.wp-env.json") install"
exit "$RESULT"

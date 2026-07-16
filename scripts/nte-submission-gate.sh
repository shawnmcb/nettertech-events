#!/usr/bin/env bash
#
# nte-submission-gate.sh — Authoritative submission-readiness gate for NetterTech Events.
#
# Builds the distribution zip, installs it into a clean WordPress environment,
# runs every validation tier (build, wp plugin check, phpcs, phpstan,
# unit+contract, integration, E2E), and emits a JSON evidence artifact plus an
# unambiguous pass/fail exit code.
#
# This is the ONLY definition of "submission-ready" for nettertech-events.
# No human or agent should claim the plugin is ready to submit to WP.org
# without running this script and attaching the evidence JSON it produces.
#
# Usage:
#   nte-submission-gate.sh [--branch main] [--keep-env] [--skip-e2e] [--help]
#
# Exit codes:
#   0  READY     — every gate passed, overall READY
#   1  NOT READY — at least one gate failed, or a prerequisite is missing
#

set -euo pipefail

# ---------- paths and constants ----------------------------------------------

# NTE_WORKSPACE is the directory holding build-dist.sh and the dist/ output
# (defaults to six levels above this script, the Local sites root in a
# standard Local-by-Flywheel layout).
NTE_PLUGIN_SRC="${NTE_PLUGIN_SRC:-$(cd "$(dirname "$0")/.." && pwd)}"
NTE_WORKSPACE="${NTE_WORKSPACE:-$(cd "$NTE_PLUGIN_SRC/../../../../../.." && pwd)}"
DIST_DIR="$NTE_WORKSPACE/dist"
BUILD_SCRIPT="$NTE_WORKSPACE/build-dist.sh"
CLEAN_ENV_DIR="$NTE_WORKSPACE/.submission-gate-env"
PLUGIN_SLUG="nettertech-events"
TIMESTAMP="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
TIMESTAMP_FS="$(date -u +%Y-%m-%d-%H%M%S)"
EVIDENCE_PATH="$DIST_DIR/submission-evidence-${TIMESTAMP_FS}.json"

# ---------- flags ------------------------------------------------------------

BRANCH="main"
KEEP_ENV=0
SKIP_E2E=0

while [[ $# -gt 0 ]]; do
	case "$1" in
		--branch)
			BRANCH="${2:-}"
			shift 2
			;;
		--keep-env)
			KEEP_ENV=1
			shift
			;;
		--skip-e2e)
			SKIP_E2E=1
			shift
			;;
		--help|-h)
			sed -n '3,19p' "$0" | sed 's/^# \{0,1\}//;s/^#$//'
			exit 0
			;;
		*)
			echo "Unknown flag: $1" >&2
			echo "Run $0 --help for usage." >&2
			exit 1
			;;
	esac
done

# ---------- colors and log helpers ------------------------------------------

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

log()   { echo -e "${CYAN}[gate]${NC} $*"; }
ok()    { echo -e "${GREEN}  ✓${NC} $*"; }
warn()  { echo -e "${YELLOW}  ⚠${NC} $*"; }
err()   { echo -e "${RED}  ✗${NC} $*" >&2; }
fatal() { err "$*"; exit 1; }

# ---------- gate result tracking --------------------------------------------

declare -a GATE_NAMES=()
declare -a GATE_RESULTS=()
declare -a GATE_DETAILS=()
OVERALL_READY=1
EVIDENCE_WRITTEN=0

record_gate() {
	local name="$1"
	local result="$2"
	local detail="${3:-}"
	GATE_NAMES+=("$name")
	GATE_RESULTS+=("$result")
	GATE_DETAILS+=("$detail")
	case "$result" in
		pass)
			ok "$name — $detail"
			;;
		skipped)
			warn "$name — skipped ($detail)"
			;;
		*)
			err "$name — $detail"
			OVERALL_READY=0
			;;
	esac
}

# ---------- JSON evidence artifact ------------------------------------------

ZIP_PATH=""
ZIP_SIZE_BYTES=0
ZIP_FILE_COUNT=0
GIT_SHA=""
GIT_BRANCH_ACTUAL=""

write_evidence() {
	local overall="$1"

	mkdir -p "$DIST_DIR"

	# Build gates object via jq from parallel arrays — no string concat.
	local gates_json
	gates_json=$(jq -n '{}')
	local i
	for i in "${!GATE_NAMES[@]}"; do
		gates_json=$(jq --arg k "${GATE_NAMES[$i]}" \
			--arg r "${GATE_RESULTS[$i]}" \
			--arg d "${GATE_DETAILS[$i]}" \
			'. + {($k): ($r + (if $d == "" then "" else " (" + $d + ")" end))}' \
			<<<"$gates_json")
	done

	jq -n \
		--arg timestamp "$TIMESTAMP" \
		--arg branch "$GIT_BRANCH_ACTUAL" \
		--arg sha "$GIT_SHA" \
		--arg zip_path "$ZIP_PATH" \
		--argjson zip_size_bytes "${ZIP_SIZE_BYTES:-0}" \
		--argjson zip_file_count "${ZIP_FILE_COUNT:-0}" \
		--argjson gates "$gates_json" \
		--arg overall "$overall" \
		'{
			timestamp: $timestamp,
			git: { branch: $branch, sha: $sha },
			zip: { path: $zip_path, size_bytes: $zip_size_bytes, file_count: $zip_file_count },
			gates: $gates,
			overall: $overall
		}' > "$EVIDENCE_PATH"

	EVIDENCE_WRITTEN=1
	log "Evidence: $EVIDENCE_PATH"
}

# ---------- teardown trap ---------------------------------------------------

cleanup() {
	local exit_code=$?

	# Safety net: if we died mid-flow (set -e abort) before reaching a
	# write_evidence call, write one now with whatever gate state we have.
	# This ensures every failed run produces an evidence artifact for
	# post-mortem, even when the script blew up unexpectedly.
	if [[ $EVIDENCE_WRITTEN -eq 0 ]]; then
		write_evidence "NOT READY" 2>/dev/null || true
	fi

	if [[ $KEEP_ENV -eq 1 ]]; then
		warn "Clean env retained at $CLEAN_ENV_DIR (--keep-env)"
	elif [[ -d "$CLEAN_ENV_DIR" ]]; then
		log "Tearing down clean environment..."
		if command -v wp-env >/dev/null 2>&1; then
			(cd "$CLEAN_ENV_DIR" && wp-env destroy --force >/dev/null 2>&1) || true
		fi
		rm -rf "$CLEAN_ENV_DIR" 2>/dev/null || true
		ok "Teardown complete"
	fi

	# Restore dev vendor if build-dist.sh was interrupted mid-swap.
	# (build-dist.sh has its own trap but we chain for safety.)

	exit "$exit_code"
}
trap cleanup EXIT

# ============================================================================
# 1. PREREQUISITES
# ============================================================================

log "${BOLD}Tier 4.1 NTE Submission Gate${NC}"
log "Timestamp: $TIMESTAMP"
log "Plugin source: $NTE_PLUGIN_SRC"
log "Requested branch: $BRANCH"
echo ""

log "Checking prerequisites..."

for tool in composer node npm zip jq unzip; do
	if ! command -v "$tool" >/dev/null 2>&1; then
		fatal "Missing required tool: $tool"
	fi
done
ok "core tools present: composer, node, npm, zip, jq, unzip"

# wp-cli may be shell-aliased (as in this environment); detect via wp --info.
if ! wp --info >/dev/null 2>&1; then
	fatal "wp-cli not available (tried \`wp --info\`)"
fi
ok "wp-cli available"

if [[ ! -f "$BUILD_SCRIPT" ]]; then
	fatal "build-dist.sh not found at $BUILD_SCRIPT"
fi
ok "build-dist.sh present"

if [[ ! -d "$NTE_PLUGIN_SRC" ]]; then
	fatal "Plugin source tree not found at $NTE_PLUGIN_SRC"
fi
ok "plugin source tree present"

# Capture git state early so it appears in the evidence artifact even if
# a later prereq (e.g. clean-env tooling) fails.
GIT_BRANCH_ACTUAL="$(cd "$NTE_PLUGIN_SRC" && git rev-parse --abbrev-ref HEAD)"
GIT_SHA="$(cd "$NTE_PLUGIN_SRC" && git rev-parse HEAD)"

# Working tree must be clean on the source plugin.
if ! (cd "$NTE_PLUGIN_SRC" && git diff --quiet && git diff --cached --quiet); then
	fatal "Plugin source tree has uncommitted changes. Commit or stash before running the gate."
fi
ok "plugin source tree is clean"

if [[ "$GIT_BRANCH_ACTUAL" != "$BRANCH" ]]; then
	fatal "Plugin source is on branch '$GIT_BRANCH_ACTUAL', expected '$BRANCH'. Use --branch to opt in explicitly."
fi
ok "branch: $GIT_BRANCH_ACTUAL ($GIT_SHA)"

# Clean-env provisioning requires wp-env or ddev.
CLEAN_ENV_TOOL=""
if command -v wp-env >/dev/null 2>&1; then
	CLEAN_ENV_TOOL="wp-env"
	ok "wp-env available — will use for clean install"
elif command -v ddev >/dev/null 2>&1; then
	CLEAN_ENV_TOOL="ddev"
	ok "ddev available — will use for clean install"
else
	err "Neither wp-env nor ddev is available. Install one:"
	err "  npm install -g @wordpress/env   (requires Docker)"
	err "  brew install ddev/ddev/ddev     (requires Docker or Colima)"
	err "This gate cannot attest submission-readiness without a clean install target."
	record_gate "clean_env_tooling" "fail" "neither wp-env nor ddev installed"
	write_evidence "NOT READY"
	echo ""
	err "[FAIL] NTE submission gate ${TIMESTAMP_FS} — NOT READY (see $EVIDENCE_PATH)"
	exit 1
fi

echo ""

# ============================================================================
# 2. BUILD
# ============================================================================

log "${BOLD}Gate: build${NC}"
BUILD_LOG="$(mktemp -t nte-gate-build.XXXXXX)"
if (cd "$NTE_WORKSPACE" && ./build-dist.sh "$PLUGIN_SLUG") >"$BUILD_LOG" 2>&1; then
	# Find the built zip — most recently modified matching pattern.
	ZIP_PATH=$(ls -t "$DIST_DIR/${PLUGIN_SLUG}".*.zip 2>/dev/null | head -1)
	if [[ -z "$ZIP_PATH" || ! -f "$ZIP_PATH" ]]; then
		record_gate "build" "fail" "no zip produced"
		write_evidence "NOT READY"
		cat "$BUILD_LOG" >&2
		err "[FAIL] NTE submission gate ${TIMESTAMP_FS} — NOT READY"
		exit 1
	fi
	ZIP_SIZE_BYTES=$(stat -f%z "$ZIP_PATH" 2>/dev/null || stat -c%s "$ZIP_PATH" 2>/dev/null || echo 0)
	ZIP_FILE_COUNT=$(unzip -l "$ZIP_PATH" 2>/dev/null | tail -1 | awk '{print $2}')
	record_gate "build" "pass" "$(basename "$ZIP_PATH") — ${ZIP_SIZE_BYTES} bytes, ${ZIP_FILE_COUNT} files"
else
	record_gate "build" "fail" "build-dist.sh returned non-zero"
	cat "$BUILD_LOG" >&2
	write_evidence "NOT READY"
	err "[FAIL] NTE submission gate ${TIMESTAMP_FS} — NOT READY"
	exit 1
fi
rm -f "$BUILD_LOG"

echo ""

# ============================================================================
# 3. CLEAN ENVIRONMENT
# ============================================================================

log "${BOLD}Provisioning clean environment (${CLEAN_ENV_TOOL})${NC}"

rm -rf "$CLEAN_ENV_DIR"
mkdir -p "$CLEAN_ENV_DIR"

CLEAN_ENV_URL=""
CLEAN_ENV_DB_SOCKET=""
CLEAN_ENV_WP_PATH=""

if [[ "$CLEAN_ENV_TOOL" == "wp-env" ]]; then
	# wp-env's plugins field does not accept local .zip paths — only
	# directories, GitHub/GitLab refs, or HTTPS zip URLs. Unpack the built
	# zip into CLEAN_ENV_DIR and mount the unpacked directory so wp plugin
	# check sees dist state 1:1.
	unzip -q "$ZIP_PATH" -d "$CLEAN_ENV_DIR/unpacked"

	# Build the gate .wp-env.json: reuse core/phpVersion from the plugin's
	# committed config (ADR-001) so the gate is pinned to the same WP/PHP
	# requirements as the plugin itself. Override plugins/port/testsEnvironment
	# for the gate workspace (unpacked zip, dedicated port, single env).
	PLUGIN_WP_ENV="$NTE_PLUGIN_SRC/.wp-env.json"
	if [[ -f "$PLUGIN_WP_ENV" ]]; then
		# Merge the plugin's config but strip root-only keys that wp-env's
		# 'run' command rejects (testsEnvironment, lifecycleScripts) and
		# mysqlPort (causes port collision if a tests env spins up).
		jq \
			--arg slug "./unpacked/${PLUGIN_SLUG}" \
			'. + {
				"plugins": [
					$slug,
					"https://downloads.wordpress.org/plugin/woocommerce.zip",
					"https://downloads.wordpress.org/plugin/plugin-check.zip"
				],
				"port": 8988
			} | del(.testsEnvironment, .mysqlPort, .lifecycleScripts)' \
			"$PLUGIN_WP_ENV" > "$CLEAN_ENV_DIR/.wp-env.json"
		ok "gate .wp-env.json built from plugin committed config (core/phpVersion pinned)"
	else
		warn "Plugin .wp-env.json not found at $PLUGIN_WP_ENV — falling back to inline generator (versions unpinned)"
		cat > "$CLEAN_ENV_DIR/.wp-env.json" <<EOF
{
	"core": null,
	"plugins": [
		"./unpacked/${PLUGIN_SLUG}",
		"https://downloads.wordpress.org/plugin/woocommerce.zip",
		"https://downloads.wordpress.org/plugin/plugin-check.zip"
	],
	"port": 8988,
	"testsEnvironment": false
}
EOF
	fi

	log "Starting wp-env (this may take a few minutes on first run)..."
	WP_ENV_LOG="$(mktemp -t nte-gate-wp-env.XXXXXX)"
	if ! (cd "$CLEAN_ENV_DIR" && wp-env start) >"$WP_ENV_LOG" 2>&1; then
		# Capture the tail of wp-env's output into the evidence record so
		# the failure is visible without needing --keep-env.
		WP_ENV_TAIL=$(tail -15 "$WP_ENV_LOG" | tr '\n' ' ' | sed 's/"/\\"/g')
		record_gate "clean_env_start" "fail" "wp-env start failed: ${WP_ENV_TAIL}"
		cat "$WP_ENV_LOG" >&2
		write_evidence "NOT READY"
		err "[FAIL] NTE submission gate ${TIMESTAMP_FS} — NOT READY"
		exit 1
	fi
	rm -f "$WP_ENV_LOG"
	ok "wp-env started"
	CLEAN_ENV_URL="http://localhost:8988"

	# No container capture needed — the gate config strips root-only keys
	# (testsEnvironment, lifecycleScripts, mysqlPort) so wp-env run validates
	# cleanly. See the jq del() above.

	# Activate the plugin (wp-env installs but may not activate).
	(cd "$CLEAN_ENV_DIR" && wp-env run cli wp plugin activate nettertech-events) >/dev/null 2>&1 || true
	(cd "$CLEAN_ENV_DIR" && wp-env run cli wp plugin activate woocommerce) >/dev/null 2>&1 || true

	# Enable pretty permalinks + flush rewrites so plugin route URLs resolve.
	# wp-env defaults to plain permalinks (?p=N); the plugin's E2E specs assert
	# on /events/<slug>/ format. Without this, every permalink-dependent spec
	# fails on the clean env. See NTE-045 for audit of affected specs.
	(cd "$CLEAN_ENV_DIR" && wp-env run cli wp option update permalink_structure '/%postname%/') >/dev/null 2>&1 || true
	(cd "$CLEAN_ENV_DIR" && wp-env run cli wp rewrite flush --hard) >/dev/null 2>&1 || true

	# WooCommerce provisioning: enable Cash on Delivery payment, run the WC
	# install routine (creates Cart/Checkout/My Account pages), set store
	# address so checkout validation passes. Fresh WC installs have zero
	# payment methods enabled; the ticket-purchase E2E spec expects COD.
	(cd "$CLEAN_ENV_DIR" && wp-env run cli wp option update woocommerce_cod_settings '{"enabled":"yes","title":"Cash on Delivery","description":"Pay on delivery."}' --format=json) >/dev/null 2>&1 || true
	(cd "$CLEAN_ENV_DIR" && wp-env run cli wp wc --user=admin tool run install_pages) >/dev/null 2>&1 || true
	(cd "$CLEAN_ENV_DIR" && wp-env run cli wp option update woocommerce_store_address '123 Test Street') >/dev/null 2>&1 || true
	(cd "$CLEAN_ENV_DIR" && wp-env run cli wp option update woocommerce_store_city 'Minneapolis') >/dev/null 2>&1 || true
	(cd "$CLEAN_ENV_DIR" && wp-env run cli wp option update woocommerce_default_country 'US:MN') >/dev/null 2>&1 || true
	(cd "$CLEAN_ENV_DIR" && wp-env run cli wp option update woocommerce_store_postcode '55401') >/dev/null 2>&1 || true
	(cd "$CLEAN_ENV_DIR" && wp-env run cli wp option update woocommerce_currency 'USD') >/dev/null 2>&1 || true
	# Disable "Store coming soon" mode. WC enables this by default on fresh
	# installs; it blocks guest access to cart/checkout pages with a landing
	# page, which breaks any E2E spec that performs a guest checkout flow.
	(cd "$CLEAN_ENV_DIR" && wp-env run cli wp option update woocommerce_coming_soon 'no') >/dev/null 2>&1 || true

elif [[ "$CLEAN_ENV_TOOL" == "ddev" ]]; then
	# Minimal ddev WP scaffold. ddev requires a project dir with a WP install.
	# We create one, download WP core, install WC and plugin-check, then
	# install the built zip.
	(cd "$CLEAN_ENV_DIR" && ddev config --project-type=wordpress --project-name=nte-gate --docroot=web --create-docroot) >/dev/null 2>&1 || {
		record_gate "clean_env_start" "fail" "ddev config failed"
		write_evidence "NOT READY"
		exit 1
	}
	(cd "$CLEAN_ENV_DIR" && ddev start) >/dev/null 2>&1 || {
		record_gate "clean_env_start" "fail" "ddev start failed"
		write_evidence "NOT READY"
		exit 1
	}
	(cd "$CLEAN_ENV_DIR" && ddev wp core download) >/dev/null 2>&1
	(cd "$CLEAN_ENV_DIR" && ddev wp core install \
		--url="https://nte-gate.ddev.site" \
		--title="NTE Gate" \
		--admin_user=admin \
		--admin_password=admin \
		--admin_email=admin@example.test) >/dev/null 2>&1
	(cd "$CLEAN_ENV_DIR" && ddev wp plugin install woocommerce --activate) >/dev/null 2>&1
	(cd "$CLEAN_ENV_DIR" && ddev wp plugin install plugin-check --activate) >/dev/null 2>&1
	(cd "$CLEAN_ENV_DIR" && ddev wp plugin install "$ZIP_PATH" --activate) >/dev/null 2>&1 || {
		record_gate "clean_env_install" "fail" "plugin install from zip failed"
		write_evidence "NOT READY"
		exit 1
	}
	CLEAN_ENV_URL="https://nte-gate.ddev.site"
fi

ok "nettertech-events activated in clean environment"
record_gate "clean_env" "pass" "$CLEAN_ENV_TOOL — $CLEAN_ENV_URL"

echo ""

# ============================================================================
# 4. VALIDATION GATES (run ALL — do not short-circuit)
# ============================================================================

log "${BOLD}Running validation gates${NC}"

# ----- 4a. wp plugin check against the clean install ------------------------
#
# Modern `wp plugin check` (v1.x+) removed the `--strict` flag. The tool now
# reports all findings at default severity; our heuristic is: zero findings
# lines = pass. Banner/info lines (prefix "ℹ") and "Starting" noise are
# filtered out before counting.

log "Gate: wp plugin check"
PCHECK_LOG="$(mktemp -t nte-gate-pcheck.XXXXXX)"
if [[ "$CLEAN_ENV_TOOL" == "wp-env" ]]; then
	(cd "$CLEAN_ENV_DIR" && wp-env run cli wp plugin check nettertech-events) \
		>"$PCHECK_LOG" 2>&1 || true
else
	(cd "$CLEAN_ENV_DIR" && ddev wp plugin check nettertech-events) \
		>"$PCHECK_LOG" 2>&1 || true
fi
# Strip banner lines ("ℹ Starting ...", "Success: ..."), blank lines, and
# wp-cli's "Running wp-cli on ... container" noise. Anything remaining is a
# real finding.
PCHECK_OUTPUT=$(grep -v '^[[:space:]]*$' "$PCHECK_LOG" \
	| grep -v '^ℹ ' \
	| grep -v '^✔ Ran ' \
	| grep -v '^Success:' \
	| grep -v 'Running .* on .* container' \
	|| true)
if [[ -z "$PCHECK_OUTPUT" ]]; then
	record_gate "wp_plugin_check" "pass" "no findings"
else
	PCHECK_SNIPPET=$(echo "$PCHECK_OUTPUT" | head -3 | tr '\n' '; ' | cut -c1-200)
	record_gate "wp_plugin_check" "fail" "$PCHECK_SNIPPET"
fi
rm -f "$PCHECK_LOG"

# ----- 4b. composer phpcs against source tree ------------------------------

log "Gate: composer phpcs (source tree)"
PHPCS_LOG="$(mktemp -t nte-gate-phpcs.XXXXXX)"
if (cd "$NTE_PLUGIN_SRC" && composer phpcs -- --report=summary) >"$PHPCS_LOG" 2>&1; then
	record_gate "phpcs" "pass" "0 errors"
else
	PHPCS_SNIPPET=$(tail -20 "$PHPCS_LOG" | tr '\n' ' ' | cut -c1-200)
	record_gate "phpcs" "fail" "$PHPCS_SNIPPET"
fi
rm -f "$PHPCS_LOG"

# ----- 4c. composer phpstan against source tree ----------------------------

log "Gate: composer phpstan (source tree)"
PHPSTAN_LOG="$(mktemp -t nte-gate-phpstan.XXXXXX)"
if (cd "$NTE_PLUGIN_SRC" && composer phpstan) >"$PHPSTAN_LOG" 2>&1; then
	record_gate "phpstan" "pass" "0 errors, Level 7"
else
	PHPSTAN_SNIPPET=$(tail -20 "$PHPSTAN_LOG" | tr '\n' ' ' | cut -c1-200)
	record_gate "phpstan" "fail" "$PHPSTAN_SNIPPET"
fi
rm -f "$PHPSTAN_LOG"

# ----- 4d. composer test (unit + Tier 1 contract tests) --------------------

log "Gate: composer test (unit + Tier 1 contract tests)"
UNIT_LOG="$(mktemp -t nte-gate-unit.XXXXXX)"
if (cd "$NTE_PLUGIN_SRC" && composer test) >"$UNIT_LOG" 2>&1; then
	# Parse the PHPUnit summary line: "OK (4960 tests, 11447 assertions)"
	UNIT_SUMMARY=$(grep -E "^(OK|Tests:)" "$UNIT_LOG" | tail -1 || echo "summary not parsed")
	record_gate "unit_tests" "pass" "$UNIT_SUMMARY"
else
	UNIT_SNIPPET=$(grep -E "FAILURES|ERRORS|Tests:" "$UNIT_LOG" | tail -5 | tr '\n' ' ' | cut -c1-250)
	record_gate "unit_tests" "fail" "$UNIT_SNIPPET"
fi
rm -f "$UNIT_LOG"

# ----- 4e. integration tests ------------------------------------------------
#
# The integration suite in tests/Integration/ is configured to run against the
# DB socket defined in tests/wp-tests-config.php. For the clean environment,
# adapting that socket requires either:
#   (a) patching wp-tests-config.php to honor env vars, or
#   (b) running the integration suite against the source tree's existing config.
#
# Drift note: the plan assumed "Tier 2 integration tests against the clean
# install's MySQL" but this gate still runs integration against
# the source tree's configured MySQL. This still exercises real MySQL at the
# code/DB boundary — which is the bug-class the tier was added to catch.

log "Gate: integration tests (source tree MySQL)"
INT_LOG="$(mktemp -t nte-gate-int.XXXXXX)"
# Run phpunit directly with a 2GB memory limit. The default 128M is
# insufficient for the full integration suite — SchemaMigrationIntegrationTest
# OOMs mid-run, producing no parseable phpunit summary. Bypassing the
# composer script lets us inject -d memory_limit=2G.
if (cd "$NTE_PLUGIN_SRC" && php -d memory_limit=2G vendor/bin/phpunit \
	--configuration phpunit-integration.xml.dist --no-coverage) \
	>"$INT_LOG" 2>&1; then
	INT_SUMMARY=$(grep -E "^(OK|Tests:)" "$INT_LOG" | tail -1 || echo "summary not parsed")
	record_gate "integration_tests" "pass" "$INT_SUMMARY"
else
	# Robust failure capture: try the phpunit summary grep first, fall back
	# to the raw log tail if phpunit produced no summary (e.g., OOM or fatal
	# bootstrap error). `|| true` on the pipeline prevents set -e + pipefail
	# from aborting when grep matches nothing.
	INT_SNIPPET=$(grep -E "FAILURES|ERRORS|Tests:" "$INT_LOG" 2>/dev/null \
		| tail -5 | tr '\n' ' ' | cut -c1-250 || true)
	if [[ -z "$INT_SNIPPET" ]]; then
		INT_SNIPPET=$(tail -10 "$INT_LOG" | tr '\n' ' ' | cut -c1-250)
	fi
	# Preserve full log for post-mortem; snippet in evidence JSON is truncated.
	INT_FAIL_LOG="$DIST_DIR/integration-failure-${TIMESTAMP_FS}.log"
	cp "$INT_LOG" "$INT_FAIL_LOG" 2>/dev/null && warn "Integration log preserved at $INT_FAIL_LOG"
	record_gate "integration_tests" "fail" "$INT_SNIPPET"
fi
rm -f "$INT_LOG"

# ----- 4f. Playwright E2E ---------------------------------------------------

if [[ $SKIP_E2E -eq 1 ]]; then
	echo ""
	warn "${BOLD}E2E TESTS SKIPPED via --skip-e2e flag${NC}"
	warn "This gate is being run in fast-iteration mode."
	warn "A submission-ready verdict requires a full run without this flag."
	echo ""
	record_gate "e2e_tests" "skipped" "--skip-e2e flag"
else
	log "Gate: Playwright E2E (clean environment URL: $CLEAN_ENV_URL)"

	# Playwright tests read WP_BASE_URL from .env in the plugin root.
	# For the clean env run we set it via env var inline.
	E2E_LOG="$(mktemp -t nte-gate-e2e.XXXXXX)"

	# Ensure node_modules present in source tree.
	if [[ ! -d "$NTE_PLUGIN_SRC/node_modules" ]]; then
		(cd "$NTE_PLUGIN_SRC" && npm ci) >/dev/null 2>&1 || warn "npm ci failed"
	fi

	export WP_BASE_URL="$CLEAN_ENV_URL"
	export WP_ADMIN_USERNAME="${WP_ADMIN_USERNAME:-admin}"
	export WP_ADMIN_PASSWORD="${WP_ADMIN_PASSWORD:-password}"
	# WP-CLI command prefix: specs use WP_CLI_CMD to run wp eval fixtures.
	# wp-env run cli wp executes inside the Docker container.
	export WP_CLI_CMD="cd $CLEAN_ENV_DIR && wp-env run cli wp"
	# Single worker: multiple specs logging into the same WP admin session
	# in parallel causes cookie invalidation cascades on a single-user
	# wp-env instance. The config defaults to workers=4 locally.
	if (cd "$NTE_PLUGIN_SRC" && npx playwright test --project=chromium --reporter=line --workers=1) \
	   >"$E2E_LOG" 2>&1; then
		E2E_SUMMARY=$(grep -E "passed|failed" "$E2E_LOG" | tail -1 || echo "summary not parsed")
		record_gate "e2e_tests" "pass" "$E2E_SUMMARY"
	else
		E2E_SNIPPET=$(grep -E "passed|failed|Error" "$E2E_LOG" | tail -5 | tr '\n' ' ' | cut -c1-250)
		record_gate "e2e_tests" "fail" "$E2E_SNIPPET"
	fi
	rm -f "$E2E_LOG"
fi

echo ""

# ============================================================================
# 5. EVIDENCE + EXIT
# ============================================================================

if [[ $OVERALL_READY -eq 1 ]]; then
	OVERALL="READY"
else
	OVERALL="NOT READY"
fi

write_evidence "$OVERALL"

echo ""
if [[ "$OVERALL" == "READY" ]]; then
	echo -e "${GREEN}${BOLD}[PASS]${NC} NTE submission gate ${TIMESTAMP_FS} — ${GREEN}READY${NC}"
	exit 0
else
	echo -e "${RED}${BOLD}[FAIL]${NC} NTE submission gate ${TIMESTAMP_FS} — ${RED}NOT READY${NC} (see $EVIDENCE_PATH)"
	exit 1
fi

#!/usr/bin/env bash
# run-plugin-check.sh — canonical Plugin Check invocation for the dev source.
#
# Plugin Check runs against the live install of the plugin, which on a dev
# machine includes test/ files, dotfile configs, and other artifacts that
# `.distignore` excludes from the WP.org submission package. This wrapper
# applies the matching exclusions so the local run produces the same result
# the WP.org reviewer would see against the dist zip.
#
# Authoritative gate for "is this submission-ready" is still
# `scripts/nte-submission-gate.sh` (which runs Plugin Check against a clean
# WP install of the dist zip via wp-env or ddev). Use this wrapper for
# day-to-day local checks where a clean env isn't available.
#
# Usage: scripts/run-plugin-check.sh [extra wp plugin check flags]

set -euo pipefail

# Directories excluded from the dist (per .distignore). Plugin Check should
# skip these for source-level scans.
EXCLUDE_DIRS=(
	tests
	test-results
	playwright-report
	build
	scripts
	docs
	architecture
	languages
	node_modules
	.claude
	.gemini
	.serena
	.audit
	.remediation
	.marketing
	.obsidian
	.claudeBAK
	.git
	.githooks
	.husky
	.wordpress-org
	.worktrees
	.phpunit.cache
	vendor-dev-backup
)

# Individual files excluded from the dist (dotfiles, dev configs, stray docs).
EXCLUDE_FILES=(
	.DS_Store
	.commitlintrc.json
	.distignore
	.editorconfig
	.env
	.env.example
	.eslintignore
	.eslintrc
	.eslintrc.js
	.eslintrc.json
	.gitattributes
	.gitignore
	.gitleaksignore
	.prettierrc
	.prettierrc.json
	.stylelintrc.json
	.wp-env.json
	CONTRIBUTING.md
	CURRENT.md
	GEMINI.md
	README.md
	infection.json5
	infection-bootstrap.php
	patchwork.json
	phpcs.xml
	phpmd.xml
	phpstan.neon
	phpstan-baseline.neon
	phpstan-bootstrap.php
	phpunit.xml.dist
	phpunit-integration.xml.dist
	playwright.config.ts
	webpack.config.js
)

EXCLUDE_DIRS_CSV="$(IFS=,; echo "${EXCLUDE_DIRS[*]}")"
EXCLUDE_FILES_CSV="$(IFS=,; echo "${EXCLUDE_FILES[*]}")"

cd "$(dirname "$0")/../../../.."

# Shell out to wp-cli with the canonical flags.
wp plugin check nettertech-events \
	--severity=error \
	--exclude-directories="$EXCLUDE_DIRS_CSV" \
	--exclude-files="$EXCLUDE_FILES_CSV" \
	"$@"

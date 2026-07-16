#!/bin/bash
# Patch Infection's IncludeInterceptor for PHP 8.5 compatibility.
#
# PHP 8.5 no longer sets STREAM_OPEN_FOR_INCLUDE (0x80) in stream_open options,
# which breaks Infection's file interception mechanism (all mutants escape).
# This patch removes the include-flag check so interception matches on path alone.
#
# Upstream issue: https://github.com/infection/include-interceptor/issues/XXX
# Re-run after: composer install, composer update
#
# Usage: bash scripts/patch-infection-interceptor.sh

set -e
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
FILE="$PLUGIN_DIR/vendor/infection/include-interceptor/src/IncludeInterceptor.php"

if [ ! -f "$FILE" ]; then
    echo "IncludeInterceptor.php not found — skipping patch"
    exit 0
fi

# Check if already patched (look for our comment)
if grep -q "PHP 8.5 no longer sets STREAM_OPEN_FOR_INCLUDE" "$FILE"; then
    echo "IncludeInterceptor already patched for PHP 8.5"
    exit 0
fi

# Apply patch: remove the $including gate from the interception check
php -r "
\$content = file_get_contents('$FILE');

\$old = <<<'ORIGINAL'
        \$including = (bool) (\$options & self::STREAM_OPEN_FOR_INCLUDE);

        try {
            if (\$including) {
                if (\$path === self::\$intercept || realpath(\$path) === self::\$intercept) {
                    \$this->fp = fopen(self::\$replacement, 'r');

                    return true;
                }
            }
ORIGINAL;

\$new = <<<'PATCHED'
        try {
            // PHP 8.5 no longer sets STREAM_OPEN_FOR_INCLUDE (0x80) for include/require.
            // Match on path regardless of the include flag to support all PHP versions.
            if (\$path === self::\$intercept || realpath(\$path) === self::\$intercept) {
                \$this->fp = fopen(self::\$replacement, \$mode);

                return true;
            }
PATCHED;

if (strpos(\$content, \$old) === false) {
    echo \"Patch target not found — IncludeInterceptor may have changed\n\";
    exit(1);
}

\$patched = str_replace(\$old, \$new, \$content);
file_put_contents('$FILE', \$patched);
echo \"IncludeInterceptor patched for PHP 8.5 compatibility\n\";
"

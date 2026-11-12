#!/usr/bin/env bash
# Crea dist/integra-report-<versione>.zip pronto per "Plugin › Carica plugin".
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT/integra-report"
composer install --no-dev --optimize-autoloader --no-interaction --quiet
VERSION="$(grep -m1 "define('IR_VERSION'" integra-report.php | sed -E "s/.*'([0-9.]+)'.*/\1/")"
mkdir -p "$ROOT/dist"
OUT="$ROOT/dist/integra-report-$VERSION.zip"
rm -f "$OUT"
cd "$ROOT"
zip -rq "$OUT" integra-report -x "integra-report/tests/*" "*/.git/*" "*/.github/*" "integra-report/vendor/*/tests/*" "integra-report/vendor/*/*/tests/*" "integra-report/vendor/*/*/examples/*" "integra-report/vendor/*/*/fixtures/*" "integra-report/vendor/*/*/scripts/*" "*.DS_Store"
echo "Creato $OUT"

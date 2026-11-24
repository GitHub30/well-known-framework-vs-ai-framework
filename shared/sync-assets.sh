#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"

# Generate minified CSS AND OPcache-cached PHP constant (zero per-request disk I/O!)
php -r '
$css = file_get_contents($argv[1]);
$css = preg_replace("#/\*.*?\*/#s", "", $css);
$css = preg_replace("/\s+/", " ", $css);
$css = preg_replace("/\s*([{};:,>~])\s*/", "$1", $css);
$css = str_replace(";}", "}", trim($css));
file_put_contents($argv[2], $css);
$php = "<?php\ndeclare(strict_types=1);\nreturn " . var_export($css, true) . ";\n";
file_put_contents($argv[3], $php);
echo "Minified CSS: " . strlen($css) . " bytes (compiled OPcache PHP module)\n";
' "$ROOT_DIR/shared/public/assets/app.css" "$ROOT_DIR/shared/public/assets/app.min.css" "$ROOT_DIR/shared/public/assets/inline_css.php"

APPS=("ai-framework" "laravel" "phalcon" "slim")

for app in "${APPS[@]}"; do
  pub="$ROOT_DIR/apps/$app/public"
  mkdir -p "$pub/assets" "$ROOT_DIR/apps/$app/storage/cache"
  cp -a "$ROOT_DIR/shared/public/assets/." "$pub/assets/"
  rm -f "$pub/assets/inline_css.php"
  cp -a "$ROOT_DIR/shared/public/assets/inline_css.php" "$ROOT_DIR/apps/$app/storage/cache/inline_css.php"
  cp -a "$ROOT_DIR/shared/public/sw.js" "$pub/sw.js"
done

# Remove Laravel default empty favicon.ico so all 4 frameworks behave identically
rm -f "$ROOT_DIR/apps/laravel/public/favicon.ico" "$ROOT_DIR/apps/laravel/public/robots.txt"

echo "Synced shared static assets, OPcache inline CSS, and sw.js to all 4 apps."

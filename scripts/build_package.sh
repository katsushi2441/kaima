#!/usr/bin/env bash
# kappstore で配布するzipを作る。設定の実物・データ・鍵は入れない。
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p outputs
stamp=$(date +%Y%m%d)
zip="outputs/kaima-${stamp}.zip"
rm -f "$zip"
zip -r "$zip" \
  public/kaima.php public/kaima_config.php.example public/kaima_data/.htaccess \
  scripts/check_kaima.php \
  README.md LICENSE \
  -x '*.sqlite' -x '*.log' >/dev/null
echo "built: $zip ($(du -h "$zip" | cut -f1))"

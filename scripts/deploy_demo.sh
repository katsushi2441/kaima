#!/usr/bin/env bash
# デモの公開。https://proto.exbridge.jp/kaima/
# デモのAIは0.3のkaima-vision-relay(:18343, ddns経由)→ローカルgemma4。
# トークンは .env から注入する(リポジトリに置かない)。
set -euo pipefail
cd "$(dirname "$0")/.."
php scripts/check_kaima.php >/dev/null || { echo "自己テスト失敗→デプロイ中止" >&2; exit 1; }
set -a; . /home/kojima/work/aixec/.env; set +a
RELAY_TOKEN=$(grep -m1 '^KAIMA_RELAY_TOKEN=' .env | cut -d= -f2)
DEMO_PW=$(grep -m1 '^KAIMA_DEMO_PASSWORD=' .env | cut -d= -f2)
[ -n "$RELAY_TOKEN" ] && [ -n "$DEMO_PW" ] || { echo ".envにKAIMA_RELAY_TOKEN/KAIMA_DEMO_PASSWORDがない" >&2; exit 1; }
remote="/web/proto_exbridge_jp/kaima"
up() { curl --fail --silent --show-error --ftp-create-dirs -T "$1" \
  "ftp://${FTP_USER}:${FTP_PASS}@${FTP_HOST}${remote}/${2}"; echo "up: $2"; }
tmp=$(mktemp)
sed -e "s/__KAIMA_RELAY_TOKEN__/${RELAY_TOKEN}/" -e "s/__KAIMA_DEMO_PASSWORD__/${DEMO_PW}/" demo/kaima_config.php > "$tmp"
up public/kaima.php kaima.php
up "$tmp" kaima_config.php
rm -f "$tmp"
up demo/index.php index.php
up demo/.htaccess .htaccess
up public/kaima_data/.htaccess kaima_data/.htaccess
echo "published: https://proto.exbridge.jp/kaima/"

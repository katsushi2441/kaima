<?php
/** デモ環境の設定(https://proto.exbridge.jp/kaima/)。トークン類はデプロイ時に注入される。 */
define('KAIMA_TITLE',       'Kurage AI名刺解析 デモ');
define('KAIMA_BRAND_COLOR', '#7a4a8c');
define('KAIMA_PASSWORD',    '__KAIMA_DEMO_PASSWORD__');
define('KAIMA_PASSWORD_HASH', '');
define('KAIMA_API_TOKEN',   '');
define('KAIMA_API_BASE',    'http://exbridge.ddns.net:18343/v1');
define('KAIMA_API_KEY',     '__KAIMA_RELAY_TOKEN__');
define('KAIMA_MODEL',       'gemma4:12b-it-qat');
define('KAIMA_HOLDERS',     'デモ太郎,デモ花子');
define('KAIMA_RATE_PER_HOUR', 10);
define('KAIMA_MAX_FILES',   3);
define('KAIMA_DEMO',        true);

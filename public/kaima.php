<?php
/**
 * Kurage AI Meishi Analysis (kaima) — Kurage AI名刺解析システム。1ファイル。
 *
 * スマホで名刺を撮ってアップ→AIが解析→「下書き」に登録→人が原本画像と
 * 見比べて確認・修正・承認→台帳へ。DBサーバー不要(SQLite)・レンタルサーバーで動く。
 *
 * 【設計の芯】(kcrmagent / kdbagent と同じ思想)
 *  1. AIに自己採点させない — AIの読み取りは必ず下書き(drafts)まで。台帳
 *     (companies/people/cards)に書けるのは人の承認だけ。検証は決定的な
 *     PHPコード(メール形式・DNS・電話・郵便番号)が行う。
 *  2. 入口が違っても同じ関門(kaima_can)を通る。宣言に無い操作は実行できない。
 *  3. 名寄せ — 氏名+会社の正規化照合で同一人物を判定。新しい名刺で役職が
 *     変われば「変遷履歴」を残して更新する(昇進が見える)。
 *  4. 接点 — 名刺1枚に「誰が・いつ・どこで」が付く。会社ページで
 *     「この会社、うちの誰が知ってる?」に答える。
 *  5. 名刺画像は個人情報。直URLでは公開せず、ログイン済みセッションだけに配信する。
 *
 * カスタマイズは kaima_config.php を編集。PHP 7.0+ / pdo_sqlite / gd / curl。
 */

date_default_timezone_set('Asia/Tokyo');

$cfg = __DIR__ . '/kaima_config.php';
if (!is_file($cfg)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'kaima_config.php がありません。kaima_config.php.example をコピーして作成してください。';
    exit;
}
require $cfg;

if (!defined('KAIMA_TITLE'))         { define('KAIMA_TITLE', 'Kurage AI名刺解析'); }
if (!defined('KAIMA_BRAND_COLOR'))   { define('KAIMA_BRAND_COLOR', '#7a4a8c'); }
if (!defined('KAIMA_PASSWORD'))      { define('KAIMA_PASSWORD', ''); }
if (!defined('KAIMA_PASSWORD_HASH')) { define('KAIMA_PASSWORD_HASH', ''); }
if (!defined('KAIMA_API_TOKEN'))     { define('KAIMA_API_TOKEN', ''); }
if (!defined('KAIMA_API_BASE'))      { define('KAIMA_API_BASE', 'https://api.openai.com/v1'); }
if (!defined('KAIMA_API_KEY'))       { define('KAIMA_API_KEY', ''); }
if (!defined('KAIMA_MODEL'))         { define('KAIMA_MODEL', 'gpt-4o-mini'); }
if (!defined('KAIMA_HOLDERS'))       { define('KAIMA_HOLDERS', ''); }   // 名刺の持ち主(社員)候補。カンマ区切り
if (!defined('KAIMA_RATE_PER_HOUR')) { define('KAIMA_RATE_PER_HOUR', 30); }
if (!defined('KAIMA_MAX_FILES'))     { define('KAIMA_MAX_FILES', 5); }
if (!defined('KAIMA_DEMO'))          { define('KAIMA_DEMO', false); }
if (!defined('KAIMA_DATA_DIR'))      { define('KAIMA_DATA_DIR', __DIR__ . '/kaima_data'); }

/* ================= ユーティリティ ================= */

function ka_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function ka_json_out($code, $data) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function ka_holders() { return array_values(array_filter(array_map('trim', explode(',', KAIMA_HOLDERS)))); }
function ka_rate_max() { $n = (int)KAIMA_RATE_PER_HOUR; if (KAIMA_DEMO) { $n = min($n, 10); } return $n; }

/** 照合用正規化(全半角・空白・大小・中黒ゆれの吸収)。表示は原文のまま。 */
function ka_norm($s) {
    $s = trim((string)$s);
    if (function_exists('mb_convert_kana')) { $s = mb_convert_kana($s, 'asKV', 'UTF-8'); }
    $s = preg_replace('/[\s・]+/u', '', $s);
    if (function_exists('mb_strtolower')) { $s = mb_strtolower($s, 'UTF-8'); }
    return $s;
}

/** 電話系の正規化: 全角→半角、数字とハイフン以外を除去。 */
function ka_tel($s) {
    $s = trim((string)$s);
    if ($s === '') { return ''; }
    if (function_exists('mb_convert_kana')) { $s = mb_convert_kana($s, 'as', 'UTF-8'); }
    $s = preg_replace('/[^0-9+\-]/', '', $s);
    return $s;
}

/* ================= DB(SQLite) ================= */

function ka_pdo() {
    static $pdo = null;
    if ($pdo !== null) { return $pdo; }
    if (!is_dir(KAIMA_DATA_DIR)) { @mkdir(KAIMA_DATA_DIR, 0755, true); }
    if (!is_dir(KAIMA_DATA_DIR . '/img')) { @mkdir(KAIMA_DATA_DIR . '/img', 0755, true); }
    $pdo = new PDO('sqlite:' . KAIMA_DATA_DIR . '/kaima.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=8000');
    ka_schema($pdo);
    return $pdo;
}

function ka_schema($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS companies(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL, norm TEXT NOT NULL UNIQUE, created_at TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS people(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        company_id INTEGER NOT NULL REFERENCES companies(id),
        name TEXT NOT NULL, norm TEXT NOT NULL, kana TEXT NOT NULL DEFAULT '',
        department TEXT NOT NULL DEFAULT '', title TEXT NOT NULL DEFAULT '',
        tel TEXT NOT NULL DEFAULT '', mobile TEXT NOT NULL DEFAULT '',
        email TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '',
        zip TEXT NOT NULL DEFAULT '', address TEXT NOT NULL DEFAULT '',
        note TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS career(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        person_id INTEGER NOT NULL REFERENCES people(id),
        department TEXT NOT NULL DEFAULT '', title TEXT NOT NULL DEFAULT '',
        noted_at TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cards(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        person_id INTEGER NOT NULL REFERENCES people(id),
        image TEXT NOT NULL DEFAULT '',
        holder TEXT NOT NULL DEFAULT '', place TEXT NOT NULL DEFAULT '',
        exchanged_on TEXT NOT NULL, memo TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS drafts(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        image TEXT NOT NULL,
        fields_json TEXT NOT NULL, warns_json TEXT NOT NULL DEFAULT '[]',
        match_json TEXT NOT NULL DEFAULT '{}',
        holder TEXT NOT NULL DEFAULT '', place TEXT NOT NULL DEFAULT '',
        exchanged_on TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        decided_by TEXT NOT NULL DEFAULT '', decided_at TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ts TEXT NOT NULL, actor TEXT NOT NULL, action TEXT NOT NULL, detail TEXT NOT NULL)");
}

function ka_now() { return date('Y-m-d H:i:s'); }

function ka_audit($actor, $action, $detail) {
    $st = ka_pdo()->prepare('INSERT INTO audit(ts,actor,action,detail) VALUES(?,?,?,?)');
    $st->execute(array(ka_now(), $actor, $action, mb_substr((string)$detail, 0, 400, 'UTF-8')));
}

/* ================= 関門(全書き込みを裁く宣言表) ================= */

class KaDenied extends Exception {}

function ka_can($actor, $action) {
    $rules = array(
        'ai'   => array('draft.create'),
        'api'  => array('card.upload'),
        'user' => array('card.upload', 'draft.create', 'draft.approve', 'draft.reject'),
    );
    return isset($rules[$actor]) && in_array($action, $rules[$actor], true);
}

function ka_assert($actor, $action) {
    if (!ka_can($actor, $action)) {
        throw new KaDenied($actor . ' は ' . $action . ' を許可されていません');
    }
}

/* ================= 画像 ================= */

/** アップロード画像を保存し、保存名を返す(拡張子検証つき)。 */
function ka_save_image($tmpPath) {
    $info = @getimagesize($tmpPath);
    if (!$info || !in_array($info[2], array(IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP), true)) {
        return array('error' => '画像(JPEG/PNG/WebP)をアップロードしてください');
    }
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.jpg';
    // 原本は最大2000pxに収めてJPEG保存(名刺なら十分・容量対策)
    $img = @imagecreatefromstring((string)file_get_contents($tmpPath));
    if (!$img) { return array('error' => '画像を読み込めませんでした'); }
    $w = imagesx($img); $h = imagesy($img);
    $max = 2000;
    if (max($w, $h) > $max) {
        $r = $max / max($w, $h);
        $img2 = imagescale($img, (int)($w * $r), (int)($h * $r));
        imagedestroy($img); $img = $img2;
    }
    imagejpeg($img, KAIMA_DATA_DIR . '/img/' . $name, 88);
    imagedestroy($img);
    return array('name' => $name);
}

/** AIに渡す縮小版(最大1280px)のbase64。 */
function ka_image_b64($name) {
    $path = KAIMA_DATA_DIR . '/img/' . basename($name);
    $img = @imagecreatefromstring((string)file_get_contents($path));
    if (!$img) { return ''; }
    $w = imagesx($img); $h = imagesy($img);
    $max = 1280;
    if (max($w, $h) > $max) {
        $r = $max / max($w, $h);
        $img2 = imagescale($img, (int)($w * $r), (int)($h * $r));
        imagedestroy($img); $img = $img2;
    }
    ob_start(); imagejpeg($img, null, 85); $bin = ob_get_clean();
    imagedestroy($img);
    return base64_encode($bin);
}

/* ================= AI解析(OpenAI互換 vision) ================= */

function ka_ai_parse($imageName) {
    if (KAIMA_API_KEY === '' || KAIMA_API_KEY === 'sk-xxxx') {
        return array('error' => 'AIのAPIキーが未設定です(kaima_config.php)');
    }
    $b64 = ka_image_b64($imageName);
    if ($b64 === '') { return array('error' => '画像の変換に失敗しました'); }
    $prompt = 'この名刺画像から情報を読み取り、次のJSONだけを出力してください。読み取れない項目はnull。'
        . '値の創作・推測補完は禁止。文字は名刺の表記のまま(旧字体・記号も正規化しない)。'
        . '{"company":"","department":"","title":"","name":"","kana":"","zip":"","address":"","tel":"","fax":"","mobile":"","email":"","url":""}';
    $payload = json_encode(array(
        'model' => KAIMA_MODEL,
        'messages' => array(array('role' => 'user', 'content' => array(
            array('type' => 'text', 'text' => $prompt),
            array('type' => 'image_url', 'image_url' => array('url' => 'data:image/jpeg;base64,' . $b64)),
        ))),
        'temperature' => 0,
        'max_tokens' => 700,
    ), JSON_UNESCAPED_UNICODE);
    $ch = curl_init(rtrim(KAIMA_API_BASE, '/') . '/chat/completions');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Authorization: Bearer ' . KAIMA_API_KEY),
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 180,
    ));
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) { return array('error' => 'AIへの接続に失敗しました: ' . $err); }
    $j = json_decode($res, true);
    if ($code !== 200 || !isset($j['choices'][0]['message']['content'])) {
        $msg = isset($j['error']['message']) ? $j['error']['message'] : ('HTTP ' . $code);
        return array('error' => 'AIがエラーを返しました: ' . $msg);
    }
    $text = trim((string)$j['choices'][0]['message']['content']);
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $text);
    $out = json_decode($text, true);
    if (!is_array($out)) { return array('error' => 'AIの出力をJSONとして解釈できませんでした'); }
    return array('data' => $out);
}

/* ================= 決定的検証(AIに自己採点させない本体) ================= */

/**
 * AI出力(または人の編集値)を正規化・検証する。
 * 戻り値: array('fields'=>正規化済み, 'warns'=>警告)
 */
function ka_validate($data) {
    $f = array(); $warns = array();
    foreach (array('company','department','title','name','kana','zip','address','tel','fax','mobile','email','url') as $k) {
        $v = isset($data[$k]) && $data[$k] !== null ? trim((string)$data[$k]) : '';
        $f[$k] = mb_substr($v, 0, 200, 'UTF-8');
    }
    if ($f['name'] === '') { $warns[] = '氏名を読み取れませんでした(承認前に入力が必要です)'; }
    if ($f['company'] === '') { $warns[] = '会社名を読み取れませんでした'; }

    foreach (array('tel', 'fax', 'mobile') as $k) {
        if ($f[$k] === '') { continue; }
        $t = ka_tel($f[$k]);
        if (strlen(preg_replace('/[^0-9]/', '', $t)) < 10) { $warns[] = $k . 'の桁数が不足しています(' . $f[$k] . ')'; }
        $f[$k] = $t;
    }
    if ($f['zip'] !== '') {
        $z = ka_tel($f['zip']);
        if (preg_match('/^(\d{3})-?(\d{4})$/', $z, $m)) { $f['zip'] = $m[1] . '-' . $m[2]; }
        else { $warns[] = '郵便番号の形式が不正です(' . $f['zip'] . ')'; }
    }
    if ($f['email'] !== '') {
        if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
            $warns[] = 'メールアドレスの形式が不正です(' . $f['email'] . ')';
        } elseif (function_exists('checkdnsrr')) {
            $domain = substr(strrchr($f['email'], '@'), 1);
            if ($domain && !@checkdnsrr($domain, 'MX') && !@checkdnsrr($domain, 'A')) {
                $warns[] = 'メールのドメイン(' . $domain . ')がDNSで確認できません(誤読の可能性)';
            }
        }
    }
    if ($f['url'] !== '') {
        $u = $f['url'];
        if (!preg_match('~^https?://~', $u)) { $u = 'https://' . $u; }
        if (!filter_var($u, FILTER_VALIDATE_URL)) { $warns[] = 'URLの形式が不正です(' . $f['url'] . ')'; }
        else { $f['url'] = $u; }
    }
    return array('fields' => $f, 'warns' => $warns);
}

/** 名寄せ: 既存の会社・人物との照合(AIの判断は使わずDBで照合)。 */
function ka_match($pdo, $fields) {
    $m = array('company_id' => null, 'person_id' => null, 'prev_title' => '', 'prev_department' => '');
    if ($fields['company'] !== '') {
        $st = $pdo->prepare('SELECT id FROM companies WHERE norm=?');
        $st->execute(array(ka_norm($fields['company'])));
        $cid = $st->fetchColumn();
        if ($cid) { $m['company_id'] = (int)$cid; }
    }
    if ($m['company_id'] && $fields['name'] !== '') {
        $st = $pdo->prepare('SELECT id, title, department FROM people WHERE company_id=? AND norm=?');
        $st->execute(array($m['company_id'], ka_norm($fields['name'])));
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if ($p) {
            $m['person_id'] = (int)$p['id'];
            $m['prev_title'] = (string)$p['title'];
            $m['prev_department'] = (string)$p['department'];
        }
    }
    return $m;
}

/* ================= 取り込み→下書き ================= */

function ka_draft_create($actor, $imageName, $fields, $warns, $match, $holder, $place, $date) {
    ka_assert($actor, 'draft.create');
    $pdo = ka_pdo();
    if (!ka_valid_date($date)) { $date = date('Y-m-d'); }
    $st = $pdo->prepare('INSERT INTO drafts(image,fields_json,warns_json,match_json,holder,place,exchanged_on,created_at) VALUES(?,?,?,?,?,?,?,?)');
    $st->execute(array($imageName,
        json_encode($fields, JSON_UNESCAPED_UNICODE),
        json_encode($warns, JSON_UNESCAPED_UNICODE),
        json_encode($match, JSON_UNESCAPED_UNICODE),
        mb_substr($holder, 0, 50, 'UTF-8'), mb_substr($place, 0, 100, 'UTF-8'), $date, ka_now()));
    $id = (int)$pdo->lastInsertId();
    ka_audit($actor, 'draft.create', 'draft#' . $id . ' ' . $fields['name'] . '/' . $fields['company']);
    return $id;
}

function ka_valid_date($s) {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$s, $m)) { return false; }
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/** 画像1枚の取り込みパイプライン。戻り値: array('draft_id'=>) or array('error'=>,'code'=>) */
function ka_pipeline($actor, $tmpPath, $holder, $place, $date) {
    ka_assert($actor, 'card.upload');
    $saved = ka_save_image($tmpPath);
    if (isset($saved['error'])) { return array('error' => $saved['error'], 'code' => 400); }
    $ai = ka_ai_parse($saved['name']);
    if (isset($ai['error'])) {
        @unlink(KAIMA_DATA_DIR . '/img/' . $saved['name']);
        return array('error' => $ai['error'], 'code' => 502);
    }
    $v = ka_validate($ai['data']);
    $match = ka_match(ka_pdo(), $v['fields']);
    if ($match['person_id'] && ($match['prev_title'] !== $v['fields']['title'] || $match['prev_department'] !== $v['fields']['department'])) {
        $v['warns'][] = '既存人物と一致: 承認すると役職/部署を更新し、旧情報を変遷履歴に残します('
            . $match['prev_department'] . ' ' . $match['prev_title'] . ' → ' . $v['fields']['department'] . ' ' . $v['fields']['title'] . ')';
    } elseif ($match['person_id']) {
        $v['warns'][] = '既存人物と一致: 接点(名刺)を追加します';
    }
    $draftId = ka_draft_create('ai', $saved['name'], $v['fields'], $v['warns'], $match, $holder, $place, $date);
    return array('draft_id' => $draftId, 'warns' => $v['warns']);
}

/* ================= 承認(台帳へ反映) ================= */

function ka_draft_approve($actor, $draftId, $edited, $decidedBy) {
    ka_assert($actor, 'draft.approve');
    $pdo = ka_pdo();
    $st = $pdo->prepare("SELECT * FROM drafts WHERE id=? AND status='pending'");
    $st->execute(array($draftId));
    $draft = $st->fetch(PDO::FETCH_ASSOC);
    if (!$draft) { return array('error' => 'この下書きは既に処理済みか、存在しません', 'code' => 409); }

    // 人の編集値を再検証(下書き作成時と同じ決定的検証を通す)
    $v = ka_validate($edited);
    $f = $v['fields'];
    if ($f['name'] === '') { return array('error' => '氏名は必須です', 'code' => 422); }
    if ($f['company'] === '') { return array('error' => '会社名は必須です(個人の場合は屋号や「個人」を入力)', 'code' => 422); }
    $match = ka_match($pdo, $f);   // 承認時点のDB状態で照合し直す

    $pdo->beginTransaction();
    try {
        if ($match['company_id']) { $cid = $match['company_id']; }
        else {
            $pdo->prepare('INSERT INTO companies(name,norm,created_at) VALUES(?,?,?)')
                ->execute(array($f['company'], ka_norm($f['company']), ka_now()));
            $cid = (int)$pdo->lastInsertId();
        }
        if ($match['person_id']) {
            $pid = $match['person_id'];
            if ($match['prev_title'] !== $f['title'] || $match['prev_department'] !== $f['department']) {
                $pdo->prepare('INSERT INTO career(person_id,department,title,noted_at) VALUES(?,?,?,?)')
                    ->execute(array($pid, $match['prev_department'], $match['prev_title'], ka_now()));
            }
            $pdo->prepare('UPDATE people SET kana=CASE WHEN ?<>\'\' THEN ? ELSE kana END,
                department=?, title=?,
                tel=CASE WHEN ?<>\'\' THEN ? ELSE tel END,
                mobile=CASE WHEN ?<>\'\' THEN ? ELSE mobile END,
                email=CASE WHEN ?<>\'\' THEN ? ELSE email END,
                url=CASE WHEN ?<>\'\' THEN ? ELSE url END,
                zip=CASE WHEN ?<>\'\' THEN ? ELSE zip END,
                address=CASE WHEN ?<>\'\' THEN ? ELSE address END,
                updated_at=? WHERE id=?')
                ->execute(array($f['kana'], $f['kana'], $f['department'], $f['title'],
                    $f['tel'], $f['tel'], $f['mobile'], $f['mobile'], $f['email'], $f['email'],
                    $f['url'], $f['url'], $f['zip'], $f['zip'], $f['address'], $f['address'],
                    ka_now(), $pid));
        } else {
            $pdo->prepare('INSERT INTO people(company_id,name,norm,kana,department,title,tel,mobile,email,url,zip,address,created_at,updated_at)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute(array($cid, $f['name'], ka_norm($f['name']), $f['kana'], $f['department'], $f['title'],
                    $f['tel'], $f['mobile'], $f['email'], $f['url'], $f['zip'], $f['address'], ka_now(), ka_now()));
            $pid = (int)$pdo->lastInsertId();
        }
        $pdo->prepare('INSERT INTO cards(person_id,image,holder,place,exchanged_on,created_at) VALUES(?,?,?,?,?,?)')
            ->execute(array($pid, $draft['image'], $draft['holder'], $draft['place'], $draft['exchanged_on'], ka_now()));
        $pdo->prepare("UPDATE drafts SET status='approved', decided_by=?, decided_at=?, fields_json=? WHERE id=?")
            ->execute(array($decidedBy, ka_now(), json_encode($f, JSON_UNESCAPED_UNICODE), $draftId));
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        return array('error' => '反映に失敗しました: ' . $e->getMessage(), 'code' => 500);
    }
    ka_audit($actor, 'draft.approve', 'draft#' . $draftId . '→person#' . $pid . ' by=' . $decidedBy);
    return array('person_id' => $pid, 'warns' => $v['warns']);
}

function ka_draft_reject($actor, $draftId, $decidedBy) {
    ka_assert($actor, 'draft.reject');
    $pdo = ka_pdo();
    $st = $pdo->prepare("UPDATE drafts SET status='rejected', decided_by=?, decided_at=? WHERE id=? AND status='pending'");
    $st->execute(array($decidedBy, ka_now(), $draftId));
    if ($st->rowCount() < 1) { return array('error' => 'この下書きは既に処理済みです', 'code' => 409); }
    ka_audit($actor, 'draft.reject', 'draft#' . $draftId . ' by=' . $decidedBy);
    return array('ok' => 1);
}

/* ================= レート制限(AI費用の防波堤) ================= */

function ka_rate_ok($ip, $n = 1) {
    $file = KAIMA_DATA_DIR . '/rate.json';
    if (!is_dir(KAIMA_DATA_DIR)) { @mkdir(KAIMA_DATA_DIR, 0755, true); }
    $key = substr(hash('sha256', $ip . '|kaima'), 0, 16);
    $now = time();
    $fp = fopen($file, 'c+');
    if (!$fp) { return true; }
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $all = $raw ? json_decode($raw, true) : array();
    if (!is_array($all)) { $all = array(); }
    $hits = isset($all[$key]) ? $all[$key] : array();
    $hits = array_values(array_filter($hits, function ($t) use ($now) { return $t > $now - 3600; }));
    $ok = (count($hits) + $n) <= ka_rate_max();
    if ($ok) {
        for ($i = 0; $i < $n; $i++) { $hits[] = $now; }
        $all[$key] = $hits;
        foreach ($all as $k => $ts) {
            $ts = array_values(array_filter($ts, function ($t) use ($now) { return $t > $now - 3600; }));
            if ($ts) { $all[$k] = $ts; } else { unset($all[$k]); }
        }
        ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($all));
    }
    flock($fp, LOCK_UN); fclose($fp);
    return $ok;
}

/* ================= 認証 ================= */

function ka_session_start() {
    if (session_status() === PHP_SESSION_NONE) { session_name('KAIMASESSID'); session_start(); }
}
function ka_logged_in() { ka_session_start(); return !empty($_SESSION['ka_ok']); }
function ka_try_login($pw) {
    if (KAIMA_PASSWORD_HASH !== '') { return password_verify($pw, KAIMA_PASSWORD_HASH); }
    if (KAIMA_PASSWORD !== '') { return hash_equals(KAIMA_PASSWORD, $pw); }
    return false;
}
function ka_csrf() {
    ka_session_start();
    if (empty($_SESSION['ka_csrf'])) { $_SESSION['ka_csrf'] = bin2hex(random_bytes(16)); }
    return $_SESSION['ka_csrf'];
}
function ka_csrf_ok() {
    ka_session_start();
    $t = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    return !empty($_SESSION['ka_csrf']) && hash_equals($_SESSION['ka_csrf'], $t);
}

/* ================= 画像配信(要ログイン。名刺は個人情報) ================= */

if (isset($_GET['img'])) {
    if (!ka_logged_in()) { http_response_code(403); exit('forbidden'); }
    $name = basename((string)$_GET['img']);
    $path = KAIMA_DATA_DIR . '/img/' . $name;
    if (!is_file($path)) { http_response_code(404); exit('not found'); }
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=3600');
    readfile($path);
    exit;
}

/* ================= 外部API ================= */

if (isset($_GET['api'])) {
    $api = (string)$_GET['api'];
    if ($api === 'health') { ka_json_out(200, array('ok' => 1, 'app' => 'kaima')); }
    if ($api === 'upload') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { ka_json_out(405, array('error' => 'POSTで送信してください')); }
        $tok = isset($_SERVER['HTTP_X_KAIMA_TOKEN']) ? $_SERVER['HTTP_X_KAIMA_TOKEN'] : '';
        if (KAIMA_API_TOKEN === '' || !hash_equals(KAIMA_API_TOKEN, $tok)) {
            ka_json_out(401, array('error' => 'APIトークンが違います(X-KAIMA-TOKENヘッダ)'));
        }
        if (empty($_FILES['image']['tmp_name'])) { ka_json_out(400, array('error' => 'image(multipart)を添付してください')); }
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        if (!ka_rate_ok($ip)) { ka_json_out(429, array('error' => '利用が集中しています。1時間ほど空けてください')); }
        $r = ka_pipeline('api', $_FILES['image']['tmp_name'],
            isset($_POST['holder']) ? (string)$_POST['holder'] : '',
            isset($_POST['place']) ? (string)$_POST['place'] : '',
            isset($_POST['date']) ? (string)$_POST['date'] : '');
        if (isset($r['error'])) { ka_json_out($r['code'], array('error' => $r['error'])); }
        ka_json_out(200, array('draft_id' => $r['draft_id'], 'warns' => $r['warns'],
            'note' => '下書きを作成しました。台帳への反映には管理画面での承認が必要です'));
    }
    ka_json_out(404, array('error' => '不明なAPIです'));
}

/* ================= 画面 ================= */

ka_session_start();
$login_error = '';
if (isset($_POST['ka_pw'])) {
    if (ka_try_login((string)$_POST['ka_pw'])) { $_SESSION['ka_ok'] = 1; header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit; }
    $login_error = 'パスワードが違います';
}
if (isset($_GET['logout'])) { unset($_SESSION['ka_ok']); header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit; }

$SELF = strtok($_SERVER['REQUEST_URI'], '?');

/* ---- CSVエクスポート ---- */
if (ka_logged_in() && isset($_GET['export']) && $_GET['export'] === 'csv') {
    $pdo = ka_pdo();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="kaima_people_' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, array('会社名', '氏名', 'かな', '部署', '役職', 'TEL', '携帯', 'メール', 'URL', '郵便番号', '住所', '登録日'));
    $q = $pdo->query('SELECT c.name co, p.* FROM people p JOIN companies c ON c.id=p.company_id ORDER BY c.norm, p.id');
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        fputcsv($out, array($r['co'], $r['name'], $r['kana'], $r['department'], $r['title'],
            $r['tel'], $r['mobile'], $r['email'], $r['url'], $r['zip'], $r['address'], substr($r['created_at'], 0, 10)));
    }
    fclose($out);
    exit;
}

/* ---- 画面POST ---- */
$flash = ''; $flash_err = ''; $flash_warns = array();
if (ka_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act'])) {
    if (!ka_csrf_ok()) {
        $flash_err = 'ページの有効期限が切れました。再読み込みしてやり直してください';
    } else {
        $act = (string)$_POST['act'];
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        if ($act === 'upload') {
            $files = isset($_FILES['cards']) ? $_FILES['cards'] : null;
            $n = $files && is_array($files['tmp_name']) ? count(array_filter($files['tmp_name'])) : 0;
            if ($n < 1) { $flash_err = '名刺画像を選択してください'; }
            elseif ($n > (int)KAIMA_MAX_FILES) { $flash_err = '一度に' . (int)KAIMA_MAX_FILES . '枚までです'; }
            elseif (!ka_rate_ok($ip, $n)) { $flash_err = 'AI解析の利用が集中しています。1時間ほど空けてください'; }
            else {
                $okN = 0; $ngN = 0;
                for ($i = 0; $i < count($files['tmp_name']); $i++) {
                    if (empty($files['tmp_name'][$i])) { continue; }
                    $r = ka_pipeline('user', $files['tmp_name'][$i],
                        isset($_POST['holder']) ? (string)$_POST['holder'] : '',
                        isset($_POST['place']) ? (string)$_POST['place'] : '',
                        isset($_POST['date']) ? (string)$_POST['date'] : '');
                    if (isset($r['error'])) { $ngN++; $flash_err = $r['error']; }
                    else { $okN++; $flash_warns = array_merge($flash_warns, $r['warns']); }
                }
                if ($okN) { $flash = $okN . '枚をAIが解析し、下書きに登録しました。内容を確認して承認してください'; }
            }
        } elseif ($act === 'approve') {
            $r = ka_draft_approve('user', (int)$_POST['draft_id'], $_POST, 'admin');
            if (isset($r['error'])) { $flash_err = $r['error']; }
            else { $flash = '台帳に反映しました'; $flash_warns = $r['warns']; }
        } elseif ($act === 'reject') {
            $r = ka_draft_reject('user', (int)$_POST['draft_id'], 'admin');
            if (isset($r['error'])) { $flash_err = $r['error']; } else { $flash = '下書きを却下しました(画像も台帳にも残りません)'; }
        }
    }
}

$page = isset($_GET['p']) ? (string)$_GET['p'] : 'home';
$pdo = ka_logged_in() ? ka_pdo() : null;
$pendingN = $pdo ? (int)$pdo->query("SELECT COUNT(*) FROM drafts WHERE status='pending'")->fetchColumn() : 0;
?><!doctype html>
<html lang="ja"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo ka_h(KAIMA_TITLE); ?></title>
<meta name="robots" content="noindex">
<style>
:root { --brand:<?php echo ka_h(KAIMA_BRAND_COLOR); ?>; --ink:#2a2433; --line:#e0d8e6; --paper:#f7f4f9; --ok:#2f7d4f; --warn:#a06a10; --err:#a33; }
* { box-sizing:border-box; }
body { margin:0; background:var(--paper); color:var(--ink); font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif; font-size:14.5px; }
a { color:var(--brand); }
.wrap { max-width:980px; margin:0 auto; padding:0 14px 40px; }
header.top { background:#fff; border-bottom:2px solid var(--brand); margin:0 -14px 16px; padding:12px 16px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
header.top h1 { margin:0; font-size:17px; }
header.top h1 a { color:var(--brand); text-decoration:none; }
.demo-badge { background:#fff3d8; border:1px solid #e8c87a; color:#7a5b12; font-size:11px; font-weight:700; border-radius:6px; padding:2px 8px; }
nav.tabs { display:flex; gap:4px; margin-left:auto; flex-wrap:wrap; }
nav.tabs a { text-decoration:none; padding:6px 12px; border-radius:8px; font-size:13px; color:#4a3f56; }
nav.tabs a.on { background:var(--brand); color:#fff; }
.badge { background:var(--err); color:#fff; border-radius:999px; font-size:11px; padding:1px 7px; margin-left:4px; }
.card { background:#fff; border:1px solid var(--line); border-radius:12px; padding:16px 18px; margin-bottom:14px; }
.card h2 { margin:0 0 10px; font-size:15.5px; color:var(--brand); }
.btn { background:var(--brand); color:#fff; border:none; border-radius:8px; padding:9px 18px; font-size:14px; font-weight:700; cursor:pointer; }
.btn.ok { background:var(--ok); } .btn.ng { background:#8a8f94; }
.flash { border-radius:10px; padding:10px 14px; margin-bottom:14px; font-size:13.5px; }
.flash.ok { background:#e8f5ec; border:1px solid #b5d9c2; color:#215c3c; }
.flash.err { background:#fdeaea; border:1px solid #eab6b6; color:var(--err); }
.flash.warn { background:#fff7e5; border:1px solid #ecd9a0; color:var(--warn); }
.up input[type=file] { width:100%; padding:22px 12px; border:2px dashed var(--line); border-radius:12px; background:#faf8fc; font-size:14px; }
.uprow { display:flex; gap:10px; flex-wrap:wrap; margin-top:10px; }
.uprow label { font-size:12.5px; color:#5c5168; display:block; margin-bottom:3px; }
.uprow input, .uprow select { padding:8px 10px; border:1.5px solid var(--line); border-radius:8px; font-size:14px; }
.draft { display:flex; gap:16px; flex-wrap:wrap; border:1px solid #e8c87a; background:#fffdf5; }
.draft .imgside { flex:1; min-width:260px; }
.draft .imgside img { width:100%; border-radius:8px; border:1px solid var(--line); }
.draft .formside { flex:1.2; min-width:280px; }
.fgrid { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
.fgrid .full { grid-column:1 / -1; }
.fgrid label { font-size:11.5px; color:#5c5168; display:block; margin-bottom:2px; }
.fgrid input { width:100%; padding:7px 9px; border:1.5px solid var(--line); border-radius:7px; font-size:13.5px; }
.warns { font-size:12.5px; color:var(--warn); margin:8px 0; line-height:1.6; }
table.list { width:100%; border-collapse:collapse; font-size:13.5px; }
table.list th, table.list td { text-align:left; padding:8px 10px; border-bottom:1px solid var(--line); vertical-align:top; }
table.list th { color:#5c5168; font-size:12px; }
.muted { color:#7d7288; font-size:12.5px; }
.pill { display:inline-block; font-size:11.5px; border-radius:999px; padding:2px 10px; background:#efe9f3; color:#4a3f56; }
.gate { max-width:380px; margin:80px auto; background:#fff; border:1px solid var(--line); border-radius:12px; padding:28px; text-align:center; }
.gate input { width:100%; padding:10px; font-size:15px; border:1.5px solid var(--line); border-radius:8px; margin:12px 0; }
.gate button { width:100%; background:var(--brand); color:#fff; border:none; border-radius:8px; padding:11px; font-size:15px; font-weight:700; cursor:pointer; }
.search { display:flex; gap:8px; margin-bottom:12px; }
.search input { flex:1; padding:9px 12px; border:1.5px solid var(--line); border-radius:8px; font-size:14px; }
.foot { text-align:center; font-size:11px; color:#948aa0; padding:18px 0 6px; }
form.inline { display:inline; }
</style></head>
<body>
<?php if (!ka_logged_in()): ?>
<div class="gate">
  <h1 style="font-size:18px;color:var(--brand);margin:0 0 4px"><?php echo ka_h(KAIMA_TITLE); ?></h1>
  <p class="muted">名刺を撮るだけ。読み取りはAI、確定はあなた。</p>
  <?php if ($login_error): ?><p style="color:var(--err);font-size:13px"><?php echo ka_h($login_error); ?></p><?php endif; ?>
  <form method="post"><input type="password" name="ka_pw" placeholder="パスワード" autofocus><button>ログイン</button></form>
  <?php if (KAIMA_DEMO): ?><p class="muted">デモ環境です。データは定期的に初期化されます。実在の個人情報はアップしないでください。</p><?php endif; ?>
</div>
<?php else: ?>
<div class="wrap">
<header class="top">
  <h1><a href="<?php echo ka_h($SELF); ?>"><?php echo ka_h(KAIMA_TITLE); ?></a></h1>
  <?php if (KAIMA_DEMO): ?><span class="demo-badge">デモ</span><?php endif; ?>
  <nav class="tabs">
    <?php
    $tabs = array('home' => '取り込み', 'queue' => '承認', 'people' => '人物台帳', 'companies' => '会社・接点', 'history' => '履歴');
    foreach ($tabs as $k => $label) {
        $cls = $page === $k ? 'on' : '';
        $badge = ($k === 'queue' && $pendingN) ? '<span class="badge">' . $pendingN . '</span>' : '';
        echo '<a class="' . $cls . '" href="' . ka_h($SELF) . '?p=' . $k . '">' . ka_h($label) . $badge . '</a>';
    }
    ?>
    <a href="<?php echo ka_h($SELF); ?>?logout=1">ログアウト</a>
  </nav>
</header>

<?php if ($flash): ?><div class="flash ok"><?php echo ka_h($flash); ?></div><?php endif; ?>
<?php if ($flash_err): ?><div class="flash err"><?php echo ka_h($flash_err); ?></div><?php endif; ?>
<?php if ($flash_warns): ?><div class="flash warn">確認ポイント: <?php echo ka_h(implode(' / ', array_unique($flash_warns))); ?></div><?php endif; ?>

<?php if ($page === 'home'): ?>
<div class="card up">
  <h2>名刺を取り込む</h2>
  <p class="muted" style="margin:0 0 10px">スマホならカメラが起動します。AIが読み取って「下書き」に登録します(台帳には承認するまで載りません)。一度に<?php echo (int)KAIMA_MAX_FILES; ?>枚まで。</p>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?php echo ka_h(ka_csrf()); ?>">
    <input type="hidden" name="act" value="upload">
    <input type="file" name="cards[]" accept="image/*" capture="environment" multiple required>
    <div class="uprow">
      <div><label>誰の名刺交換？</label>
        <?php $holders = ka_holders(); if ($holders): ?>
        <select name="holder"><?php foreach ($holders as $h0): ?><option><?php echo ka_h($h0); ?></option><?php endforeach; ?></select>
        <?php else: ?><input name="holder" placeholder="例: 山田" style="width:120px"><?php endif; ?>
      </div>
      <div><label>どこで(任意)</label><input name="place" placeholder="例: 〇〇展示会" style="width:170px"></div>
      <div><label>交換日</label><input type="date" name="date" value="<?php echo date('Y-m-d'); ?>"></div>
      <div style="align-self:flex-end"><button class="btn" type="submit">AIで解析する</button></div>
    </div>
  </form>
</div>
<div class="card">
  <h2>いまの台帳</h2>
  <?php
  $nC = (int)$pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn();
  $nP = (int)$pdo->query('SELECT COUNT(*) FROM people')->fetchColumn();
  $nK = (int)$pdo->query('SELECT COUNT(*) FROM cards')->fetchColumn();
  ?>
  <p><span class="pill">会社 <?php echo $nC; ?></span> <span class="pill">人物 <?php echo $nP; ?></span> <span class="pill">名刺(接点) <?php echo $nK; ?></span>
  <?php if ($pendingN): ?> <a href="<?php echo ka_h($SELF); ?>?p=queue">承認待ち <?php echo $pendingN; ?>件 →</a><?php endif; ?></p>
  <p class="muted">エクスポート: <a href="<?php echo ka_h($SELF); ?>?export=csv">人物台帳CSV</a></p>
</div>

<?php elseif ($page === 'queue'): ?>
<?php
$drafts = $pdo->query("SELECT * FROM drafts WHERE status='pending' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if (!$drafts): ?>
<div class="card"><p class="muted">承認待ちの下書きはありません。<a href="<?php echo ka_h($SELF); ?>">取り込みへ</a></p></div>
<?php endif; ?>
<?php foreach ($drafts as $d):
    $f = json_decode($d['fields_json'], true) ?: array();
    $warns = json_decode($d['warns_json'], true) ?: array();
?>
<div class="card draft">
  <div class="imgside">
    <div class="muted">下書き#<?php echo (int)$d['id']; ?> ・ <?php echo ka_h($d['created_at']); ?> ・ <?php echo ka_h($d['holder']); ?><?php echo $d['place'] !== '' ? ' @' . ka_h($d['place']) : ''; ?></div>
    <img src="<?php echo ka_h($SELF); ?>?img=<?php echo urlencode($d['image']); ?>" alt="名刺原本" loading="lazy">
    <div class="muted">↑ 原本と見比べて、右の内容を確認・修正してください</div>
  </div>
  <div class="formside">
    <form method="post">
      <input type="hidden" name="csrf" value="<?php echo ka_h(ka_csrf()); ?>">
      <input type="hidden" name="act" value="approve">
      <input type="hidden" name="draft_id" value="<?php echo (int)$d['id']; ?>">
      <div class="fgrid">
        <div class="full"><label>会社名 *</label><input name="company" value="<?php echo ka_h($f['company']); ?>"></div>
        <div><label>氏名 *</label><input name="name" value="<?php echo ka_h($f['name']); ?>"></div>
        <div><label>かな</label><input name="kana" value="<?php echo ka_h($f['kana']); ?>"></div>
        <div><label>部署</label><input name="department" value="<?php echo ka_h($f['department']); ?>"></div>
        <div><label>役職</label><input name="title" value="<?php echo ka_h($f['title']); ?>"></div>
        <div><label>TEL</label><input name="tel" value="<?php echo ka_h($f['tel']); ?>"></div>
        <div><label>携帯</label><input name="mobile" value="<?php echo ka_h($f['mobile']); ?>"></div>
        <div class="full"><label>メール</label><input name="email" value="<?php echo ka_h($f['email']); ?>"></div>
        <div class="full"><label>URL</label><input name="url" value="<?php echo ka_h($f['url']); ?>"></div>
        <div><label>郵便番号</label><input name="zip" value="<?php echo ka_h($f['zip']); ?>"></div>
        <div class="full"><label>住所</label><input name="address" value="<?php echo ka_h($f['address']); ?>"></div>
      </div>
      <?php if ($warns): ?><div class="warns">⚠ <?php echo ka_h(implode("\n⚠ ", $warns)); ?></div><?php endif; ?>
      <div style="margin-top:10px">
        <button class="btn ok" type="submit">この内容で台帳に反映</button>
      </div>
    </form>
    <form class="inline" method="post" style="margin-top:6px;display:block">
      <input type="hidden" name="csrf" value="<?php echo ka_h(ka_csrf()); ?>">
      <input type="hidden" name="act" value="reject">
      <input type="hidden" name="draft_id" value="<?php echo (int)$d['id']; ?>">
      <button class="btn ng" type="submit">却下(破棄)</button>
    </form>
  </div>
</div>
<?php endforeach; ?>

<?php elseif ($page === 'people'): ?>
<?php $pidQ = isset($_GET['id']) ? (int)$_GET['id'] : 0; ?>
<?php if ($pidQ):
    $st = $pdo->prepare('SELECT p.*, c.name co, c.id cid FROM people p JOIN companies c ON c.id=p.company_id WHERE p.id=?');
    $st->execute(array($pidQ)); $p = $st->fetch(PDO::FETCH_ASSOC);
    if ($p): ?>
<div class="card">
  <h2><?php echo ka_h($p['name']); ?> <span class="muted"><?php echo ka_h($p['kana']); ?></span></h2>
  <p><a href="<?php echo ka_h($SELF); ?>?p=companies&id=<?php echo (int)$p['cid']; ?>"><?php echo ka_h($p['co']); ?></a> ／ <?php echo ka_h($p['department']); ?> <?php echo ka_h($p['title']); ?></p>
  <table class="list">
    <?php foreach (array('tel' => 'TEL', 'mobile' => '携帯', 'email' => 'メール', 'url' => 'URL', 'zip' => '〒', 'address' => '住所') as $k => $lab): if ($p[$k] === '') { continue; } ?>
    <tr><th style="width:80px"><?php echo $lab; ?></th><td><?php echo ka_h($p[$k]); ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php
  $hist = $pdo->prepare('SELECT * FROM career WHERE person_id=? ORDER BY id DESC');
  $hist->execute(array($pidQ)); $hist = $hist->fetchAll(PDO::FETCH_ASSOC);
  if ($hist): ?>
  <h3 style="font-size:13.5px;margin:14px 0 6px">役職の変遷</h3>
  <table class="list"><tr><th>時期(記録日)</th><th>部署・役職(当時)</th></tr>
  <?php foreach ($hist as $hh): ?>
  <tr><td class="muted"><?php echo ka_h(substr($hh['noted_at'], 0, 10)); ?>まで</td><td><?php echo ka_h(trim($hh['department'] . ' ' . $hh['title'])); ?></td></tr>
  <?php endforeach; ?></table>
  <?php endif; ?>
  <h3 style="font-size:13.5px;margin:14px 0 6px">名刺・接点</h3>
  <table class="list"><tr><th>交換日</th><th>誰が</th><th>どこで</th><th>原本</th></tr>
  <?php $cs = $pdo->prepare('SELECT * FROM cards WHERE person_id=? ORDER BY exchanged_on DESC'); $cs->execute(array($pidQ));
  foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $cc): ?>
  <tr><td><?php echo ka_h($cc['exchanged_on']); ?></td><td><?php echo ka_h($cc['holder']); ?></td><td><?php echo ka_h($cc['place']); ?></td>
      <td><?php echo $cc['image'] !== '' ? '<a href="' . ka_h($SELF) . '?img=' . urlencode($cc['image']) . '" target="_blank">画像</a>' : '—'; ?></td></tr>
  <?php endforeach; ?></table>
  <p><a href="<?php echo ka_h($SELF); ?>?p=people">← 人物台帳へ</a></p>
</div>
    <?php endif; ?>
<?php else: ?>
<div class="card">
  <h2>人物台帳</h2>
  <form class="search" method="get">
    <input type="hidden" name="p" value="people">
    <input name="q" value="<?php echo ka_h(isset($_GET['q']) ? $_GET['q'] : ''); ?>" placeholder="氏名・会社・部署・役職で検索">
    <button class="btn" type="submit">検索</button>
  </form>
  <table class="list"><tr><th>氏名</th><th>会社</th><th>部署・役職</th><th>連絡先</th></tr>
  <?php
  $q = trim(isset($_GET['q']) ? (string)$_GET['q'] : '');
  $sql = 'SELECT p.*, c.name co FROM people p JOIN companies c ON c.id=p.company_id';
  $args = array();
  if ($q !== '') {
      $sql .= ' WHERE p.name LIKE ? OR p.kana LIKE ? OR c.name LIKE ? OR p.department LIKE ? OR p.title LIKE ?';
      $like = '%' . $q . '%';
      $args = array($like, $like, $like, $like, $like);
  }
  $sql .= ' ORDER BY p.updated_at DESC LIMIT 200';
  $st = $pdo->prepare($sql); $st->execute($args);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r): ?>
  <tr><td><a href="<?php echo ka_h($SELF); ?>?p=people&id=<?php echo (int)$r['id']; ?>"><?php echo ka_h($r['name']); ?></a></td>
      <td><?php echo ka_h($r['co']); ?></td>
      <td><?php echo ka_h(trim($r['department'] . ' ' . $r['title'])); ?></td>
      <td class="muted"><?php echo ka_h($r['email'] !== '' ? $r['email'] : ($r['mobile'] !== '' ? $r['mobile'] : $r['tel'])); ?></td></tr>
  <?php endforeach; ?></table>
</div>
<?php endif; ?>

<?php elseif ($page === 'companies'): ?>
<?php $cidQ = isset($_GET['id']) ? (int)$_GET['id'] : 0; ?>
<?php if ($cidQ):
    $st = $pdo->prepare('SELECT * FROM companies WHERE id=?'); $st->execute(array($cidQ));
    $co = $st->fetch(PDO::FETCH_ASSOC);
    if ($co): ?>
<div class="card">
  <h2><?php echo ka_h($co['name']); ?></h2>
  <?php
  $who = $pdo->prepare('SELECT DISTINCT k.holder FROM cards k JOIN people p ON p.id=k.person_id WHERE p.company_id=? AND k.holder<>\'\'');
  $who->execute(array($cidQ));
  $who = $who->fetchAll(PDO::FETCH_COLUMN);
  ?>
  <p><b>この会社と接点がある社内メンバー:</b> <?php echo $who ? ka_h(implode('、', $who)) : '記録なし'; ?></p>
  <table class="list"><tr><th>氏名</th><th>部署・役職</th><th>接点(名刺)</th><th>最終交換日</th></tr>
  <?php
  $ps = $pdo->prepare('SELECT p.*, (SELECT COUNT(*) FROM cards WHERE person_id=p.id) nk,
      (SELECT MAX(exchanged_on) FROM cards WHERE person_id=p.id) lk
      FROM people p WHERE p.company_id=? ORDER BY p.updated_at DESC');
  $ps->execute(array($cidQ));
  foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $r): ?>
  <tr><td><a href="<?php echo ka_h($SELF); ?>?p=people&id=<?php echo (int)$r['id']; ?>"><?php echo ka_h($r['name']); ?></a></td>
      <td><?php echo ka_h(trim($r['department'] . ' ' . $r['title'])); ?></td>
      <td><?php echo (int)$r['nk']; ?>枚</td><td class="muted"><?php echo ka_h($r['lk'] ?: '—'); ?></td></tr>
  <?php endforeach; ?></table>
  <p><a href="<?php echo ka_h($SELF); ?>?p=companies">← 会社一覧へ</a></p>
</div>
    <?php endif; ?>
<?php else: ?>
<div class="card">
  <h2>会社・接点 <span class="muted" style="font-size:12px">「この会社、うちの誰が知ってる?」に答える台帳</span></h2>
  <table class="list"><tr><th>会社名</th><th>人物</th><th>名刺</th><th>接点がある社内メンバー</th></tr>
  <?php
  $rows = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM people WHERE company_id=c.id) np,
      (SELECT COUNT(*) FROM cards k JOIN people p ON p.id=k.person_id WHERE p.company_id=c.id) nk
      FROM companies c ORDER BY c.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $r):
      $who = $pdo->prepare('SELECT DISTINCT k.holder FROM cards k JOIN people p ON p.id=k.person_id WHERE p.company_id=? AND k.holder<>\'\'');
      $who->execute(array((int)$r['id']));
      $who = $who->fetchAll(PDO::FETCH_COLUMN);
  ?>
  <tr><td><a href="<?php echo ka_h($SELF); ?>?p=companies&id=<?php echo (int)$r['id']; ?>"><?php echo ka_h($r['name']); ?></a></td>
      <td><?php echo (int)$r['np']; ?></td><td><?php echo (int)$r['nk']; ?></td>
      <td class="muted"><?php echo ka_h(implode('、', $who)); ?></td></tr>
  <?php endforeach; ?></table>
</div>
<?php endif; ?>

<?php elseif ($page === 'history'): ?>
<div class="card">
  <h2>監査ログ(直近60件)</h2>
  <p class="muted" style="margin:0 0 8px">AIは下書きの作成しかできません。台帳への反映は、すべて人の承認として記録されます。</p>
  <table class="list"><tr><th>日時</th><th>誰が</th><th>操作</th><th>詳細</th></tr>
  <?php foreach ($pdo->query('SELECT * FROM audit ORDER BY id DESC LIMIT 60')->fetchAll(PDO::FETCH_ASSOC) as $a): ?>
  <tr><td class="muted"><?php echo ka_h($a['ts']); ?></td><td><?php echo ka_h($a['actor']); ?></td>
      <td><?php echo ka_h($a['action']); ?></td><td class="muted"><?php echo ka_h($a['detail']); ?></td></tr>
  <?php endforeach; ?></table>
</div>
<?php endif; ?>

<div class="foot">Kurage AI Meishi Analysis — 読み取りはAI、確定はあなた。<?php if (KAIMA_DEMO): ?>(デモ環境・AI解析は1時間<?php echo ka_rate_max(); ?>枚まで)<?php endif; ?></div>
</div>
<?php endif; ?>
</body></html>

<?php
/**
 * kaima の自己テスト。デプロイ前に実行する:  php scripts/check_kaima.php
 * AIは呼ばない(AI出力はフィクスチャで与え、関門・検証・名寄せ・変遷・反映を機械検証する)。
 */
error_reporting(E_ALL);
$root = dirname(__DIR__);

$pass = 0; $fail = 0;
function ok($name, $cond, $note = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok  $name\n"; }
    else { $fail++; echo "  NG  $name" . ($note ? " ($note)" : '') . "\n"; }
}

echo "== kaima check ==\n";

ok('本体 kaima.php が存在', is_file($root . '/public/kaima.php'));
$lint = (string)shell_exec('php -l ' . escapeshellarg($root . '/public/kaima.php') . ' 2>&1');
ok('本体のPHP構文', strpos($lint, 'No syntax errors') !== false, trim($lint));
ok('設定example が存在', is_file($root . '/public/kaima_config.php.example'));
ok('kaima_data保護(.htaccess deny)', trim((string)file_get_contents($root . '/public/kaima_data/.htaccess')) === 'Require all denied');

// ---- テスト用設定 ----
$tmp = sys_get_temp_dir() . '/kaima_test_' . getmypid();
@mkdir($tmp, 0755, true);
define('KAIMA_TITLE', 'テスト');
define('KAIMA_PASSWORD', 'testpw');
define('KAIMA_API_TOKEN', 'testtoken');
define('KAIMA_API_KEY', 'sk-not-used');
define('KAIMA_HOLDERS', '山田,佐藤');
define('KAIMA_RATE_PER_HOUR', 3);
define('KAIMA_DEMO', false);
define('KAIMA_DATA_DIR', $tmp);

$src = file_get_contents($root . '/public/kaima.php');
$cut = strpos($src, '/* ================= 画像配信');
ok('関数部の切り出しマーカー', $cut !== false);
$funcs = substr($src, 0, $cut);
$funcs = preg_replace('/\$cfg = __DIR__.*?require \$cfg;/s', '', str_replace('<?php', '', $funcs), 1);
eval($funcs);

// ---- 関門 ----
ok('関門: aiは下書き作成のみ', ka_can('ai', 'draft.create') && !ka_can('ai', 'draft.approve') && !ka_can('ai', 'card.upload'));
ok('関門: apiは取り込みのみ', ka_can('api', 'card.upload') && !ka_can('api', 'draft.approve'));
ok('関門: userは承認・却下可', ka_can('user', 'draft.approve') && ka_can('user', 'draft.reject'));
ok('関門: 未知actorは全拒否', !ka_can('root', 'draft.approve'));
$denied = false;
try { ka_assert('ai', 'draft.approve'); } catch (KaDenied $e) { $denied = true; }
ok('関門: 違反は例外で止まる', $denied);

// ---- 正規化 ----
ok('会社名正規化: 全半角・空白・中黒吸収', ka_norm('株式会社ＡＢＣ・商事 ') === ka_norm('株式会社abc商事'));
ok('電話正規化: 全角→半角・記号除去', ka_tel('０５２（９５１）８８４２') === '052951' . '8842' || ka_tel('０５２-９５１-８８４２') === '052-951-8842');

// ---- 決定的検証 ----
$v = ka_validate(array('name' => '', 'company' => 'テスト社', 'tel' => '052-12', 'zip' => '４６０-０００８',
    'email' => 'x@invalid-domain-kaima-test-zzz.example', 'url' => 'www.example.com'));
ok('検証: 氏名欠落は警告', (bool)array_filter($v['warns'], function ($w) { return strpos($w, '氏名') !== false; }));
ok('検証: 電話桁不足は警告', (bool)array_filter($v['warns'], function ($w) { return strpos($w, '桁数') !== false; }));
ok('検証: 郵便番号を正規化', $v['fields']['zip'] === '460-0008');
ok('検証: メールDNS不在は警告', (bool)array_filter($v['warns'], function ($w) { return strpos($w, 'DNS') !== false; }));
ok('検証: URLにスキーム補完', $v['fields']['url'] === 'https://www.example.com');

// ---- 下書き→承認(新規作成パス) ----
$pdo = ka_pdo();
ok('SQLiteスキーマ初期化', (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn() >= 6);
$fields1 = array('company' => '株式会社燕製作所', 'department' => '第二営業部', 'title' => '課長',
    'name' => '高橋 圭一郎', 'kana' => 'たかはし けいいちろう', 'zip' => '460-0008',
    'address' => '名古屋市中区栄3-15-27', 'tel' => '052-951-8842', 'mobile' => '', 'email' => '', 'url' => '', 'fax' => '');
$d1 = ka_draft_create('ai', 'test1.jpg', $fields1, array(), ka_match($pdo, $fields1), '山田', '展示会', '2026-08-19');
ok('下書き作成(actor=ai)', $d1 > 0);
$r1 = ka_draft_approve('user', $d1, $fields1, 'tester');
ok('承認で人物が作成される', isset($r1['person_id']) && $r1['person_id'] > 0);
ok('会社が作成される', (int)$pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn() === 1);
ok('名刺(接点)が記録される', (int)$pdo->query("SELECT COUNT(*) FROM cards WHERE holder='山田' AND place='展示会'")->fetchColumn() === 1);
$r1b = ka_draft_approve('user', $d1, $fields1, 'tester');
ok('二重承認は409', isset($r1b['error']) && $r1b['code'] === 409);

// ---- 名寄せ+役職変遷(更新パス) ----
$fields2 = $fields1;
$fields2['company'] = '株式会社 燕製作所';   // 表記ゆれ
$fields2['name'] = '高橋圭一郎';             // 空白ゆれ
$fields2['title'] = '部長';                  // 昇進
$fields2['email'] = 'k.takahashi@example.com';
$m2 = ka_match($pdo, ka_validate($fields2)['fields']);
ok('名寄せ: 表記ゆれでも同一人物と判定', $m2['person_id'] === $r1['person_id']);
$d2 = ka_draft_create('ai', 'test2.jpg', $fields2, array(), $m2, '佐藤', '', '2026-08-19');
$r2 = ka_draft_approve('user', $d2, $fields2, 'tester');
ok('承認で既存人物を更新(新規作成しない)', isset($r2['person_id']) && $r2['person_id'] === $r1['person_id']
    && (int)$pdo->query('SELECT COUNT(*) FROM people')->fetchColumn() === 1);
$p = $pdo->query('SELECT * FROM people')->fetch(PDO::FETCH_ASSOC);
ok('役職が更新される', $p['title'] === '部長');
ok('空の値で既存を消さない(tel維持)', $p['tel'] === '052-951-8842');
ok('新しい値は反映(email)', $p['email'] === 'k.takahashi@example.com');
$hist = $pdo->query("SELECT * FROM career")->fetchAll(PDO::FETCH_ASSOC);
ok('役職変遷が履歴に残る', count($hist) === 1 && $hist[0]['title'] === '課長');
ok('接点が2件になる(山田・佐藤)', (int)$pdo->query('SELECT COUNT(DISTINCT holder) FROM cards')->fetchColumn() === 2);

// ---- 必須チェック・却下 ----
$d3 = ka_draft_create('ai', 'test3.jpg', array_merge($fields1, array('name' => '')), array(), array('company_id' => null, 'person_id' => null, 'prev_title' => '', 'prev_department' => ''), '', '', '2026-08-19');
$r3 = ka_draft_approve('user', $d3, array_merge($fields1, array('name' => '')), 'tester');
ok('氏名なしの承認は422', isset($r3['error']) && $r3['code'] === 422);
$r3b = ka_draft_reject('user', $d3, 'tester');
ok('却下できる', isset($r3b['ok']));
$r3c = ka_draft_reject('user', $d3, 'tester');
ok('二重却下は拒否', isset($r3c['error']));

// ---- 画像(GD) ----
if (!function_exists('imagecreatetruecolor')) {
    echo "  skip 画像テスト3件(この環境のPHP CLIにGDなし。本番サーバーではGD必須・デプロイ後にE2Eで実測すること)\n";
    goto after_gd;
}
$img = imagecreatetruecolor(400, 240);
imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
$tmpImg = $tmp . '/in.png';
imagepng($img, $tmpImg); imagedestroy($img);
$sv = ka_save_image($tmpImg);
ok('画像保存(png→jpg変換)', isset($sv['name']) && is_file($tmp . '/img/' . $sv['name']));
ok('AI用縮小base64が作れる', strlen(ka_image_b64($sv['name'])) > 1000);
$bad = $tmp . '/bad.txt'; file_put_contents($bad, 'not an image');
$sv2 = ka_save_image($bad);
ok('画像以外は拒否', isset($sv2['error']));
after_gd:

// ---- レート制限(上限3) ----
$okN = 0;
for ($i = 0; $i < 5; $i++) { if (ka_rate_ok('203.0.113.5')) { $okN++; } }
ok('レート制限: 上限で止まる', $okN === 3, "通過={$okN}");
ok('レート制限: 複数枚を一括計上', ka_rate_ok('203.0.113.6', 2) && !ka_rate_ok('203.0.113.6', 2));

// ---- 監査 ----
ok('監査ログが残る', (int)$pdo->query('SELECT COUNT(*) FROM audit')->fetchColumn() >= 5);
ok('監査: aiによる台帳書き込み記録が0件',
    (int)$pdo->query("SELECT COUNT(*) FROM audit WHERE actor='ai' AND action LIKE 'draft.approve%'")->fetchColumn() === 0);

// 後片付け
foreach (glob($tmp . '/img/*') as $f) { @unlink($f); }
@rmdir($tmp . '/img');
foreach (glob($tmp . '/*') as $f) { @unlink($f); }
@rmdir($tmp);

echo "\n結果: pass={$pass} fail={$fail}\n";
exit($fail ? 1 : 0);

# Kurage AI Meishi Analysis (kaima)

Kurage AI名刺解析システム。スマホで名刺を撮ってアップすると、AIが読み取って**下書き**に登録し、人が原本画像と見比べて承認したものだけが台帳に載る、1ファイルPHPの名刺管理。

- デモ: https://proto.exbridge.jp/kaima/
- 読み取りはAI、確定はあなた——AIは台帳に直接書けません(`ka_can()`が関門)
- 名寄せ(同一人物判定)・役職の変遷履歴・「この会社、うちの誰が知ってる?」の接点台帳
- メール形式+DNS実在・電話・郵便番号はプログラムが機械検証
- DBサーバー不要(SQLite)。レンタルサーバーのPHPで動く

## 設置

1. `public/` の中身(kaima.php・kaima_config.php.example・kaima_data/)をサーバーへ
2. `kaima_config.php.example` を `kaima_config.php` にコピーし、パスワードとAIのAPIキー(OpenAI互換のvision対応モデル)を設定
3. ブラウザで開いてログイン

要件: PHP 7.0+ / pdo_sqlite / gd / curl。データと名刺画像は `kaima_data/` に保存(このフォルダごとバックアップ)。名刺画像はログイン済みセッションにのみ配信されます。

## 開発

```
php scripts/check_kaima.php   # 自己テスト(関門・検証・名寄せ・変遷をAIなしで機械検証)
```

構築・運用の詳しい手順書(サーバー選び、APIキーの取得と選び方、一括取り込み、カスタマイズのAI用プロンプト集)は有償で提供しています: [Kurage App Store](https://kappstore.exbridge.jp/) / [解説と入手先](https://kurage.exbridge.jp/)

© EXBRIDGE, Inc. / MIT License

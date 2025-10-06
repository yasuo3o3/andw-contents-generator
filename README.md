# andW Contents Generator

Gutenberg ブロックエディタでの下書き作成を効率化する WordPress プラグインです。OpenAI API を使った AI 下書き生成と、静的 HTML を安全にブロックへ変換する 2 つのモジュールを統合しています。生成された内容は常に「下書き」として保存され、人間による確認を前提としています。

## 主要機能

- **AI 自動生成 (module-ai)**
  - OpenAI Chat Completions API を利用し、キーワードからタイトル / 見出し / 本文 / 要約を提案。
  - 投稿エディタ右サイドバーに「AI生成」パネルを追加。
  - 投稿一覧画面に「AIで下書き作成」ボタンを追加し、ワンクリックでドラフト生成。
  - 生成ログを `logs/andw-contents-generator.log` に保存し、REST 後処理でステータスを draft に強制設定。

- **HTML インポート (module-html)**
  - 貼り付けた HTML を DOMDocument で解析、危険なタグや属性を除去。
  - 見出し階層 (H1→H2/H3) を補正し、段落・リスト・引用・テーブル・画像ブロックへ変換。
  - 同種・同長の兄弟要素をスコアリングし、Columns ブロックに自動整形。
  - 変換結果をプレビューした上で「下書きに置換」または「現在記事に追記」を選択可能。

- **共通仕様**
  - プラグイン設定画面に「AI」「HTML」タブを用意。権限: `manage_ai_generate` / `manage_html_import`。
  - REST API では nonce と能力チェックを実施。
  - 外部画像は `media_sideload_image()` で添付し、すべての実行ログをファイル出力。

## ディレクトリ構成

```
andw-contents-generator/
├─ andw-contents-generator.php
├─ admin/
├─ assets/
│  └─ js/
├─ core/
├─ module-ai/
├─ module-html/
└─ logs/
```

## セットアップ

1. ZIP を作成し、WordPress 管理画面「プラグイン > 新規追加 > プラグインのアップロード」でインストールします。
2. 有効化後、「設定 > andW生成」ページで OpenAI API キー・モデル・既定プロンプトを登録します。
3. HTML タブで列化スコアや iframe 許可ドメインを必要に応じて調整します。
4. 投稿エディタを開くと、右サイドバーに AI / HTML パネルが表示されます。

## 開発メモ

- OpenAI との通信は `module-ai/class-andw-ai-service.php` を中心に構成。
- HTML 変換は `module-html/class-andw-html-importer.php` で DOMDocument を利用。
- エディタ UI は `assets/js/ai-sidebar.js` と `assets/js/html-importer.js` で実装。
- すべての外部操作はログファイルに記録されます。問題が発生したら `logs/andw-contents-generator.log` を確認してください。

## ライセンス

GPLv2 or later

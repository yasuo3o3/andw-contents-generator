=== andW Contents Generator ===
Contributors: yasuo3o3
Donate link: https://yasuo-o.xyz/
Tags: ai, block-editor, html-import, draft, gutenberg
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
原稿作成を支援するための AI下書き生成とHTMLインポート変換をまとめたプラグイン

== Description ==

Gutenberg ブロックエディタでの原稿作成を支援するための AI 下書き生成と HTML インポート変換をまとめたツールです。OpenAI API を利用したアイデア出し・要約生成と、静的 HTML を安全にクリーニングしてブロック化するフローを統合しています。生成結果は常に「下書き」として保存され、公開前に人間の確認を必須とする設計になっています。

== Features ==

* **AI 自動生成 (module-ai)** – エディタ右サイドバーと投稿一覧から OpenAI API によるタイトル/見出し/本文生成、要約の取得が可能。
* **HTML インポート (module-html)** – 貼り付けた静的 HTML をサニタイズし、段落・見出し・リスト・テーブル・画像ブロックへ変換。類似パターンを列化ブロックに自動整形。
* **共通ログ & 権限制御** – すべての REST エンドポイントで manage_ai_generate / manage_html_import 権限と nonce を検証。logs/ 配下へアクションログを記録。
* **ドラフト運用に特化** – 自動公開を禁止し、生成結果は常に draft ステータスに変更。外部画像は `media_sideload_image()` 経由で添付として保存。

== Installation ==

1. プラグイン ZIP を WordPress 管理画面の「プラグイン > 新規追加 > プラグインのアップロード」からインストールします。
2. 有効化後、「設定 > andW生成」ページを開き、AI タブで OpenAI API キーなどの初期設定を行います。
3. HTML タブで列化スコアや iframe 許可ドメインを必要に応じて調整します。
4. 投稿エディタを開くと、右サイドバーに「AI生成」「HTMLインポート」各パネルが表示されます。

== Frequently Asked Questions ==

= OpenAI API キーを入力してもサイドバーが利用できません =
API キーが空でないか、対象ユーザーに `manage_ai_generate` 権限が付与されているか確認してください。初回有効化時に管理者には自動付与されます。

= HTML 変換で画像が読み込まれません =
投稿がまだ保存されていない場合、外部画像の sideload をスキップします。いったん下書き保存してから再度変換してください。

== Screenshots ==

1. AI 生成パネル（投稿エディタ）
2. HTML インポートパネル（投稿エディタ）
3. AI 設定タブ
4. HTML 設定タブ

== Changelog ==

= 0.0.1 =
* 初期リリース。AI 下書き生成、HTML インポート、設定画面、ログ機構を搭載。

== Upgrade Notice ==

= 0.0.1 =
初期リリースです。AI 設定に OpenAI API キーを登録してからご利用ください。

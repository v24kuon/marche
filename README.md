# Marche Management Plugin

WordPressマルシェ顧客管理システム - Contact Form 7と連携した動的フォーム管理プラグイン

## 📋 概要

Marche Management Pluginは、WordPressでマルシェの出店者募集システムを構築するためのプラグインです。Contact Form 7と連携し、動的な料金計算、エリア管理、レンタル用品管理、画像管理などの機能を提供します。

## ✨ 主な機能

### 🎯 出店申込機能
- **動的料金計算**: エリア別料金とレンタル用品料金の自動計算
- **動的選択肢生成**: 管理画面での設定変更が即座にフォームに反映
- **定員制限機能**: エリアごとの定員管理と満員時の制御
- **画像アップロード**: チラシ画像などの安全な保存と管理

### 💳 決済機能
- **Stripe連携**: Contact Form 7との連携によるクレジットカード決済
- **銀行振込み対応**: 手動確認による承認プロセス
- **動的料金計算**: フォーム送信時のリアルタイム料金計算

### 📊 管理機能
- **ダッシュボード**: 申し込み統計とデータ一覧表示
- **フォーム管理**: Contact Form 7との連携管理
- **開催日管理**: 動的な開催日の追加・編集・削除
- **エリア管理**: 料金・定員・説明の個別設定
- **レンタル用品管理**: 料金・単位・数量制限の設定
- **画像管理**: アップロード画像の一覧表示とダウンロード

### 📧 メール機能
- **動的料金表示**: `[_total_amount]` タグによる合計金額の自動表示
- **ファイルURL送信**: アップロード画像のURL自動送信

## 🚀 インストール

### 前提条件
- WordPress 6.7以上
- PHP 7.4以上
- Contact Form 7 6.1以上

### インストール手順

1. **プラグインのアップロード**
   ```bash
   # プラグインディレクトリにファイルを配置
   wp-content/plugins/marche/
   ```

2. **プラグインの有効化**
   - WordPress管理画面 → プラグイン → Marche Management Plugin → 有効化

3. **Contact Form 7の確認**
   - Contact Form 7がインストール・有効化されていることを確認

## 📖 使用方法

### 1. フォーム管理

#### フォームの登録
1. 管理画面 → マルシェ管理 → フォーム管理
2. 「新規追加可能なフォーム」から対象フォームを選択
3. フォームタイプと支払い方法を設定

#### Contact Form 7コードの設定
フォーム管理画面で表示されるコードをContact Form 7エディタに貼り付け：

```html
[select* date data:date first_as_label "開催日を選択してください"]
[select* booth-location data:booth-location first_as_label "エリアを選択してください"]
[_total_amount]
[select* payment-method "クレジットカード" "銀行振込み"]
```

### 2. 開催日管理

1. 管理画面 → マルシェ管理 → 開催日管理
2. フォームを選択
3. 開催日の追加・編集・削除
4. ソート順の調整（ドラッグ&ドロップ）

### 3. エリア管理

1. 管理画面 → マルシェ管理 → エリア管理
2. フォームと開催日を選択
3. エリアの追加・編集・削除
4. 料金・定員・説明の設定

### 4. レンタル用品管理

1. 管理画面 → マルシェ管理 → レンタル用品管理
2. フォームを選択
3. レンタル用品の追加・編集・削除
4. 料金・単位・数量制限の設定

### 5. ダッシュボード

1. 管理画面 → マルシェ管理 → ダッシュボード
2. フォームと開催日を選択
3. 統計情報と申し込み一覧を確認

### 6. 画像管理

1. 管理画面 → マルシェ管理 → 画像管理
2. フォームと開催日を選択
3. アップロード画像の一覧表示
4. 画像の表示・ダウンロード・詳細確認

## 🔧 技術仕様

### データベース構造
- `marche_forms`: フォーム設定
- `marche_dates`: 開催日管理
- `marche_areas`: エリア管理
- `marche_rental_items`: レンタル用品管理
- `marche_applications`: 申し込みデータ
- `marche_files`: ファイル管理

### 主要クラス
- `MarcheManagementPlugin`: メインプラグインクラス
- `MarcheDataAccess`: データアクセス
- `MarchePriceCalculator`: 料金計算
- `MarcheFormHooks`: Contact Form 7連携
- `Marche_File_Manager`: ファイル管理

### Contact Form 7連携
- `wpcf7_form_tag_data_option`: 動的選択肢生成
- `wpcf7_mail_components`: メールタグ処理
- `wpcf7_stripe_payment_intent_parameters`: Stripe連携

## 📁 ファイル構成

```
marche/
├── marche-management-plugin.php    # メインプラグインファイル
├── admin/                          # 管理画面
│   ├── dashboard-management.php    # ダッシュボード
│   ├── form-management.php         # フォーム管理
│   ├── date-management.php         # 開催日管理
│   ├── area-management.php         # エリア管理
│   ├── rental-management.php       # レンタル用品管理
│   └── image-management.php        # 画像管理
├── includes/                       # クラスファイル
│   ├── class-data-access.php       # データアクセス
│   ├── class-price-calculator.php  # 料金計算
│   ├── class-form-hooks.php        # フォームフック
│   ├── class-settings-manager.php  # 設定管理
│   ├── class-application-saver.php # 申し込み保存
│   └── class-file-manager.php      # ファイル管理
├── assets/                         # アセットファイル
│   ├── css/
│   │   ├── admin.css               # 管理画面CSS
│   │   └── frontend.css            # フロントエンドCSS
│   └── js/
│       └── admin.js                # 管理画面JavaScript
└── uninstall.php                   # アンインストール処理
```

## 🎨 カスタマイズ

### CSSカスタマイズ
管理画面のスタイルは `assets/css/admin.css` で管理されています。

### JavaScriptカスタマイズ
管理画面の機能は `assets/js/admin.js` で管理されています。

### フック・フィルター
プラグインは以下のWordPressフックを使用しています：
- `wpcf7_form_tag_data_option`: 動的選択肢生成
- `wpcf7_mail_components`: メールタグ処理
- `wpcf7_stripe_payment_intent_parameters`: Stripe連携

## 🔒 セキュリティ

- 管理者権限チェック
- nonce検証
- ファイルアップロードのセキュリティ
- SQLインジェクション対策
- XSS対策

## 📝 更新履歴

### Version 1.0.0
- 初回リリース
- Contact Form 7連携
- 動的料金計算
- 管理画面機能
- Stripe決済連携
- 画像管理機能

## 🤝 サポート

### トラブルシューティング

#### Contact Form 7が認識されない
- Contact Form 7 6.1以上がインストール・有効化されているか確認
- プラグインの有効化順序を確認

#### 料金計算が動作しない
- エリアとレンタル用品が正しく設定されているか確認
- フォームデータが正しく送信されているか確認

#### 画像がアップロードされない
- ファイルサイズ制限を確認
- ディレクトリの書き込み権限を確認

### ログ確認
デバッグ時は `wp-content/debug.log` を確認してください。

## 📄 ライセンス

このプラグインは独自開発のため、使用・改変・配布は自由です。

## 👨‍💻 開発者

- **開発者**: GITAG
- **バージョン**: 1.0.0
- **最終更新**: 2024年

---

**注意**: このプラグインはContact Form 7との連携が必要です。事前にContact Form 7をインストール・有効化してください。

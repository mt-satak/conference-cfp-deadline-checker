# DynamoDB Schema Specification

Conference CfP Deadline Checker のデータスキーマ確定版。

## 1. テーブル概要

| テーブル | 役割 | 想定件数 |
|---|---|---|
| `conferences` | カンファレンス情報（CfP 期限を含む） | 50〜200 件 |
| `categories` | タグ・カテゴリのマスタ | 30〜50 件 |
| `donations` | カンパ受領履歴（管理画面で手動登録） | 数十〜数百件/年 |

すべて **DynamoDB オンデマンドモード**で運用する。

## 2. テーブル: `conferences`

### 2.1 キー定義

| キー種別 | 属性 | 型 | 説明 |
|---|---|---|---|
| Partition Key | `conferenceId` | String | UUID v4 |

Sort Key は持たない（複数 CfP トラックは独立したアイテムとして登録）。

### 2.2 属性定義

| 属性 | 型 | 必須 | 説明 |
|---|---|---|---|
| `conferenceId` | String | ✓ | UUID v4 |
| `name` | String | ✓ | カンファレンス名 |
| `trackName` | String | - | トラック名（複数 CfP がある場合のみ。例: 「一般 CfP」「LT CfP」） |
| `officialUrl` | String | ✓ | 公式サイト URL（カードクリック先） |
| `cfpUrl` | String | ✓ | CfP 応募ページ URL |
| `eventStartDate` | String | ✓ | 開催開始日（ISO 8601: `"YYYY-MM-DD"`、JST） |
| `eventEndDate` | String | ✓ | 開催終了日（ISO 8601: `"YYYY-MM-DD"`、JST） |
| `venue` | String | ✓ | 開催地（例: `"東京"`, `"オンライン"`, `"大阪"`） |
| `format` | String | ✓ | `"online"` / `"offline"` / `"hybrid"` |
| `cfpStartDate` | String | - | CfP 開始日（ISO 8601 date、不明時は未設定） |
| `cfpEndDate` | String | ✓ | CfP 締切日（ISO 8601 date、JST 23:59 を期限とする） |
| `categories` | List\<String\> | ✓ | `categories.categoryId` の配列 |
| `description` | String | - | 概要・備考（プレーンテキスト、HTML 不可） |
| `themeColor` | String | - | HEX 表記カラーコード（例: `"#FF6B6B"`） |
| `ttl` | Number | ✓ | DynamoDB TTL 用 UNIX タイムスタンプ（後述） |
| `createdAt` | String | ✓ | 登録日時（ISO 8601 datetime、JST） |
| `updatedAt` | String | ✓ | 更新日時（ISO 8601 datetime、JST） |

### 2.3 バリデーションルール（管理画面で強制）

| ルール | 内容 |
|---|---|
| 日付整合性 1 | `cfpStartDate` 設定時: `cfpStartDate <= cfpEndDate` |
| 日付整合性 2 | `cfpEndDate <= eventStartDate`（CfP は開催前に締切る） |
| 日付整合性 3 | `eventStartDate <= eventEndDate` |
| URL 形式 | `officialUrl` / `cfpUrl` は `https://` で始まる絶対 URL のみ |
| 文字長 | `name`: 1〜200 文字、`description`: 0〜2000 文字 |
| 列挙型 | `format` は `online` / `offline` / `hybrid` のいずれか |
| 参照整合性 | `categories` 配列の各 `categoryId` は `categories` テーブルに存在すること |
| HTML 禁止 | `description` にタグ文字（`<`, `>`, `&` 等）を含む場合はエスケープまたは拒否 |

### 2.4 TTL（自動削除）

| 設定 | 値 |
|---|---|
| TTL 属性名 | `ttl` |
| 値 | `cfpEndDate` の翌日 00:00 JST を UNIX タイムスタンプ化 |
| 効果 | CfP 締切翌日に自動的にレコード削除（最大 48 時間程度の遅延あり） |

ユーザー画面非表示は **ビルド時フィルタ**でも担保するため、TTL の遅延はユーザー体験に影響しない。

### 2.5 主要アクセスパターン

| 用途 | クエリ |
|---|---|
| 公開ページのビルド | テーブル全件 Scan → アプリ側で `cfpEndDate >= today` でフィルタ・ソート |
| iCal フィードのビルド | 同上 |
| 管理画面の一覧 | テーブル全件 Scan |
| 管理画面の編集 | `GetItem` by `conferenceId` |
| 管理画面の登録・更新 | `PutItem` |
| 管理画面の削除 | `DeleteItem` by `conferenceId` |

データ件数が少ないため Scan で十分。GSI は不要。

### 2.6 GSI

**MVP では作成しない。** 将来データが膨らんだ場合、`status (constant) + cfpEndDate` の GSI を検討。

### 2.7 サンプルアイテム

```json
{
  "conferenceId": "0c1f7a40-1e6a-4d52-9b1f-3b5c8a21d301",
  "name": "PHPカンファレンス2026",
  "trackName": "一般 CfP",
  "officialUrl": "https://phpcon.example.com/2026",
  "cfpUrl": "https://phpcon.example.com/2026/cfp",
  "eventStartDate": "2026-09-19",
  "eventEndDate": "2026-09-20",
  "venue": "東京",
  "format": "offline",
  "cfpStartDate": "2026-05-01",
  "cfpEndDate": "2026-07-15",
  "categories": [
    "1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02",
    "f5b7ac6b-edca-4bfd-fdac-fa6dbf7c8c10"
  ],
  "description": "国内最大規模のPHPカンファレンス。",
  "themeColor": "#777BB4",
  "ttl": 1768867200,
  "createdAt": "2026-04-15T10:30:00+09:00",
  "updatedAt": "2026-04-15T10:30:00+09:00"
}
```

## 3. テーブル: `categories`

### 3.1 キー定義

| キー種別 | 属性 | 型 | 説明 |
|---|---|---|---|
| Partition Key | `categoryId` | String | UUID v4 |

### 3.2 属性定義

| 属性 | 型 | 必須 | 説明 |
|---|---|---|---|
| `categoryId` | String | ✓ | UUID v4 |
| `name` | String | ✓ | 表示名（例: `"PHP"`, `"フロントエンド"`） |
| `slug` | String | ✓ | URL/フィルタ用識別子（英小文字 + ハイフン、例: `"php"`） |
| `displayOrder` | Number | ✓ | 表示順（軸ごとに番号帯を分割） |
| `axis` | String | - | 軸ラベル（`"A"`/`"B"`/`"C"`/`"D"`、運用補助。表示には使わない） |
| `createdAt` | String | ✓ | 登録日時 |
| `updatedAt` | String | ✓ | 更新日時 |

### 3.3 バリデーションルール

| ルール | 内容 |
|---|---|
| `slug` 形式 | `^[a-z0-9-]+$`、1〜64 文字 |
| `name` 重複禁止 | 既存カテゴリと同名のものを登録不可（管理画面で事前チェック） |
| `slug` 一意性 | `slug` の重複を禁止（管理画面で事前チェック） |

### 3.4 アクセスパターン

| 用途 | クエリ |
|---|---|
| 公開ページのビルド | 全件 Scan（フィルタ UI 用） |
| 管理画面 | 全件 Scan、`PutItem` / `DeleteItem` |

### 3.5 初期データ

[data/seeds/categories.json](./seeds/categories.json) を参照。34 件のシード。

## 4. テーブル: `donations`

### 4.1 キー定義

| キー種別 | 属性 | 型 | 説明 |
|---|---|---|---|
| Partition Key | `donationId` | String | UUID v4 |

### 4.2 属性定義

| 属性 | 型 | 必須 | 説明 |
|---|---|---|---|
| `donationId` | String | ✓ | UUID v4 |
| `receivedAt` | String | ✓ | 受領日時（ISO 8601 datetime、JST） |
| `amount` | Number | ✓ | 金額（JPY、整数） |
| `source` | String | ✓ | 受領経路（例: `"Stripe"`, `"BOOTH"`, `"BuyMeACoffee"`） |
| `donorName` | String | - | 寄付者名（任意・将来 Phase 3 で要再検討） |
| `note` | String | - | 備考 |
| `gsi1pk` | String | ✓ | 固定値 `"DONATION"`（GSI 用） |
| `gsi1sk` | String | ✓ | `receivedAt` と同値（GSI 用） |
| `createdAt` | String | ✓ | レコード作成日時 |
| `updatedAt` | String | ✓ | レコード更新日時 |

### 4.3 バリデーションルール

| ルール | 内容 |
|---|---|
| 金額 | `amount > 0`、整数 |
| `source` | 文字列の自由入力だが管理画面でプリセット選択を推奨 |
| 個人情報 | `donorName` を保存する場合、Phase 3 着手時にプライバシーポリシー記載 |

### 4.4 GSI

| GSI 名 | PK | SK | 用途 |
|---|---|---|---|
| `gsi1` | `gsi1pk` (固定 `"DONATION"`) | `gsi1sk` (`receivedAt`) | 期間範囲集計 |

### 4.5 アクセスパターン

| 用途 | クエリ |
|---|---|
| 月次 / 年次集計 | `Query gsi1 WHERE gsi1pk = "DONATION" AND gsi1sk BETWEEN :from AND :to` |
| 管理画面の登録 | `PutItem` |
| 管理画面の編集・削除 | `GetItem` / `DeleteItem` by `donationId` |

### 4.6 サンプルアイテム

```json
{
  "donationId": "abc12345-6789-4def-9012-3456789abcde",
  "receivedAt": "2026-04-20T15:30:00+09:00",
  "amount": 1000,
  "source": "Stripe",
  "donorName": null,
  "note": "Twitter経由",
  "gsi1pk": "DONATION",
  "gsi1sk": "2026-04-20T15:30:00+09:00",
  "createdAt": "2026-04-20T16:00:00+09:00",
  "updatedAt": "2026-04-20T16:00:00+09:00"
}
```

## 5. 共通設定

### 5.1 暗号化

| 項目 | 設定 |
|---|---|
| 保存時暗号化 | AWS マネージド KMS（デフォルト ON） |
| 通信時暗号化 | TLS（AWS SDK デフォルト） |

### 5.2 バックアップ・復旧

| 項目 | 設定 |
|---|---|
| Point-in-Time Recovery (PITR) | **全テーブル ON** |
| 削除防止 (Deletion Protection) | **全テーブル ON** |
| DynamoDB Streams | OFF |

### 5.3 環境分離

| 環境 | テーブル名規則 |
|---|---|
| 本番 | `cfp-conferences-prod`, `cfp-categories-prod`, `cfp-donations-prod` |
| 開発（ローカル） | DynamoDB Local コンテナを利用、テーブル名は同じでも別エンドポイント |

## 6. 削除運用

| テーブル | 方針 |
|---|---|
| `conferences` | TTL による自動削除（CfP 締切翌日 00:00 JST 以降）。手動削除も可 |
| `categories` | 手動削除のみ。削除前に該当 `categoryId` を参照する `conferences` がないことを管理画面で確認 |
| `donations` | 集計実績の保全のため自動削除しない。手動削除のみ |

## 7. 変更履歴管理

スキーマの破壊的変更（属性削除・型変更）は CDK のマイグレーションスクリプトで実施し、本ドキュメントを併せて更新する。

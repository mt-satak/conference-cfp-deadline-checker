# admin-api

`Conference CfP Deadline Checker` の管理 API (Laravel 13 + Bref + DynamoDB)。

## 前提

- PHP 8.5 (Bref Layer の php-85 と同バージョン)
- Composer 2.x
- xdebug 3.5+ (テストカバレッジ計測時のみ必須)
- Docker (DynamoDB Local 起動用)

## セットアップ

ホスト前提でのローカル開発手順:

```sh
# 依存インストール
make install

# DynamoDB Local 起動 + テーブル作成 + シード投入 (プロジェクトルートから)
cd ../.. && make db-up && make db-init && cd apps/admin-api

# .env を雛形からコピー (初回のみ) + APP_KEY 生成
cp .env.example .env && php artisan key:generate

# Laravel ビルトインサーバ起動 (port 8080)
make serve
```

ブラウザでアクセス:
- 管理画面: http://127.0.0.1:8080/admin
- API: http://127.0.0.1:8080/admin/api/health

> **注意**: `make serve` は `127.0.0.1:8080` で起動するため、`.env` の
> `APP_URL` は `http://127.0.0.1:8080` (= `.env.example` のデフォルト値)
> である必要がある。`http://localhost:8080` 等で開くと VerifyOrigin
> ミドルウェアが POST/PUT/DELETE で 403 INVALID_ORIGIN を返す。

UI 開発時は別端末で Vite dev server を起動すると CSS/JS の hot reload が効く:

```sh
pnpm install   # 初回のみ
pnpm dev
```

xdebug が未インストールでカバレッジ機能を使う場合:

```sh
pecl install xdebug
# php.ini 自動編集される。インストール後 `php -m | grep xdebug` で確認
```

## カテゴリ初期投入 (categories:seed)

`data/seeds/categories.json` の 34 件を `CategoryRepository` に upsert する Artisan コマンド。
運用初期に 1 度実行すれば軸 (axis) と表示順 (displayOrder) を含む全カテゴリが揃う。
カテゴリ管理を「管理フリー」に近づけるための一括投入機構 (Issue #38)。

```sh
# 投入予定だけ確認 (DB 書き込みなし)
php artisan categories:seed --dry-run

# 本投入 (idempotent。同 categoryId は上書き)
php artisan categories:seed

# 別ファイルから投入
php artisan categories:seed --source=path/to/file.json
```

- 既定パス: `data/seeds/categories.json` (リポジトリ同梱、34 件)
- DynamoDB Local 向け: `make db-up && make db-init` 後にこのコマンドを実行
- 本番 AWS 向け Bref Console handler 化は Phase 2 で対応予定

## カンファレンス初期投入 (conferences:seed)

`data/seeds/conferences.json` の 30+ 件を `ConferenceRepository` に upsert する Artisan コマンド (Issue #40 Phase 1)。
カンファレンス管理を「半自動化」する第 1 段階で、既知のカンファを一気に登録する用途。
Phase 0.5 (Issue #41) で導入した **Draft 状態を活用** しているため、CfP 期間が判明していないカンファレンスも仮登録できる。

```sh
# 投入予定だけ確認 (DB 書き込みなし)
php artisan conferences:seed --dry-run

# 本投入 (idempotent。同 conferenceId は上書き)
php artisan conferences:seed

# 別ファイルから投入
php artisan conferences:seed --source=path/to/file.json
```

### JSON シード形式

```json
{
  "conferences": [
    {
      "conferenceId": "uuid-v4-here",
      "name": "PHP Conference Japan 2026",
      "officialUrl": "https://phpcon.php.gr.jp/2026/",
      "cfpUrl": "https://fortee.jp/phpcon-2026/cfp",
      "eventStartDate": "2026-07-20",
      "eventEndDate": "2026-07-20",
      "venue": "大田区産業プラザ PiO",
      "format": "offline",
      "cfpEndDate": "2026-05-20",
      "categorySlugs": ["php"],
      "status": "published"
    },
    {
      "conferenceId": "uuid-v4-here",
      "name": "RubyKaigi 2027",
      "officialUrl": "https://rubykaigi.org/2027/",
      "categorySlugs": ["ruby"],
      "status": "draft"
    }
  ]
}
```

- `categorySlugs` は `data/seeds/categories.json` の `slug` (例: `php`, `frontend`, `mobile-ios`) を配列で指定
  - コマンド内で `CategoryRepository::findBySlug()` で UUID に解決される (= 可読性優先で UUID 直書きを避ける)
  - 未知 slug があると FAILURE で停止
- `status="draft"` の行は `cfpUrl` 等の Published 必須項目を省略可能
- `status="published"` の行は事前検証で必須項目欠落を弾く (= 投入後に admin UI でバリデーション違反になるデータを防ぐ)

### 同梱種データの構成 (2026-05-05 時点)

| 状態 | 件数 | 内訳 |
|---|---|---|
| **Published (CfP 募集中・締切確定済)** | 6 | 大吉祥寺.pm 2026 / PHP愛媛 2026 / PHP Japan 2026 / AWS CDK 2026 / PHP新潟 2026 / Product Engineering Conference 2026 |
| **Draft (CfP 期間未確定 / 終了済)** | 25 | iOSDC / DroidKaigi / PyCon JP / KubeCon Japan / 関数型まつり / Vue Fes / RubyKaigi 2027 / 他 |

合計 **31 件**。Draft の各エントリは公式 URL を持つので、Phase 3 (LLM 抽出) が完成すれば URL → 詳細補完 → Published 昇格の運用に乗せられる。

### 運用シナリオ

- **初期投入 (1 回限り)**: `make db-init && php artisan conferences:seed` で 31 件揃う
- **新規カンファ追加**: 単発登録は admin UI から 1 件ずつ。バッチ追加なら JSON に追記して再実行 (idempotent なので重複なし)
- **本番 AWS 向け Bref Console handler 化**: Phase 2 (= categories:seed と同様) で対応予定

## カンファレンスの Draft / Published 状態 (Phase 0.5)

カンファレンスは 2 つのライフサイクル状態を持つ (Issue #41 で導入):

| 状態 | 用途 | 必須項目 |
|---|---|---|
| **Published** (公開中) | 確定したカンファレンス。公開フロントエンドにも露出する | name / officialUrl / cfpUrl / eventStartDate / eventEndDate / venue / format / cfpEndDate / categories (≥1) |
| **Draft** (下書き) | CfP 期間未確定の仮登録、LLM 抽出結果のレビュー前置き場 (Phase 3 想定)、公開待ち | name / officialUrl のみ |

### Admin UI での操作

- 一覧画面 `/admin/conferences` 上部の「すべて / 公開中 / 下書き」タブで状態フィルタ
- 各行に色付きバッジで状態を表示 (緑=公開中、グレー=下書き)
- 編集フォームの送信ボタンが 2 つ:
  - **下書き保存**: name + officialUrl だけ埋まっていれば保存可能
  - **公開する**: 従来通り全 9 必須項目 + 整合性ルール (URL https / 日付順序 / 等) を要求
- 一覧の Draft 行に「公開する」ショートカットがあり、編集画面を経由せず 1 クリックで Published に昇格
  - 必須項目欠落時は edit 画面に戻り、不足項目をエラーフラッシュで提示

### API で Draft を扱う

```sh
# Draft で最小作成
curl -X POST http://127.0.0.1:8080/admin/api/conferences \
  -H 'Content-Type: application/json' \
  -d '{"status":"draft","name":"PHPカンファレンス2027","officialUrl":"https://phpcon.example.com/2027"}'

# Draft 一覧フィルタ
curl 'http://127.0.0.1:8080/admin/api/conferences?status=draft'

# Draft → Published 昇格 (PUT)
curl -X PUT http://127.0.0.1:8080/admin/api/conferences/{id} \
  -H 'Content-Type: application/json' \
  -d '{"status":"published"}'
```

詳細は `data/openapi.yaml` の Conference / ConferenceCreate / ConferenceUpdate スキーマ参照。

### データ後方互換

Phase 0.5 導入前に作成された旧 Conference アイテム (DynamoDB に `status` 属性が無い) は、Repository 読み込み時に Published として復元される (`DynamoDbConferenceRepository::resolveStatus()` の fail-safe 処理)。マイグレーション不要。

### TTL の挙動

- Published: `cfpEndDate` 翌日 00:00 JST を UNIX タイムスタンプ化した値が `ttl` 属性に付与され、DynamoDB が自動削除
- Draft (`cfpEndDate` が null): `ttl` 属性なし → 自動削除されない (= CfP 発表まで残せる)
- Draft → Published 昇格時: `save()` 再発行で `ttl` が後付けされる

### 現状の制約 / 注意点 (Phase 0.5 で残されている課題)

1. **Published → Draft 取り下げが UI 上技術的に可能**:
   Issue #41 では「published → draft 取り下げは当面サポートしない (将来検討)」としたが、編集フォームの「下書き保存」ボタンは status を draft にして保存するため、結果として取り下げが可能になっている。意図しない取り下げが起きないよう運用注意。将来必要なら UI 側で抑制する別 Issue を立てる。
2. **公開フロントエンド側の Draft 除外**: published のみ返す API/フロント実装は Astro 側着手時に対応 (Issue #41 Out of Scope)。
3. **status 変更の audit log**: 誰がいつ Draft → Published に昇格させたかの記録は持たない。必要になったら別 Issue。
4. **ベース seed (Issue #40 Phase 1) は本機能完了後**: 30+ 件の `data/seeds/conferences.json` 投入は別 PR で対応 (Phase 1)。

## テスト

| コマンド | 内容 |
|---|---|
| `make test` | テスト実行 (xdebug 不要、高速) |
| `make test-coverage` | テスト + C1 (Branch Coverage) 計測、HTML/Clover/Text 出力 |
| `make coverage-check` | 層別 C1 閾値判定 (未達なら exit 1) |

プロジェクトルートからは `make api-test` / `make api-test-coverage` /
`make api-coverage-check` で同等。

カバレッジレポート: `apps/admin-api/storage/coverage/html/index.html`

## 静的解析 (PHPStan level max)

| コマンド | 内容 |
|---|---|
| `make phpstan` | PHPStan level max を実行 (新規違反のみ報告) |
| `make phpstan-baseline` | ベースライン再生成 (緊急時のみ、原則不要) |
| `make phpstan-clear-cache` | 解析キャッシュをクリア |

プロジェクトルートからは `make api-phpstan` / `make api-phpstan-baseline`、
pnpm からは `pnpm api:phpstan` で同等。

ルール:
- `app/` 配下は **level max で 0 エラー必須**。`git push` 時の pre-push フックで
  自動チェックし、新規違反は遮断する
- `tests/` 配下は Pest/Mockery の構造的視野不足 (Pest クロージャの `$this`
  推論不足、Mockery fluent API の union 型解決困難) に由来する identifier
  限定で ignore (詳細は `phpstan.neon` の `ignoreErrors:` セクション参照)
- 経緯: Issue #13 のコメント参照

## CI / CD (GitHub Actions)

- `.github/workflows/admin-api.yml`:
  - **PR / push 時**: Pint lint / PHPStan level max / Pest + 層別 C1 ゲート
  - **main マージ時**: 上記再実行 → OIDC で AssumeRole → `cdk deploy CfpDeadlineCheckerStack`
  - パスフィルタ: `apps/admin-api/**` / `packages/**` / `docker/**` /
    `infra/cdk/**` のいずれかに変更がない PR ではワークフロー自体が起動しない
- `.github/workflows/cdk.yml`: `infra/cdk` の typecheck + vitest (TypeScript ゲート)

OIDC + Deploy Role のセットアップは [`infra/cdk/README.md`](../../infra/cdk/README.md)
参照。Phase 1 (CDK で OIDC 信頼関係構築) は完了済みで、本ワークフローはその
Role を `vars.AWS_DEPLOY_ROLE_ARN` から参照する。

ローカル開発時の同等チェックは pre-push フックで実行される (詳細は下記
「コードカバレッジルール」「静的解析」セクション参照)。

## コードカバレッジルール (層別 C1)

全コード一律 90% ではなく、**層 (namespace) ごとに C1 (Branch Coverage) 閾値を分ける**。
詳細な根拠・運用手順は [docs/test-strategy.md](../../docs/test-strategy.md) 参照。

| 層 | 閾値 | 理由 |
|---|---|---|
| `App\Domain\*` | **100%** | Entity/VO/Domain Exception。ロジックの中核、untested 分岐を許さない |
| `App\Application\*` | **100%** | UseCase。ロジック層 |
| `App\Http\Presenters\*` | **100%** | データ整形のみ、防御コード不要 |
| `App\Http\Requests\*` | **100%** | バリデーション定義 |
| `App\Http\Controllers\*` | **85%** | 薄いオーケストレーション、Feature テスト主体 |
| `App\Http\Middleware\*` | **85%** | 同上 + フレームワーク hook 分岐 |
| `App\Console\Commands\*` | **85%** | Artisan コマンド。Controllers と同様の薄いオーケストレーション |
| `App\Exceptions\*` | **75%** | match (true) + compound instanceof で xdebug が micro-branch 細分割するため一律 80%+ は padding テストでしか到達不能。実測上限を踏まえた現実値 |
| `App\Infrastructure\*` | **75%** | AWS SDK の例外パスのモック網羅コストが高い |
| `App\Providers\*` | (計測対象外) | DI 配線、Lambda コンテナ起動時のみ走る |

判定スクリプト: `scripts/check-coverage.php`
ゲート: `git push` 時に `.githooks/pre-push` が自動実行。

- フックの自動セットアップは `pnpm install` の `postinstall` で行われる
  (`git config core.hooksPath .githooks` が設定される)。
- 緊急時のみ `SKIP_COVERAGE_CHECK=1 git push` でバイパス可能 (推奨しない)。
- admin-api / packages / docker のいずれにも変更が無い push は自動でゲートを
  スキップ (=フロントエンドのみの変更等は素通し)。

## ディレクトリ構成 (Standard DDD)

```
app/
├── Domain/<Aggregate>/        # Entity / VO / Repository interface (フレームワーク非依存)
├── Application/<Aggregate>/   # UseCase (Application Service) と入力 DTO
├── Infrastructure/<Adapter>/  # AWS SDK 等の Repository 実装
└── Http/                      # Controllers / FormRequests / Presenters / Middleware
```

呼び出しフロー: `HTTP → Controller → UseCase → Repository (interface) → 実装 → DynamoDB`

詳細は MEMORY: `project_ddd_standard.md` 参照。

## 関連
- API 仕様: `data/openapi.yaml`
- DB スキーマ: `data/schema.md`
- テスト戦略・カバレッジルール: [docs/test-strategy.md](../../docs/test-strategy.md)
- 全体アーキテクチャ: `architecture.md` (gitignore 対象、ローカル参照のみ)

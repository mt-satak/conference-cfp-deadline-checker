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

# Laravel ビルトインサーバ起動 (port 8080)
make serve
```

xdebug が未インストールでカバレッジ機能を使う場合:

```sh
pecl install xdebug
# php.ini 自動編集される。インストール後 `php -m | grep xdebug` で確認
```

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

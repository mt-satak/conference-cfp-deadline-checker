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
| `make coverage-check` | C1 が 90% 以上か判定 (未満なら exit 1) |

プロジェクトルートからは `make api-test` / `make api-test-coverage` /
`make api-coverage-check` で同等。

カバレッジレポート: `apps/admin-api/storage/coverage/html/index.html`

## コードカバレッジルール (層別 C1)

全コード一律 90% ではなく、**層 (namespace) ごとに C1 (Branch Coverage) 閾値を分ける**。
詳細な根拠は `docs/test-strategy.md` (別 Issue で起票予定) を参照。

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
- 全体アーキテクチャ: `architecture.md` (gitignore 対象、ローカル参照のみ)

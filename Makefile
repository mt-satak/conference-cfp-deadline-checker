# プロジェクトルート用 Makefile
#
# 各 app / package のサブ Makefile に委譲する形式。
# プロジェクトルートから make ターゲット名で開発操作を一通り実行できる。
#
# 使い方:
#   make help              利用可能なターゲット一覧
#   make api-test          admin-api のテスト実行
#   make api-test-coverage admin-api のカバレッジ計測込みテスト
#   make api-coverage-check C1 (Branch Coverage) 90% 判定

.PHONY: help db-up db-down db-init db-reset \
        api-install api-test api-test-coverage api-coverage-check api-serve \
        api-phpstan api-phpstan-baseline api-lint api-lint-fix

.DEFAULT_GOAL := help

help: ## 利用可能なターゲット一覧を表示
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-25s\033[0m %s\n", $$1, $$2}'

# ── ローカル DB (DynamoDB Local) ──
db-up: ## DynamoDB Local 起動
	pnpm db:up

db-down: ## DynamoDB Local 停止
	pnpm db:down

db-init: ## DynamoDB Local にテーブル作成 + シード投入
	pnpm db:init

db-reset: ## DynamoDB Local の全テーブル削除
	pnpm db:reset

# ── admin-api (Laravel) ──
api-install: ## composer install を admin-api 配下で実行
	$(MAKE) -C apps/admin-api install

api-test: ## テスト実行 (xdebug 不要、高速)
	$(MAKE) -C apps/admin-api test

api-test-coverage: ## テスト + カバレッジ計測 (xdebug 必須)
	$(MAKE) -C apps/admin-api test-coverage

api-coverage-check: ## 層別 C1 (Branch Coverage) 閾値判定
	$(MAKE) -C apps/admin-api coverage-check

api-lint: ## Pint --test で style 違反を検出
	$(MAKE) -C apps/admin-api lint

api-lint-fix: ## Pint で style 違反を一括自動修正
	$(MAKE) -C apps/admin-api lint-fix

api-phpstan: ## PHPStan level max を実行 (ベースライン外の新規違反のみ報告)
	$(MAKE) -C apps/admin-api phpstan

api-phpstan-baseline: ## PHPStan ベースライン再生成 (実行は意図的に)
	$(MAKE) -C apps/admin-api phpstan-baseline

api-serve: ## php artisan serve でローカル起動 (port 8080)
	$(MAKE) -C apps/admin-api serve

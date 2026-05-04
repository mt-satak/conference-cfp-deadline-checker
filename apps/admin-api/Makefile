# admin-api 用 Makefile
#
# プロジェクト全体の make コマンド (apps/admin-api/ から実行)。
# pnpm scripts と機能は重複するが、make に慣れた開発者向けのインタフェース。
#
# 使い方:
#   make help            利用可能なターゲット一覧
#   make test            テスト実行 (xdebug 未起動、高速)
#   make test-coverage   カバレッジ計測込みでテスト実行 (xdebug 起動、HTML/Clover 出力)
#   make coverage-check  C1 (Branch Coverage) が 90% 以上か判定
#   make install         composer install
#   make serve           php artisan serve

.PHONY: help install test test-coverage coverage-check serve clean-coverage

# デフォルトは help
.DEFAULT_GOAL := help

# テストカバレッジに必要なメモリ (xdebug coverage は重いため拡大)
COVERAGE_MEMORY := 512M

help: ## 利用可能なターゲット一覧を表示
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

install: ## composer 依存をインストール
	composer install

test: ## テストを実行 (カバレッジなし、高速)
	# phpunit.xml に <coverage> があると pest が default で coverage 実行を試み、
	# xdebug 無効時に失敗するため --no-coverage を明示する。
	./vendor/bin/pest --no-coverage

test-coverage: ## テスト + カバレッジ計測 (xdebug 必須、HTML/Clover/Text 出力)
	XDEBUG_MODE=coverage php -d memory_limit=$(COVERAGE_MEMORY) ./vendor/bin/pest \
		--coverage-html=storage/coverage/html \
		--coverage-text=storage/coverage/coverage.txt \
		--coverage-clover=storage/coverage/clover.xml

coverage-check: ## C1 (Branch Coverage) が層別閾値を満たすか判定 (詳細は scripts/check-coverage.php)
	@php scripts/check-coverage.php storage/coverage/clover.xml

serve: ## php artisan serve でローカル起動 (port 8080)
	php artisan serve --host=127.0.0.1 --port=8080

clean-coverage: ## カバレッジレポート出力ディレクトリを削除
	rm -rf storage/coverage

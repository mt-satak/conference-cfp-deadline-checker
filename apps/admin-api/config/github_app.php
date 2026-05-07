<?php

/**
 * GitHub App 連携用設定 (Phase 5.3 / Issue #110)。
 *
 * 公開フロント (apps/public-site) の再ビルドを GitHub Actions deploy.yml の
 * workflow_dispatch で起動する。長期 PAT を Lambda に置かないために GitHub App
 * 経由 (= installation token は 1h で失効) で認証する設計。
 *
 * env() の直接参照は本ファイル内に閉じ込める (Larastan 推奨に従い、
 * config キャッシュ後の動作不能を防ぐ)。アプリ側は config('github_app.xxx')
 * 経由で参照する。
 *
 * private_key は AWS Secrets Manager から取得した PEM 文字列を渡す想定 (Phase 5.3
 * PR 5/5 で CDK 側に Secrets Manager リソースを追加し、Lambda 起動時に環境変数に
 * 展開する)。値が未設定の場合 Build 系エンドポイントは 503 SERVICE_UNAVAILABLE を
 * 返す (BuildServiceNotConfiguredException が投げられる経路)。
 */
return [
    /*
     * GitHub App ID (= 数値文字列)。GitHub App 設定画面トップに表示される。
     */
    'app_id' => env('GITHUB_APP_ID'),

    /*
     * Installation ID。App をリポジトリにインストールした際の URL に含まれる数値。
     * 例: https://github.com/settings/installations/<installation_id>
     */
    'installation_id' => env('GITHUB_APP_INSTALLATION_ID'),

    /*
     * Private key (PEM 文字列)。GitHub App 設定画面で発行した .pem ファイルの中身。
     * 改行を含むため env ファイルでは "\n" エスケープした 1 行で書く想定。
     * 本番は AWS Secrets Manager から取り出した値を Lambda 環境変数に展開する。
     */
    'private_key' => env('GITHUB_APP_PRIVATE_KEY'),

    /*
     * 起動対象 workflow の所属するリポジトリ owner (個人 or 組織名)。
     */
    'repo_owner' => env('GITHUB_APP_REPO_OWNER', 'mt-satak'),

    /*
     * リポジトリ名 (owner と組み合わせて {owner}/{repo} を構成する)。
     */
    'repo_name' => env('GITHUB_APP_REPO_NAME', 'conference-cfp-deadline-checker'),

    /*
     * 起動対象の workflow ファイル名 (.github/workflows 配下のファイル名)。
     * Phase 5.2 で deploy.yml に CD を集約したのでここを参照する。
     */
    'workflow_file' => env('GITHUB_APP_WORKFLOW_FILE', 'deploy.yml'),

    /*
     * workflow_dispatch で起動する際の ref (branch / tag)。
     * 通常は main 固定だが PR ブランチで試したい場合は env で切り替える。
     */
    'workflow_ref' => env('GITHUB_APP_WORKFLOW_REF', 'main'),
];

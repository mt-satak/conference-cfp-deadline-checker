<?php

/**
 * AWS Amplify 連携用設定。
 *
 * env() の直接参照は本ファイル内に閉じ込める (Larastan 推奨に従い、
 * config キャッシュ後の動作不能を防ぐ)。アプリ側は config('amplify.xxx')
 * 経由で参照する。
 *
 * 現状 Amplify アプリは未作成のため、すべて null / デフォルト値で動作する。
 * 各値が未設定の場合、Build 系エンドポイントは 503 SERVICE_UNAVAILABLE を返す
 * (BuildServiceNotConfiguredException が投げられる経路)。
 */
return [
    /*
     * Amplify Webhook URL。コンソールの「ビルドの設定」→「ビルドトリガー」で発行する。
     * 例: https://webhooks.amplify.<region>.amazonaws.com/prod/webhooks/<token>
     * 未設定の場合 POST /admin/api/build/trigger は 503 を返す。
     */
    'webhook_url' => env('AMPLIFY_WEBHOOK_URL'),

    /*
     * Amplify App ID。ListJobs API でビルド履歴取得に使う。
     * 未設定の場合 GET /admin/api/build/status は 503 を返す。
     */
    'app_id' => env('AMPLIFY_APP_ID'),

    /*
     * Amplify Branch 名。Git 連携時のブランチ名 (例: main / master)。
     * Git 連携を使わない場合でも Amplify が要求するため "main" 等を入れる。
     */
    'branch_name' => env('AMPLIFY_BRANCH_NAME', 'main'),

    /*
     * AWS リージョン。dynamodb.php と同じ値が一般的だが Amplify は別リージョン
     * 運用も可能なので独立に設定できるようにする。デフォルトは ap-northeast-1。
     */
    'region' => env('AMPLIFY_REGION', env('AWS_DEFAULT_REGION', 'ap-northeast-1')),
];

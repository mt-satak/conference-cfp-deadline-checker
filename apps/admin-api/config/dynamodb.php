<?php

/**
 * DynamoDB クライアント / リポジトリ向け設定。
 *
 * env() の直接参照は本ファイル内に閉じ込める (Larastan の
 * larastan.noEnvCallsOutsideOfConfig 推奨に従い、config キャッシュ後の
 * 動作不能を防ぐ)。アプリ側は config('dynamodb.xxx') 経由で参照する。
 */
return [
    /*
     * AWS リージョン。本番では Lambda 実行環境変数 AWS_DEFAULT_REGION から取得。
     * 未設定時のデフォルトは ap-northeast-1。
     */
    'region' => env('AWS_DEFAULT_REGION', 'ap-northeast-1'),

    /*
     * 明示的な認証情報。本番 (Lambda 実行ロール) では null 推奨で
     * SDK のデフォルトチェーンに委ねる。開発時 (DynamoDB Local) は .env で
     * ダミー値を設定する。
     */
    'credentials' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
    ],

    /*
     * DynamoDB エンドポイント上書き。開発時の DynamoDB Local 接続に使用
     * (例: http://localhost:8000)。本番は null (= AWS デフォルトエンドポイント)。
     */
    'endpoint' => env('AWS_DYNAMODB_ENDPOINT'),

    /*
     * 各テーブル名。Lambda 環境変数で本番テーブル名を上書き可能。
     */
    'tables' => [
        'conferences' => env('DYNAMODB_CONFERENCES_TABLE', 'cfp-conferences'),
    ],
];

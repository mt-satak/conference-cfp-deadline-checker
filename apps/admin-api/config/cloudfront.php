<?php

/**
 * CloudFront 関連設定。
 *
 * env() 呼び出しは本ファイル内にのみ閉じる (Larastan 推奨)。アプリ側は
 * config('cloudfront.xxx') 経由で参照する。
 */
return [
    /*
     * CloudFront → Lambda Function URL に CloudFront が付与する
     * Custom Origin Header (X-CloudFront-Secret) の値。
     *
     * Issue #77 の対応で導入。Lambda Function URL の AuthType=NONE に
     * 切り替えたため、Function URL 直アクセスを防ぐためのトークン。
     *
     * - 本番: CDK が Secrets Manager から取得して Lambda 環境変数で渡す
     * - 開発: 未設定時はミドルウェアが無効化される (= 検証スキップ)
     */
    'origin_secret' => env('CLOUDFRONT_ORIGIN_SECRET'),
];

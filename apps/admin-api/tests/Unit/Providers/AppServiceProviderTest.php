<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\URL;

/**
 * AppServiceProvider::boot() の URL 強制ロジックの単体テスト。
 *
 * 背景 (Issue #67):
 * CloudFront → Lambda Function URL 経由のリクエストでは Host ヘッダが
 * Function URL ドメインに書き換わる (SigV4 の都合)。Laravel のデフォルト
 * は Host ヘッダから URL を生成するため、生成 URL がすべて Function URL を
 * 指してしまい、ブラウザから直アクセスすると AWS_IAM 認証で 403 になる。
 *
 * 対策として APP_URL が https で始まる場合のみ forceRootUrl + forceScheme を
 * 適用する。http の場合 (= ローカル開発) は何もしない。
 */
it('APP_URL が https の場合 forceRootUrl と forceScheme を呼ぶ', function () {
    // Given: APP_URL が CloudFront ドメイン (https)
    config(['app.url' => 'https://cf.example.com']);
    URL::shouldReceive('forceRootUrl')->with('https://cf.example.com')->once();
    URL::shouldReceive('forceScheme')->with('https')->once();

    // When: AppServiceProvider::boot() を実行
    (new AppServiceProvider($this->app))->boot();

    // Then: Mockery が shouldReceive を自動検証する
});

it('APP_URL が http の場合 forceRootUrl は呼ばれない (ローカル開発を壊さない)', function () {
    // Given: APP_URL が http (= ローカル開発、`php artisan serve` 等)
    config(['app.url' => 'http://localhost:8000']);
    URL::shouldReceive('forceRootUrl')->never();
    URL::shouldReceive('forceScheme')->never();

    // When: AppServiceProvider::boot() を実行
    (new AppServiceProvider($this->app))->boot();

    // Then: never() が満たされる
});

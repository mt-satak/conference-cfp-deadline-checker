<?php

use App\Http\Middleware\CloudFrontSecretMiddleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * CloudFrontSecretMiddleware の単体テスト (Issue #77)。
 *
 * 背景:
 * Lambda Function URL の AuthType=NONE に切り替えたため、Function URL は
 * 認証なしで誰でも叩ける状態になった。CloudFront 経由のリクエストにのみ
 * Custom Origin Header `X-CloudFront-Secret: <value>` が付く設定とし、
 * このヘッダがないリクエスト (= Function URL 直アクセス) は 403 で弾く。
 */
beforeEach(function () {
    config(['cloudfront.origin_secret' => 'expected-secret-value']);
});

it('X-CloudFront-Secret header が一致する場合 next を呼ぶ', function () {
    // Given: 正しい secret 付きリクエスト
    $request = Request::create('/admin/conferences', 'POST');
    $request->headers->set('X-CloudFront-Secret', 'expected-secret-value');
    $middleware = new CloudFrontSecretMiddleware;

    // When
    $response = $middleware->handle($request, fn () => new Response('OK', 200));

    // Then
    expect($response->getStatusCode())->toBe(200);
    expect($response->getContent())->toBe('OK');
});

it('X-CloudFront-Secret header が不一致の場合 403 を返す', function () {
    // Given: 誤った secret
    $request = Request::create('/admin/conferences', 'POST');
    $request->headers->set('X-CloudFront-Secret', 'wrong-secret');
    $middleware = new CloudFrontSecretMiddleware;
    $nextCalled = false;

    // When
    $response = $middleware->handle($request, function () use (&$nextCalled) {
        $nextCalled = true;

        return new Response('OK', 200);
    });

    // Then: next は呼ばれず 403 が返る
    expect($nextCalled)->toBeFalse();
    expect($response->getStatusCode())->toBe(403);
});

it('X-CloudFront-Secret header が未指定の場合 403 を返す', function () {
    // Given: header なし (= Function URL 直アクセス想定)
    $request = Request::create('/admin/conferences', 'POST');
    $middleware = new CloudFrontSecretMiddleware;
    $nextCalled = false;

    // When
    $response = $middleware->handle($request, function () use (&$nextCalled) {
        $nextCalled = true;

        return new Response('OK', 200);
    });

    // Then
    expect($nextCalled)->toBeFalse();
    expect($response->getStatusCode())->toBe(403);
});

it('config 未設定時はミドルウェア無効として next を呼ぶ (ローカル開発で env を入れない場合の想定)', function () {
    // Given: config 未設定 (ローカル `php artisan serve` 等)
    config(['cloudfront.origin_secret' => null]);
    $request = Request::create('/admin/conferences', 'POST');
    $middleware = new CloudFrontSecretMiddleware;

    // When
    $response = $middleware->handle($request, fn () => new Response('OK', 200));

    // Then
    expect($response->getStatusCode())->toBe(200);
});

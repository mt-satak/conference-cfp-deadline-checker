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

it('config 未設定 + APP_ENV=local の時は next を呼ぶ (= ローカル開発の想定)', function () {
    // Given: config 未設定 + ローカル環境
    config(['cloudfront.origin_secret' => null, 'app.env' => 'local']);
    $request = Request::create('/admin/conferences', 'POST');
    $middleware = new CloudFrontSecretMiddleware;

    // When
    $response = $middleware->handle($request, fn () => new Response('OK', 200));

    // Then: ローカルでは bypass されて next が呼ばれる
    expect($response->getStatusCode())->toBe(200);
});

it('config 未設定 + APP_ENV=testing の時は next を呼ぶ (= テスト環境)', function () {
    // Given
    config(['cloudfront.origin_secret' => null, 'app.env' => 'testing']);
    $request = Request::create('/admin/conferences', 'POST');
    $middleware = new CloudFrontSecretMiddleware;

    // When
    $response = $middleware->handle($request, fn () => new Response('OK', 200));

    // Then: テスト環境でも bypass される (= 既存テストの後方互換)
    expect($response->getStatusCode())->toBe(200);
});

it('config 未設定 + APP_ENV=production の時は例外 throw (= fail-closed、Issue #177 #1)', function () {
    // Given: 本番環境で secret が空 (= CDK 配線ミス / config:cache stale 等の事故想定)
    config(['cloudfront.origin_secret' => null, 'app.env' => 'production']);
    $request = Request::create('/admin/conferences', 'POST');
    $middleware = new CloudFrontSecretMiddleware;

    // When / Then: silent disable せず例外を throw して 500 で確実に止まる
    expect(fn () => $middleware->handle($request, fn () => new Response('OK', 200)))
        ->toThrow(RuntimeException::class, 'CLOUDFRONT_ORIGIN_SECRET');
});

it('config 空文字列 + APP_ENV=production も例外 throw', function () {
    // Given: env 値が空文字列 (= 本番事故の別パターン)
    config(['cloudfront.origin_secret' => '', 'app.env' => 'production']);
    $request = Request::create('/admin/conferences', 'POST');
    $middleware = new CloudFrontSecretMiddleware;

    // When / Then
    expect(fn () => $middleware->handle($request, fn () => new Response('OK', 200)))
        ->toThrow(RuntimeException::class);
});

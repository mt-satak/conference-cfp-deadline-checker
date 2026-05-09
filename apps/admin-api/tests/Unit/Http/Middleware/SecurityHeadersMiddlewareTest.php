<?php

declare(strict_types=1);

use App\Http\Middleware\SecurityHeadersMiddleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * SecurityHeadersMiddleware の単体テスト (Issue #177 #2)。
 *
 * 公開フロント (cfp-checker.dev) には CDK の CloudFront response headers policy で
 * セキュリティヘッダが付与されているが、admin (admin.cfp-checker.dev) は同等の
 * 保護が無かった (= Basic 認証で守られているとはいえ、Blade の output 漏れ等の
 * defense-in-depth として CSP / X-Frame-Options / nosniff が望ましい)。
 *
 * 本ミドルウェアは admin Blade 応答に対して以下のヘッダを付与する:
 * - Content-Security-Policy: 厳格な default + frame-ancestors 'none'
 * - X-Content-Type-Options: nosniff
 * - Referrer-Policy: strict-origin-when-cross-origin
 * - X-Frame-Options: DENY (= CSP frame-ancestors の旧ブラウザ互換)
 */
it('Content-Security-Policy ヘッダを応答に付与する', function () {
    // Given
    $middleware = new SecurityHeadersMiddleware;
    $request = Request::create('/admin', 'GET');

    // When
    $response = $middleware->handle($request, fn () => new Response('OK', 200));

    // Then
    expect($response->headers->has('Content-Security-Policy'))->toBeTrue();
    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain("default-src 'self'");
    expect($csp)->toContain("frame-ancestors 'none'");
    expect($csp)->toContain("base-uri 'self'");
    expect($csp)->toContain("object-src 'none'");
    expect($csp)->toContain("form-action 'self'");
});

it('X-Content-Type-Options: nosniff を付与する', function () {
    // Given
    $middleware = new SecurityHeadersMiddleware;
    $request = Request::create('/admin', 'GET');

    // When
    $response = $middleware->handle($request, fn () => new Response('OK', 200));

    // Then
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('Referrer-Policy: strict-origin-when-cross-origin を付与する', function () {
    // Given
    $middleware = new SecurityHeadersMiddleware;
    $request = Request::create('/admin', 'GET');

    // When
    $response = $middleware->handle($request, fn () => new Response('OK', 200));

    // Then
    expect($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
});

it('X-Frame-Options: DENY を付与する (= CSP frame-ancestors の旧ブラウザ互換)', function () {
    // Given
    $middleware = new SecurityHeadersMiddleware;
    $request = Request::create('/admin', 'GET');

    // When
    $response = $middleware->handle($request, fn () => new Response('OK', 200));

    // Then
    expect($response->headers->get('X-Frame-Options'))->toBe('DENY');
});

it('既存の応答 body / status は変更されない', function () {
    // Given
    $middleware = new SecurityHeadersMiddleware;
    $request = Request::create('/admin', 'GET');

    // When
    $response = $middleware->handle($request, fn () => new Response('Hello admin', 201));

    // Then
    expect($response->getStatusCode())->toBe(201);
    expect($response->getContent())->toBe('Hello admin');
});

it('CSP は inline script を許可しない (= XSS injection の defense-in-depth)', function () {
    // Given
    $middleware = new SecurityHeadersMiddleware;
    $request = Request::create('/admin', 'GET');

    // When
    $response = $middleware->handle($request, fn () => new Response('OK', 200));

    // Then: script-src は 'self' のみ。'unsafe-inline' は含まない
    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain("script-src 'self'");
    expect($csp)->not->toContain("'unsafe-inline'");
});

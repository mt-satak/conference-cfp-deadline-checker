<?php

/**
 * /admin/api 配下の CSRF 保護に関する Feature テスト。
 *
 * 設計判断:
 *   Laravel の PreventRequestForgery (CSRF) ミドルウェアは
 *   $app->runningUnitTests() = true のとき CSRF 検証を意図的に
 *   バイパスする (テスト記述の利便性のため)。
 *   このため「POST に CSRF トークンが無いと 403」を直接テストできない。
 *
 *   代わりに、本テストでは「TokenMismatchException が発生したときに
 *   我々のグローバル例外ハンドラ (AdminApiExceptionRenderer) が
 *   OpenAPI 規約どおり 403 + CSRF_TOKEN_MISMATCH を返す」ことを検証する。
 *   Laravel の CSRF ミドルウェア自体の挙動はフレームワーク側でテスト済として信頼する。
 *
 *   /admin/api 配下のルートが web ミドルウェアグループに属していること
 *   (= CSRF が本番で適用されること) は AdminApiRoutingTest 側で確認済み。
 */

use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;

it('TokenMismatchException が /admin/api 配下で 403 + CSRF_TOKEN_MISMATCH に整形される', function () {
    Route::middleware('web')->post('/admin/api/_test_csrf_throw', function () {
        // 本番では PreventRequestForgery がトークン不一致時にこの例外を投げる。
        // テスト環境ではミドルウェアがバイパスするため、ルート内で明示的に投げる。
        throw new TokenMismatchException('CSRF token mismatch.');
    });

    $response = $this->postJson('/admin/api/_test_csrf_throw', []);

    $response->assertStatus(403);
    $response->assertJsonPath('error.code', 'CSRF_TOKEN_MISMATCH');
});

it('TokenMismatchException が /admin/api 配下でない場合は整形対象外', function () {
    Route::middleware('web')->post('/_test_csrf_throw_outside', function () {
        throw new TokenMismatchException('CSRF token mismatch.');
    });

    $response = $this->postJson('/_test_csrf_throw_outside', []);

    // /admin/api 配下でないため AdminApiExceptionRenderer は null を返し、
    // Laravel デフォルトのハンドラに委譲される。我々の独自形式 (error.code) は付かない。
    expect($response->json('error.code'))->toBeNull();
});

it('/admin/api 配下のルートは web ミドルウェアグループに属し CSRF 保護対象である', function () {
    // 本番環境で CSRF が効くための前提条件を 2 ステップで確認する:
    //   1. /admin/api 配下のルートが web グループに属している
    //   2. web グループに PreventRequestForgery (= CSRF) が含まれている
    // この 2 つの組合せで「/admin/api 配下に CSRF が適用される」ことが担保される。
    $route = collect(Route::getRoutes())
        ->first(fn ($r) => $r->uri() === 'admin/api/health');

    expect($route)->not->toBeNull();
    expect($route->middleware())->toContain('web');

    $webMiddleware = app('router')->getMiddlewareGroups()['web'] ?? [];
    expect($webMiddleware)
        ->toContain(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
});

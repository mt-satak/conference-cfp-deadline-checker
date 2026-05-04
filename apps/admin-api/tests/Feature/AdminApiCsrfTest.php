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
    // Given: /admin/api 配下に TokenMismatchException を投げる POST ルートを動的に登録する
    // (本番では PreventRequestForgery がトークン不一致時に投げるが、
    //  テスト環境ではミドルウェアがバイパスするため明示的に投げる)
    Route::middleware('web')->post('/admin/api/_test_csrf_throw', function () {
        throw new TokenMismatchException('CSRF token mismatch.');
    });

    // When: 当該 POST にリクエストする
    $response = $this->postJson('/admin/api/_test_csrf_throw', []);

    // Then: 例外整形ハンドラが 403 + CSRF_TOKEN_MISMATCH に変換する
    $response->assertStatus(403);
    $response->assertJsonPath('error.code', 'CSRF_TOKEN_MISMATCH');
});

it('TokenMismatchException が /admin/api 配下でない場合は整形対象外', function () {
    // Given: /admin/api プレフィックスの外で例外を投げる POST ルートを登録する
    Route::middleware('web')->post('/_test_csrf_throw_outside', function () {
        throw new TokenMismatchException('CSRF token mismatch.');
    });

    // When: 当該 POST にリクエストする
    $response = $this->postJson('/_test_csrf_throw_outside', []);

    // Then: AdminApiExceptionRenderer は null を返し、Laravel デフォルトの
    // ハンドラに委譲される (我々の独自形式 error.code は付かない)
    expect($response->json('error.code'))->toBeNull();
});

it('/admin/api 配下のルートは web ミドルウェアグループに属し CSRF 保護対象である', function () {
    // Given: 本番環境で CSRF が効く前提条件を 2 ステップで確認する
    // (1. ルートが web グループ / 2. web グループに PreventRequestForgery)

    // When: /admin/api/health のルート定義と web ミドルウェアグループの構成を取得する
    $route = collect(Route::getRoutes())
        ->first(fn ($r) => $r->uri() === 'admin/api/health');
    $webMiddleware = app('router')->getMiddlewareGroups()['web'] ?? [];

    // Then: 2 条件いずれも満たし、本番では CSRF が適用される
    expect($route)->not->toBeNull();
    expect($route->middleware())->toContain('web');
    expect($webMiddleware)
        ->toContain(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
});

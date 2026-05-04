<?php

/**
 * /admin/api プレフィックスのルーティング基盤に関する Feature テスト。
 *
 * このテスト群が検証するのは「ルーティングの土台」のみ。
 * 個別エンドポイントの仕様は OpenAPI で定義済みで、別 Issue で実装する。
 *
 * - /admin/api 配下にルート登録できること
 * - 登録ルートが web ミドルウェア (session / cookie 等) を踏むこと
 *   (web ミドルウェアの存在は Step 2-(d) CSRF / Step 2-(e) Origin 検証の
 *    前提条件となるため、本テストでも確認する)
 *
 * 各エンドポイント自体の振る舞い (例: /health のレスポンス内容) は
 * 個別の Feature テスト (HealthEndpointTest 等) で検証する。
 */

use Illuminate\Support\Facades\Route;

it('/admin/api プレフィックスに登録したルートが応答する', function () {
    Route::get('/admin/api/_test_ping', fn () => response()->json(['ok' => true]));

    $response = $this->get('/admin/api/_test_ping');

    $response->assertStatus(200);
    $response->assertExactJson(['ok' => true]);
});

it('routes/admin-api.php に登録された /health ルートが応答する', function () {
    // routes/admin-api.php 経由で登録された実エンドポイントが応答することを確認。
    // ここではルーティングが疎通していること自体を検証し、
    // レスポンス本体の構造詳細は HealthEndpointTest で検証する。
    $response = $this->get('/admin/api/health');

    $response->assertStatus(200);
});

it('/admin/api 配下のルートが web ミドルウェアグループに属している', function () {
    // 「ルートが web ミドルウェアグループ配下に登録されている」ことを直接確認する。
    // (テスト環境では SESSION_DRIVER=array に上書きされて Cookie が出ないため、
    //  実応答ではなくルート定義レベルで検証する)
    $route = collect(Route::getRoutes())
        ->first(fn ($r) => $r->uri() === 'admin/api/health');

    expect($route)->not->toBeNull();
    expect($route->middleware())->toContain('web');
});

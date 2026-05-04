<?php

/**
 * GET /admin/api/csrf-token の Feature テスト。
 *
 * OpenAPI 仕様 (data/openapi.yaml の operationId: getCsrfToken):
 *   - 200 OK
 *   - body: {"data": {"csrfToken": "<string>"}}
 *   - Set-Cookie: XSRF-TOKEN cookie が併せてセットされる
 *
 * SPA フロントから状態変更系リクエストを行う前に呼び出すことで、
 * XSRF-TOKEN cookie と body の csrfToken が同期される。
 *
 * 注意: テスト環境では phpunit.xml で SESSION_DRIVER=array に上書きされる
 * ため、Set-Cookie の検証は cookie ドライバの本番環境を信頼し、
 * 本テストでは body 側の csrfToken (= session 内のトークンと同値) を検証する。
 */

it('GET /admin/api/csrf-token は 200 と data.csrfToken (string, 非空) を返す', function () {
    $response = $this->getJson('/admin/api/csrf-token');

    $response->assertStatus(200);
    $token = $response->json('data.csrfToken');
    expect($token)->toBeString();
    expect($token)->not->toBeEmpty();
});

it('GET /admin/api/csrf-token のトークンは session()->token() と同値である', function () {
    // Laravel の session_token() と一致するトークンを返す。
    // これにより SPA がこのトークンを X-XSRF-TOKEN として送れば
    // 後続の状態変更系 POST/PUT/DELETE で CSRF 検証を通過できる。
    $response = $this->getJson('/admin/api/csrf-token');
    $bodyToken = $response->json('data.csrfToken');

    // Laravel TestCase のセッションは getJson 後も同一インスタンスで参照可能。
    expect($bodyToken)->toBe(session()->token());
});

it('GET /admin/api/csrf-token は web ミドルウェアを踏むこと (= セッション開始される)', function () {
    // /csrf-token は session に依存するため web ミドルウェアグループ必須。
    $route = collect(\Illuminate\Support\Facades\Route::getRoutes())
        ->first(fn ($r) => $r->uri() === 'admin/api/csrf-token');

    expect($route)->not->toBeNull();
    expect($route->middleware())->toContain('web');
});

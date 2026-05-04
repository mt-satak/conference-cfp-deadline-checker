<?php

/**
 * /admin/api 配下の Origin / Referer 検証ミドルウェアに関する Feature テスト。
 *
 * セキュリティ要件 S3 に基づき、状態変更系 (POST/PUT/PATCH/DELETE) では
 * Origin / Referer ヘッダが APP_URL と一致することを要求する。
 * 不一致時は OpenAPI 仕様 (data/openapi.yaml) のとおり 403 + INVALID_ORIGIN を返す。
 *
 * 安全性メソッド (GET/HEAD/OPTIONS) は対象外。
 */

use App\Http\Middleware\VerifyOrigin;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // Given (共通): 許可する Origin として APP_URL を固定する
    config(['app.url' => 'http://admin.example.com']);
});

it('POST に APP_URL と一致する Origin ヘッダがあれば通過する', function () {
    // Given: VerifyOrigin 適用の POST ルートを動的に登録する
    Route::middleware(['web', VerifyOrigin::class])
        ->post('/admin/api/_test_origin_ok', fn () => response()->json(['ok' => true]));

    // When: APP_URL と一致する Origin ヘッダ付きで POST する
    $response = $this->withHeaders(['Origin' => 'http://admin.example.com'])
        ->postJson('/admin/api/_test_origin_ok', []);

    // Then: 200 で通過する
    $response->assertStatus(200);
});

it('POST に不一致 Origin が来たら 403 INVALID_ORIGIN を返す', function () {
    // Given: VerifyOrigin 適用の POST ルートを動的に登録する
    Route::middleware(['web', VerifyOrigin::class])
        ->post('/admin/api/_test_origin_bad', fn () => response()->json(['ok' => true]));

    // When: APP_URL と一致しない Origin ヘッダ付きで POST する
    $response = $this->withHeaders(['Origin' => 'http://attacker.example.com'])
        ->postJson('/admin/api/_test_origin_bad', []);

    // Then: 403 + INVALID_ORIGIN で遮断される
    $response->assertStatus(403);
    $response->assertJsonPath('error.code', 'INVALID_ORIGIN');
});

it('PUT/DELETE も同様に Origin 検証対象である', function () {
    // Given: VerifyOrigin 適用の PUT / DELETE ルートを動的に登録する
    Route::middleware(['web', VerifyOrigin::class])
        ->put('/admin/api/_test_origin_put', fn () => response()->json(['ok' => true]));
    Route::middleware(['web', VerifyOrigin::class])
        ->delete('/admin/api/_test_origin_delete', fn () => response()->json(['ok' => true]));

    // When: 不一致 Origin で PUT / DELETE する
    $putResponse = $this->withHeaders(['Origin' => 'http://attacker.example.com'])
        ->putJson('/admin/api/_test_origin_put', []);
    $deleteResponse = $this->withHeaders(['Origin' => 'http://attacker.example.com'])
        ->deleteJson('/admin/api/_test_origin_delete');

    // Then: 共に 403 + INVALID_ORIGIN で遮断される
    $putResponse->assertStatus(403);
    $putResponse->assertJsonPath('error.code', 'INVALID_ORIGIN');
    $deleteResponse->assertStatus(403);
    $deleteResponse->assertJsonPath('error.code', 'INVALID_ORIGIN');
});

it('GET は Origin が不一致でも素通しする (安全性メソッドのため)', function () {
    // Given: VerifyOrigin 適用の GET ルートを動的に登録する
    Route::middleware(['web', VerifyOrigin::class])
        ->get('/admin/api/_test_origin_get', fn () => response()->json(['ok' => true]));

    // When: 不一致 Origin で GET する
    $response = $this->withHeaders(['Origin' => 'http://attacker.example.com'])
        ->getJson('/admin/api/_test_origin_get');

    // Then: 200 で素通しする (安全性メソッドのため検証対象外)
    $response->assertStatus(200);
});

it('POST に Origin が無くても Referer が APP_URL と一致すれば通過する', function () {
    // Given: VerifyOrigin 適用の POST ルートを動的に登録する
    Route::middleware(['web', VerifyOrigin::class])
        ->post('/admin/api/_test_origin_referer', fn () => response()->json(['ok' => true]));

    // When: Origin なし、Referer が APP_URL と一致するパスで POST する
    $response = $this->withHeaders(['Referer' => 'http://admin.example.com/some/page'])
        ->postJson('/admin/api/_test_origin_referer', []);

    // Then: 200 で通過する (Referer フォールバックが効く)
    $response->assertStatus(200);
});

it('POST に Origin / Referer ともに無い場合は 403 INVALID_ORIGIN', function () {
    // Given: VerifyOrigin 適用の POST ルートを動的に登録する
    Route::middleware(['web', VerifyOrigin::class])
        ->post('/admin/api/_test_origin_none', fn () => response()->json(['ok' => true]));

    // When: Origin / Referer ともに無い状態で POST する
    $response = $this->postJson('/admin/api/_test_origin_none', []);

    // Then: 403 + INVALID_ORIGIN で遮断される
    $response->assertStatus(403);
    $response->assertJsonPath('error.code', 'INVALID_ORIGIN');
});

it('routes/admin-api.php のルートには VerifyOrigin ミドルウェアが適用されている', function () {
    // When: routes/admin-api.php 経由で登録された /admin/api/health のルート定義を取得する
    $route = collect(Route::getRoutes())
        ->first(fn ($r) => $r->uri() === 'admin/api/health');

    // Then: ルートが存在し、VerifyOrigin が適用されている
    // (= bootstrap/app.php の then で /admin/api/* 全体に適用されている)
    expect($route)->not->toBeNull();
    expect($route->middleware())->toContain(VerifyOrigin::class);
});

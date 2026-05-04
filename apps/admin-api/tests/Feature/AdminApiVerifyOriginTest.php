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
    // テスト中は許可する Origin を固定する
    config(['app.url' => 'http://admin.example.com']);
});

it('POST に APP_URL と一致する Origin ヘッダがあれば通過する', function () {
    Route::middleware(['web', VerifyOrigin::class])
        ->post('/admin/api/_test_origin_ok', fn () => response()->json(['ok' => true]));

    $response = $this->withHeaders(['Origin' => 'http://admin.example.com'])
        ->postJson('/admin/api/_test_origin_ok', []);

    $response->assertStatus(200);
});

it('POST に不一致 Origin が来たら 403 INVALID_ORIGIN を返す', function () {
    Route::middleware(['web', VerifyOrigin::class])
        ->post('/admin/api/_test_origin_bad', fn () => response()->json(['ok' => true]));

    $response = $this->withHeaders(['Origin' => 'http://attacker.example.com'])
        ->postJson('/admin/api/_test_origin_bad', []);

    $response->assertStatus(403);
    $response->assertJsonPath('error.code', 'INVALID_ORIGIN');
});

it('PUT/DELETE も同様に Origin 検証対象である', function () {
    Route::middleware(['web', VerifyOrigin::class])
        ->put('/admin/api/_test_origin_put', fn () => response()->json(['ok' => true]));
    Route::middleware(['web', VerifyOrigin::class])
        ->delete('/admin/api/_test_origin_delete', fn () => response()->json(['ok' => true]));

    $putResponse = $this->withHeaders(['Origin' => 'http://attacker.example.com'])
        ->putJson('/admin/api/_test_origin_put', []);
    $deleteResponse = $this->withHeaders(['Origin' => 'http://attacker.example.com'])
        ->deleteJson('/admin/api/_test_origin_delete');

    $putResponse->assertStatus(403);
    $putResponse->assertJsonPath('error.code', 'INVALID_ORIGIN');
    $deleteResponse->assertStatus(403);
    $deleteResponse->assertJsonPath('error.code', 'INVALID_ORIGIN');
});

it('GET は Origin が不一致でも素通しする (安全性メソッドのため)', function () {
    Route::middleware(['web', VerifyOrigin::class])
        ->get('/admin/api/_test_origin_get', fn () => response()->json(['ok' => true]));

    $response = $this->withHeaders(['Origin' => 'http://attacker.example.com'])
        ->getJson('/admin/api/_test_origin_get');

    $response->assertStatus(200);
});

it('POST に Origin が無くても Referer が APP_URL と一致すれば通過する', function () {
    Route::middleware(['web', VerifyOrigin::class])
        ->post('/admin/api/_test_origin_referer', fn () => response()->json(['ok' => true]));

    $response = $this->withHeaders(['Referer' => 'http://admin.example.com/some/page'])
        ->postJson('/admin/api/_test_origin_referer', []);

    $response->assertStatus(200);
});

it('POST に Origin / Referer ともに無い場合は 403 INVALID_ORIGIN', function () {
    Route::middleware(['web', VerifyOrigin::class])
        ->post('/admin/api/_test_origin_none', fn () => response()->json(['ok' => true]));

    $response = $this->postJson('/admin/api/_test_origin_none', []);

    $response->assertStatus(403);
    $response->assertJsonPath('error.code', 'INVALID_ORIGIN');
});

it('routes/admin-api.php のルートには VerifyOrigin ミドルウェアが適用されている', function () {
    // bootstrap/app.php の then で /admin/api/* 全体に適用されることを確認。
    $route = collect(Route::getRoutes())
        ->first(fn ($r) => $r->uri() === 'admin/api/health');

    expect($route)->not->toBeNull();
    expect($route->middleware())->toContain(VerifyOrigin::class);
});

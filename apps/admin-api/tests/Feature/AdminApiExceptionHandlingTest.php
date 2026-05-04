<?php

/**
 * /admin/api 配下のグローバル例外ハンドリングに関する Feature テスト。
 *
 * OpenAPI 仕様 (data/openapi.yaml) で定義された
 * {"error": {"code": ..., "message": ..., "details": [...]}} 形式に
 * すべての例外が変換されることを検証する。
 *
 * /admin/api 配下のリクエストのみ JSON 整形対象。それ以外は Laravel
 * デフォルトの挙動 (HTML / フレームワークデフォルト JSON) を維持する。
 */

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('admin/api 配下で ValidationException が 422 + VALIDATION_FAILED を返す', function () {
    Route::post('/admin/api/_test_validation', function (\Illuminate\Http\Request $request) {
        $request->validate([
            'email' => 'required|email',
            'name' => 'required|string|min:3',
        ]);
    });

    $response = $this->postJson('/admin/api/_test_validation', []);

    $response->assertStatus(422);
    $response->assertJsonPath('error.code', 'VALIDATION_FAILED');
    $response->assertJsonPath('error.message', 'Validation failed for one or more fields');

    // details は [{field, rule}] の配列で返る (フィールド名は camelCase)
    $details = $response->json('error.details');
    expect($details)->toBeArray()->not->toBeEmpty();
    $fields = collect($details)->pluck('field')->all();
    expect($fields)->toContain('email');
    expect($fields)->toContain('name');
});

it('admin/api 配下の存在しないルートが 404 + NOT_FOUND を返す', function () {
    $response = $this->getJson('/admin/api/this_route_does_not_exist');

    $response->assertStatus(404);
    $response->assertJsonPath('error.code', 'NOT_FOUND');
});

it('admin/api 配下で ModelNotFoundException が 404 + NOT_FOUND を返す', function () {
    Route::get('/admin/api/_test_model_missing', function () {
        throw new ModelNotFoundException();
    });

    $response = $this->getJson('/admin/api/_test_model_missing');

    $response->assertStatus(404);
    $response->assertJsonPath('error.code', 'NOT_FOUND');
});

it('admin/api 配下で HttpException がステータスを保ったまま整形される', function () {
    Route::get('/admin/api/_test_http_403', function () {
        throw new HttpException(403, 'forbidden by route');
    });

    $response = $this->getJson('/admin/api/_test_http_403');

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBeString();
});

it('admin/api 配下で予期しない例外が 500 + INTERNAL_ERROR を返す', function () {
    Route::get('/admin/api/_test_runtime', function () {
        throw new \RuntimeException('boom');
    });

    $response = $this->getJson('/admin/api/_test_runtime');

    $response->assertStatus(500);
    $response->assertJsonPath('error.code', 'INTERNAL_ERROR');
});

it('admin/api 配下でない 404 は Laravel デフォルトの JSON 整形 (我々の独自形式ではない) になる', function () {
    // /admin/api 以外のパスでは整形ハンドラが介入しない。
    // Accept: application/json (getJson) を送って JSON 応答を強制し、
    // Laravel 標準の 404 JSON ({"message": "..."}) が返ること =
    // 我々の {"error": {"code": ...}} ではないこと、を確認する。
    $response = $this->getJson('/this_route_does_not_exist');

    $response->assertStatus(404);
    expect($response->json('error.code'))->toBeNull();
});

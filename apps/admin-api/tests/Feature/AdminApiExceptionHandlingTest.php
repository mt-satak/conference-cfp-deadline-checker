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
    // Given: 必須フィールドが未指定だと ValidationException を投げるルートを動的に登録する
    Route::post('/admin/api/_test_validation', function (\Illuminate\Http\Request $request) {
        $request->validate([
            'email' => 'required|email',
            'name' => 'required|string|min:3',
        ]);
    });

    // When: 必須フィールド全欠落の POST を送る
    $response = $this->postJson('/admin/api/_test_validation', []);

    // Then: 422 + VALIDATION_FAILED + フィールドレベル details が返る
    $response->assertStatus(422);
    $response->assertJsonPath('error.code', 'VALIDATION_FAILED');
    $response->assertJsonPath('error.message', 'Validation failed for one or more fields');
    $details = $response->json('error.details');
    expect($details)->toBeArray()->not->toBeEmpty();
    $fields = collect($details)->pluck('field')->all();
    expect($fields)->toContain('email');
    expect($fields)->toContain('name');
});

it('admin/api 配下の存在しないルートが 404 + NOT_FOUND を返す', function () {
    // When: /admin/api 配下の存在しないパスに GET する
    $response = $this->getJson('/admin/api/this_route_does_not_exist');

    // Then: 404 + NOT_FOUND が返る (NotFoundHttpException → NOT_FOUND マッピング)
    $response->assertStatus(404);
    $response->assertJsonPath('error.code', 'NOT_FOUND');
});

it('admin/api 配下で ModelNotFoundException が 404 + NOT_FOUND を返す', function () {
    // Given: ModelNotFoundException を投げるルートを動的に登録する
    Route::get('/admin/api/_test_model_missing', function () {
        throw new ModelNotFoundException();
    });

    // When: 当該ルートに GET する
    $response = $this->getJson('/admin/api/_test_model_missing');

    // Then: 404 + NOT_FOUND に整形される
    $response->assertStatus(404);
    $response->assertJsonPath('error.code', 'NOT_FOUND');
});

it('admin/api 配下で HttpException がステータスを保ったまま整形される', function () {
    // Given: HttpException(403) を投げるルートを動的に登録する
    Route::get('/admin/api/_test_http_403', function () {
        throw new HttpException(403, 'forbidden by route');
    });

    // When: 当該ルートに GET する
    $response = $this->getJson('/admin/api/_test_http_403');

    // Then: 例外の status code が維持され、code が文字列で返る
    $response->assertStatus(403);
    expect($response->json('error.code'))->toBeString();
});

it('admin/api 配下で予期しない例外が 500 + INTERNAL_ERROR を返す', function () {
    // Given: 任意の RuntimeException を投げるルートを動的に登録する
    Route::get('/admin/api/_test_runtime', function () {
        throw new \RuntimeException('boom');
    });

    // When: 当該ルートに GET する
    $response = $this->getJson('/admin/api/_test_runtime');

    // Then: 500 + INTERNAL_ERROR に整形される
    $response->assertStatus(500);
    $response->assertJsonPath('error.code', 'INTERNAL_ERROR');
});

it('admin/api 配下でない 404 は Laravel デフォルトの JSON 整形 (我々の独自形式ではない) になる', function () {
    // When: /admin/api プレフィックスの外で存在しないパスに getJson する
    // (Accept: application/json を強制し JSON 応答にさせる)
    $response = $this->getJson('/this_route_does_not_exist');

    // Then: 404 だが我々の error.code 形式ではない (Laravel 標準の {"message": ...})
    $response->assertStatus(404);
    expect($response->json('error.code'))->toBeNull();
});

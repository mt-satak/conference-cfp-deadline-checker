<?php

use App\Http\Controllers\Api\BaseController;
use Illuminate\Http\JsonResponse;

/**
 * BaseController のレスポンスヘルパに対するユニットテスト。
 *
 * すべての admin-api コントローラはこの基底クラスを継承し、
 * 共通フォーマット ({"data": ..., "meta": {}} / {"error": {...}}) で
 * レスポンスを返却する。OpenAPI 仕様 (data/openapi.yaml) と整合する。
 */

/**
 * BaseController は abstract のため無名サブクラスでインスタンス化するファクトリ。
 * Pest の $this->* パターンは IDE 静的解析と相性が悪いため利用しない。
 */
function makeBaseController(): BaseController
{
    return new class extends BaseController {};
}

it('ok() は 200 と {"data": ..., "meta": {}} を返す', function () {
    // Given: BaseController サブクラスのインスタンス
    $controller = makeBaseController();

    // When: ok() に data のみ渡す
    $response = $controller->ok(['foo' => 'bar']);

    // Then: 200 で {data: ..., meta: {}} が返る
    // (meta は空でも JSON 上は {} (object) で OpenAPI の meta: object 型と整合)
    expect($response)->toBeInstanceOf(JsonResponse::class);
    expect($response->status())->toBe(200);
    $payload = json_decode($response->getContent(), associative: true);
    expect($payload)->toHaveKey('data');
    expect($payload['data'])->toBe(['foo' => 'bar']);
    expect($payload)->toHaveKey('meta');
});

it('ok() に meta を渡すとそれが含まれる', function () {
    // Given: BaseController サブクラスのインスタンス
    $controller = makeBaseController();

    // When: ok() に data と meta を渡す
    $response = $controller->ok(
        data: [['id' => 'a'], ['id' => 'b']],
        meta: ['count' => 2],
    );

    // Then: data 配列と meta.count がそのまま反映される
    $payload = json_decode($response->getContent(), associative: true);
    expect($payload['data'])->toHaveCount(2);
    expect($payload['meta'])->toBe(['count' => 2]);
});

it('created() は 201 と {"data": ...} を返す', function () {
    // Given: BaseController サブクラスのインスタンス
    $controller = makeBaseController();

    // When: created() で新規リソース表現を渡す
    $response = $controller->created(['id' => 'abc-123', 'name' => 'PHPカンファレンス']);

    // Then: 201 で {data: ...} が返る
    expect($response->status())->toBe(201);
    $payload = json_decode($response->getContent(), associative: true);
    expect($payload)->toHaveKey('data');
    expect($payload['data']['id'])->toBe('abc-123');
});

it('noContent() は 204 と空ボディを返す', function () {
    // Given: BaseController サブクラスのインスタンス
    $controller = makeBaseController();

    // When: noContent() を呼ぶ
    $response = $controller->noContent();

    // Then: 204 でボディが空
    // (RFC 7231 準拠。JsonResponse(null) の "null" 文字列出力を回避)
    expect($response->status())->toBe(204);
    expect($response->getContent())->toBe('');
});

it('error() は status と {"error": {code, message}} を返す', function () {
    // Given: BaseController サブクラスのインスタンス
    $controller = makeBaseController();

    // When: error() に code/message/status を渡す (details なし)
    $response = $controller->error(
        code: 'NOT_FOUND',
        message: 'Resource not found',
        status: 404,
    );

    // Then: 指定 status で {error: {code, message}} が返る (details キーなし)
    expect($response->status())->toBe(404);
    $payload = json_decode($response->getContent(), associative: true);
    expect($payload)->toBe([
        'error' => [
            'code' => 'NOT_FOUND',
            'message' => 'Resource not found',
        ],
    ]);
});

it('error() に details を渡すと details が含まれる', function () {
    // Given: BaseController サブクラスのインスタンス
    $controller = makeBaseController();

    // When: error() に details (フィールドレベルエラー) を渡す
    $response = $controller->error(
        code: 'VALIDATION_FAILED',
        message: 'Validation failed for one or more fields',
        status: 422,
        details: [
            ['field' => 'cfpEndDate', 'rule' => 'required'],
            ['field' => 'officialUrl', 'rule' => 'url_https'],
        ],
    );

    // Then: 422 で details 配列が含まれる
    expect($response->status())->toBe(422);
    $payload = json_decode($response->getContent(), associative: true);
    expect($payload['error']['details'])->toHaveCount(2);
    expect($payload['error']['details'][0])->toBe(['field' => 'cfpEndDate', 'rule' => 'required']);
});

it('error() に空の details を渡すと details キーは含めない', function () {
    // Given: BaseController サブクラスのインスタンス
    $controller = makeBaseController();

    // When: error() に空配列の details を渡す
    $response = $controller->error(
        code: 'NOT_FOUND',
        message: 'Resource not found',
        status: 404,
        details: [],
    );

    // Then: error オブジェクトに details キー自体が含まれない
    $payload = json_decode($response->getContent(), associative: true);
    expect($payload['error'])->not->toHaveKey('details');
});

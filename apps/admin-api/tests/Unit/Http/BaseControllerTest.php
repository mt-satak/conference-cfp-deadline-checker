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

beforeEach(function () {
    // BaseController は abstract なので無名サブクラスでインスタンス化する
    $this->controller = new class extends BaseController {};
});

it('ok() は 200 と {"data": ..., "meta": {}} を返す', function () {
    $response = $this->controller->ok(['foo' => 'bar']);

    expect($response)->toBeInstanceOf(JsonResponse::class);
    expect($response->status())->toBe(200);

    // meta は空配列でも JSON 上は {} (object) となる必要がある
    // (OpenAPI の meta: object 型と整合させるため)
    $payload = json_decode($response->getContent(), associative: true);
    expect($payload)->toHaveKey('data');
    expect($payload['data'])->toBe(['foo' => 'bar']);
    expect($payload)->toHaveKey('meta');
});

it('ok() に meta を渡すとそれが含まれる', function () {
    $response = $this->controller->ok(
        data: [['id' => 'a'], ['id' => 'b']],
        meta: ['count' => 2],
    );

    $payload = json_decode($response->getContent(), associative: true);
    expect($payload['data'])->toHaveCount(2);
    expect($payload['meta'])->toBe(['count' => 2]);
});

it('created() は 201 と {"data": ...} を返す', function () {
    $response = $this->controller->created(['id' => 'abc-123', 'name' => 'PHPカンファレンス']);

    expect($response->status())->toBe(201);
    $payload = json_decode($response->getContent(), associative: true);
    expect($payload)->toHaveKey('data');
    expect($payload['data']['id'])->toBe('abc-123');
});

it('noContent() は 204 と空ボディを返す', function () {
    $response = $this->controller->noContent();

    expect($response->status())->toBe(204);
    expect($response->getContent())->toBe('');
});

it('error() は status と {"error": {code, message}} を返す', function () {
    $response = $this->controller->error(
        code: 'NOT_FOUND',
        message: 'Resource not found',
        status: 404,
    );

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
    $response = $this->controller->error(
        code: 'VALIDATION_FAILED',
        message: 'Validation failed for one or more fields',
        status: 422,
        details: [
            ['field' => 'cfpEndDate', 'rule' => 'required'],
            ['field' => 'officialUrl', 'rule' => 'url_https'],
        ],
    );

    expect($response->status())->toBe(422);
    $payload = json_decode($response->getContent(), associative: true);
    expect($payload['error']['details'])->toHaveCount(2);
    expect($payload['error']['details'][0])->toBe(['field' => 'cfpEndDate', 'rule' => 'required']);
});

it('error() に空の details を渡すと details キーは含めない', function () {
    $response = $this->controller->error(
        code: 'NOT_FOUND',
        message: 'Resource not found',
        status: 404,
        details: [],
    );

    $payload = json_decode($response->getContent(), associative: true);
    expect($payload['error'])->not->toHaveKey('details');
});

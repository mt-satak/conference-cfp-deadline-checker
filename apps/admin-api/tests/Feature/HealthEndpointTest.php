<?php

/**
 * GET /admin/api/health の Feature テスト。
 *
 * OpenAPI 仕様 (data/openapi.yaml の operationId: healthCheck):
 *   - 200 OK
 *   - body: {"status": "ok", "timestamp": "<ISO 8601 datetime>"}
 *   - 認証不要 (security: [])
 *
 * 注意: BaseController の {"data": ..., "meta": {...}} ラップ形式とは
 * 異なり、Health は直接プロパティ (status / timestamp) を返す仕様。
 */

it('GET /admin/api/health は 200 と status=ok を返す', function () {
    $response = $this->getJson('/admin/api/health');

    $response->assertStatus(200);
    $response->assertJsonPath('status', 'ok');
});

it('GET /admin/api/health のレスポンスに ISO 8601 形式の timestamp が含まれる', function () {
    $response = $this->getJson('/admin/api/health');

    $timestamp = $response->json('timestamp');
    expect($timestamp)->toBeString();

    // ISO 8601 (RFC 3339 互換) としてパースできることを確認する
    $parsed = \Carbon\Carbon::parse($timestamp);
    expect($parsed)->toBeInstanceOf(\Carbon\Carbon::class);
});

it('GET /admin/api/health は OpenAPI 仕様外のキーを含めない', function () {
    // status / timestamp のみを返し、data / meta 等の他キーは無いこと。
    // 仕様外プロパティが混入すると将来クライアント生成コードと合わなくなる。
    $response = $this->getJson('/admin/api/health');

    $payload = $response->json();
    expect($payload)->toBeArray();
    expect(array_keys($payload))->toEqualCanonicalizing(['status', 'timestamp']);
});

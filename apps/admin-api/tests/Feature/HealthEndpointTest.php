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
    // When: ヘルスチェックエンドポイントに GET する
    $response = $this->getJson('/admin/api/health');

    // Then: 200 で status: 'ok' が返る
    $response->assertStatus(200);
    $response->assertJsonPath('status', 'ok');
});

it('GET /admin/api/health のレスポンスに ISO 8601 形式の timestamp が含まれる', function () {
    // When: ヘルスチェックエンドポイントに GET する
    $response = $this->getJson('/admin/api/health');

    // Then: timestamp が文字列で、ISO 8601 (RFC 3339 互換) としてパースできる
    $timestamp = $response->json('timestamp');
    expect($timestamp)->toBeString();
    $parsed = \Carbon\Carbon::parse($timestamp);
    expect($parsed)->toBeInstanceOf(\Carbon\Carbon::class);
});

it('GET /admin/api/health は OpenAPI 仕様外のキーを含めない', function () {
    // When: ヘルスチェックエンドポイントに GET する
    $response = $this->getJson('/admin/api/health');

    // Then: トップレベルキーが status / timestamp のみで他キー混入なし
    // (仕様外プロパティが混入すると将来クライアント生成コードと合わなくなる)
    $payload = $response->json();
    expect($payload)->toBeArray();
    expect(array_keys($payload))->toEqualCanonicalizing(['status', 'timestamp']);
});

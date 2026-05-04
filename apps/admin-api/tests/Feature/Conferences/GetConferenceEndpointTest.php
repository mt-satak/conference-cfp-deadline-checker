<?php

use App\Application\Conferences\GetConferenceUseCase;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceNotFoundException;

/**
 * GET /admin/api/conferences/{id} (operationId: getConference) の Feature テスト。
 *
 * OpenAPI 仕様 (data/openapi.yaml):
 *   - 200 OK: {"data": <Conference>}
 *   - 404 NOT_FOUND: {"error": {"code": "NOT_FOUND", ...}}
 */

function getEndpointSampleConference(string $id, string $name): Conference
{
    return new Conference(
        conferenceId: $id,
        name: $name,
        trackName: '一般 CfP',
        officialUrl: 'https://example.com',
        cfpUrl: 'https://example.com/cfp',
        eventStartDate: '2026-09-19',
        eventEndDate: '2026-09-20',
        venue: '東京',
        format: ConferenceFormat::Offline,
        cfpStartDate: '2026-05-01',
        cfpEndDate: '2026-07-15',
        categories: ['1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02'],
        description: '説明文',
        themeColor: '#777BB4',
        createdAt: '2026-04-15T10:30:00+09:00',
        updatedAt: '2026-04-15T10:30:00+09:00',
    );
}

it('GET /admin/api/conferences/{id} は 200 と data に Conference を返す', function () {
    // Given: GetConferenceUseCase が指定 ID の Conference を返すようコンテナで差し替える
    $id = '550e8400-e29b-41d4-a716-446655440000';
    $conference = getEndpointSampleConference($id, 'PHPカンファレンス');
    $useCase = Mockery::mock(GetConferenceUseCase::class);
    $useCase->shouldReceive('execute')->once()->with($id)->andReturn($conference);
    app()->instance(GetConferenceUseCase::class, $useCase);

    // When: GET /admin/api/conferences/{id}
    $response = $this->getJson("/admin/api/conferences/{$id}");

    // Then: 200 + {data: <Conference>} で主要フィールドが返る
    $response->assertStatus(200);
    $response->assertJsonPath('data.conferenceId', $id);
    $response->assertJsonPath('data.name', 'PHPカンファレンス');
    $response->assertJsonPath('data.format', 'offline');
    $response->assertJsonPath('data.categories.0', '1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02');
});

it('GET /admin/api/conferences/{id} は該当無しなら 404 + NOT_FOUND', function () {
    // Given: GetConferenceUseCase が ConferenceNotFoundException を投げる
    $id = '550e8400-e29b-41d4-a716-446655440000';
    $useCase = Mockery::mock(GetConferenceUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->with($id)
        ->andThrow(ConferenceNotFoundException::withId($id));
    app()->instance(GetConferenceUseCase::class, $useCase);

    // When: GET /admin/api/conferences/{id}
    $response = $this->getJson("/admin/api/conferences/{$id}");

    // Then: 404 + NOT_FOUND に整形される (AdminApiExceptionRenderer の責務)
    $response->assertStatus(404);
    $response->assertJsonPath('error.code', 'NOT_FOUND');
});

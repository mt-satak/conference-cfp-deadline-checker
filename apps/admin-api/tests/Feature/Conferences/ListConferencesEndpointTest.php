<?php

use App\Application\Conferences\ListConferencesUseCase;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;

/**
 * GET /admin/api/conferences (operationId: listConferences) の Feature テスト。
 *
 * OpenAPI 仕様 (data/openapi.yaml):
 *   - 200 OK
 *   - body: {"data": [<Conference>, ...], "meta": {"count": N}}
 *
 * 本コミットの実装スコープは「全件取得 + 件数 meta」のみ。
 * ソート (?sort, ?order) / フィルタ (?q, ?category, ?status) は後続コミット
 * もしくは別 Issue で扱う。
 */

function listEndpointSampleConference(string $id, string $name): Conference
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

it('GET /admin/api/conferences は 200 と data 配列 + meta.count を返す', function () {
    // Given: UseCase が 2 件の Conference を返すようコンテナで差し替える
    $conferences = [
        listEndpointSampleConference('550e8400-e29b-41d4-a716-446655440000', 'A'),
        listEndpointSampleConference('660e8400-e29b-41d4-a716-446655440001', 'B'),
    ];
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn($conferences);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When: GET /admin/api/conferences
    $response = $this->getJson('/admin/api/conferences');

    // Then: 200 + data 配列 + meta.count
    $response->assertStatus(200);
    $response->assertJsonPath('data.0.conferenceId', '550e8400-e29b-41d4-a716-446655440000');
    $response->assertJsonPath('data.0.name', 'A');
    $response->assertJsonPath('data.1.conferenceId', '660e8400-e29b-41d4-a716-446655440001');
    $response->assertJsonPath('data.1.name', 'B');
    $response->assertJsonPath('meta.count', 2);
});

it('レスポンスの各 Conference は OpenAPI スキーマの主要フィールドを含む', function () {
    // Given: 1 件の Conference を返す UseCase
    $conference = listEndpointSampleConference('550e8400-e29b-41d4-a716-446655440000', 'PHPカンファレンス');
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn([$conference]);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When: GET /admin/api/conferences
    $response = $this->getJson('/admin/api/conferences');

    // Then: OpenAPI Conference スキーマの主要フィールドが含まれる
    $response->assertStatus(200);
    $response->assertJsonPath('data.0.conferenceId', '550e8400-e29b-41d4-a716-446655440000');
    $response->assertJsonPath('data.0.name', 'PHPカンファレンス');
    $response->assertJsonPath('data.0.trackName', '一般 CfP');
    $response->assertJsonPath('data.0.officialUrl', 'https://example.com');
    $response->assertJsonPath('data.0.cfpUrl', 'https://example.com/cfp');
    $response->assertJsonPath('data.0.eventStartDate', '2026-09-19');
    $response->assertJsonPath('data.0.eventEndDate', '2026-09-20');
    $response->assertJsonPath('data.0.venue', '東京');
    $response->assertJsonPath('data.0.format', 'offline');
    $response->assertJsonPath('data.0.cfpStartDate', '2026-05-01');
    $response->assertJsonPath('data.0.cfpEndDate', '2026-07-15');
    $response->assertJsonPath('data.0.categories.0', '1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02');
    $response->assertJsonPath('data.0.description', '説明文');
    $response->assertJsonPath('data.0.themeColor', '#777BB4');
    $response->assertJsonPath('data.0.createdAt', '2026-04-15T10:30:00+09:00');
    $response->assertJsonPath('data.0.updatedAt', '2026-04-15T10:30:00+09:00');
});

it('UseCase が空配列を返した場合は data: [], meta.count: 0 になる', function () {
    // Given: UseCase が空配列を返す
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn([]);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When: GET /admin/api/conferences
    $response = $this->getJson('/admin/api/conferences');

    // Then: 空のリストと count: 0
    $response->assertStatus(200);
    $response->assertJsonPath('meta.count', 0);
    expect($response->json('data'))->toBe([]);
});

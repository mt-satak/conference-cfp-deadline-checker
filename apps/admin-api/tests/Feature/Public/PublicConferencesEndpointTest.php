<?php

use App\Application\Conferences\ListConferencesUseCase;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceSortKey;
use App\Domain\Conferences\ConferenceStatus;
use App\Domain\Conferences\SortOrder;

/**
 * GET /api/public/conferences の Feature テスト (Issue #91 / Phase 4.1)。
 *
 * 公開フロント (Astro) のビルド時に fetch される read-only API。
 *  - 認証なし (= 誰でも GET 可能)
 *  - ただし CloudFront 経由のみ (= CloudFrontSecretMiddleware で直アクセス防御)
 *  - 常に Published のみ返す (Draft は除外)
 *  - cfpEndDate 昇順、null は末尾 (= ListConferencesUseCase のデフォルト)
 *  - レスポンス shape は admin/api と同じ {data: [...], meta: {count}}
 */
function publicEndpointSampleConference(string $id, string $name): Conference
{
    return new Conference(
        conferenceId: $id,
        name: $name,
        trackName: null,
        officialUrl: 'https://example.com',
        cfpUrl: 'https://example.com/cfp',
        eventStartDate: '2026-09-19',
        eventEndDate: '2026-09-20',
        venue: '東京',
        format: ConferenceFormat::Offline,
        cfpStartDate: null,
        cfpEndDate: '2026-07-15',
        categories: ['1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02'],
        description: null,
        themeColor: null,
        createdAt: '2026-04-15T10:30:00+09:00',
        updatedAt: '2026-04-15T10:30:00+09:00',
    );
}

it('GET /api/public/conferences は 200 と data 配列 + meta.count を返す', function () {
    // Given: UseCase が 2 件の Published Conference を返すようコンテナで差し替える
    $conferences = [
        publicEndpointSampleConference('550e8400-e29b-41d4-a716-446655440000', 'A'),
        publicEndpointSampleConference('660e8400-e29b-41d4-a716-446655440001', 'B'),
    ];
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase
        ->shouldReceive('execute')
        ->once()
        ->with([ConferenceStatus::Published], ConferenceSortKey::CfpEndDate, SortOrder::Asc)
        ->andReturn($conferences);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When
    $response = $this->getJson('/api/public/conferences');

    // Then
    $response->assertStatus(200);
    $response->assertJsonPath('data.0.conferenceId', '550e8400-e29b-41d4-a716-446655440000');
    $response->assertJsonPath('data.1.conferenceId', '660e8400-e29b-41d4-a716-446655440001');
    $response->assertJsonPath('meta.count', 2);
});

it('レスポンスの各 Conference は admin/api と同じ shape (ConferencePresenter) で返す', function () {
    // Given
    $conference = publicEndpointSampleConference('550e8400-e29b-41d4-a716-446655440000', 'PHP Conference Japan');
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase
        ->shouldReceive('execute')
        ->once()
        ->andReturn([$conference]);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When
    $response = $this->getJson('/api/public/conferences');

    // Then: ConferencePresenter::toArray の主要フィールドが揃う
    $response->assertStatus(200);
    $response->assertJsonPath('data.0.conferenceId', '550e8400-e29b-41d4-a716-446655440000');
    $response->assertJsonPath('data.0.name', 'PHP Conference Japan');
    $response->assertJsonPath('data.0.officialUrl', 'https://example.com');
    $response->assertJsonPath('data.0.cfpEndDate', '2026-07-15');
    $response->assertJsonPath('data.0.format', 'offline');
    $response->assertJsonPath('data.0.categories.0', '1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02');
});

it('UseCase が空配列を返す場合は data: [] と meta.count: 0 を返す', function () {
    // Given
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase
        ->shouldReceive('execute')
        ->once()
        ->andReturn([]);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When
    $response = $this->getJson('/api/public/conferences');

    // Then
    $response->assertStatus(200);
    $response->assertJsonPath('data', []);
    $response->assertJsonPath('meta.count', 0);
});

it('CloudFrontSecretMiddleware が掛かっており、Custom Origin Header 不一致時は 403', function () {
    // Given: middleware が動作するよう secret を設定
    config(['cloudfront.origin_secret' => 'expected-secret-value']);

    // When: 不正な header で叩く
    $response = $this->withHeaders([
        'X-CloudFront-Secret' => 'wrong-secret',
    ])->getJson('/api/public/conferences');

    // Then: middleware が 403 を返す (= UseCase まで到達しない)
    $response->assertStatus(403);
});

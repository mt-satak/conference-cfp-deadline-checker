<?php

use App\Application\Conferences\GetConferenceUseCase;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceNotFoundException;
use App\Domain\Conferences\ConferenceRepository;

/**
 * GetConferenceUseCase の単体テスト。
 *
 * 責務: Repository->findById() で取得し、存在しなければ
 * ConferenceNotFoundException を投げる。
 */

function getUseCaseSampleConference(string $id): Conference
{
    return new Conference(
        conferenceId: $id,
        name: 'Sample',
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

it('Repository が Conference を返したらそれをそのまま返す', function () {
    // Given: Repository->findById() が指定 ID の Conference を返すようモックする
    $id = '550e8400-e29b-41d4-a716-446655440000';
    $expected = getUseCaseSampleConference($id);
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findById')->once()->with($id)->andReturn($expected);

    // When: UseCase を実行する
    $useCase = new GetConferenceUseCase($repository);
    $result = $useCase->execute($id);

    // Then: Repository の戻りがそのまま返る
    expect($result)->toBe($expected);
});

it('Repository が null を返したら ConferenceNotFoundException を投げる', function () {
    // Given: Repository->findById() が null を返すようモックする (該当無し)
    $id = '550e8400-e29b-41d4-a716-446655440000';
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findById')->once()->with($id)->andReturn(null);

    // When / Then: UseCase 実行で ConferenceNotFoundException が投げられる
    $useCase = new GetConferenceUseCase($repository);
    expect(fn () => $useCase->execute($id))
        ->toThrow(ConferenceNotFoundException::class);
});

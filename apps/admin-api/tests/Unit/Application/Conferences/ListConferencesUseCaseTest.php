<?php

use App\Application\Conferences\ListConferencesUseCase;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceRepository;

/**
 * ListConferencesUseCase の単体テスト。
 *
 * UseCase の責務は「Repository から全件取得して呼び出し元に返す」のみ。
 * フィルタ・ソート・ページネーション等は呼び出し側 (HTTP コントローラ等) で行う方針なので
 * UseCase 自身では何もしない。
 */

function listUseCaseSampleConference(string $id, string $name): Conference
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

it('Repository->findAll() の結果をそのまま返す', function () {
    $expected = [
        listUseCaseSampleConference('550e8400-e29b-41d4-a716-446655440000', 'A'),
        listUseCaseSampleConference('660e8400-e29b-41d4-a716-446655440001', 'B'),
    ];

    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findAll')->once()->andReturn($expected);

    $useCase = new ListConferencesUseCase($repository);

    expect($useCase->execute())->toBe($expected);
});

it('Repository が空配列を返した場合は空配列をそのまま返す', function () {
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findAll')->once()->andReturn([]);

    $useCase = new ListConferencesUseCase($repository);

    expect($useCase->execute())->toBe([]);
});

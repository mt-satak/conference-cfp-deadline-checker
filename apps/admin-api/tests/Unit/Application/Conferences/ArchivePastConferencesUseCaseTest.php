<?php

declare(strict_types=1);

use App\Application\Conferences\ArchivePastConferencesUseCase;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceRepository;
use App\Domain\Conferences\ConferenceStatus;

/**
 * ArchivePastConferencesUseCase のユニットテスト (Issue #165 Phase 2)。
 *
 * 責務:
 * - Repository から全件取得し、Published かつ過去の Conference を Archived に遷移
 * - dry-run mode では save() を呼ばず、対象一覧のみ返す
 *
 * 過去判定 (eventEndDate / eventStartDate と today の比較) の詳細は
 * Conference::isPastEvent のドメインテストでカバーするため、本テストは
 * 「どの Conference が archive 対象になるか」「dry-run で save が呼ばれない」
 * 等の UseCase 固有の振る舞いのみ検証する。
 */
function makePastUseCaseConference(
    string $id,
    string $name,
    ConferenceStatus $status,
    ?string $eventEndDate,
    ?string $eventStartDate = null,
): Conference {
    return new Conference(
        conferenceId: $id,
        name: $name,
        trackName: null,
        officialUrl: 'https://example.com',
        cfpUrl: null,
        eventStartDate: $eventStartDate,
        eventEndDate: $eventEndDate,
        venue: null,
        format: ConferenceFormat::Offline,
        cfpStartDate: null,
        cfpEndDate: null,
        categories: [],
        description: null,
        themeColor: null,
        createdAt: '2026-04-01T10:00:00+09:00',
        updatedAt: '2026-04-01T10:00:00+09:00',
        status: $status,
    );
}

it('Published かつ eventEndDate < today なら Archived に遷移する', function () {
    // Given: Published + 過去開催 1 件
    $past = makePastUseCaseConference('past-1', '過去カンファ', ConferenceStatus::Published, '2026-05-07');
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findAll')->once()->andReturn([$past]);

    // 期待: status=Archived の Conference で save() が呼ばれる
    $repository->shouldReceive('save')
        ->once()
        ->with(Mockery::on(function (Conference $c) use ($past): bool {
            return $c->conferenceId === $past->conferenceId
                && $c->status === ConferenceStatus::Archived;
        }));

    // When
    $useCase = new ArchivePastConferencesUseCase($repository);
    $result = $useCase->execute(today: '2026-05-09', dryRun: false);

    // Then
    expect($result->totalChecked)->toBe(1);
    expect($result->archivedCount)->toBe(1);
    expect($result->archivedIds)->toBe(['past-1']);
    expect($result->dryRun)->toBeFalse();
});

it('Draft / Archived は対象外 (= Published のみが archive 候補)', function () {
    // Given: Draft 過去 / Archived 過去 / Published 過去
    $draft = makePastUseCaseConference('draft-1', 'Draft 過去', ConferenceStatus::Draft, '2026-05-07');
    $alreadyArchived = makePastUseCaseConference('arch-1', '既 Archived', ConferenceStatus::Archived, '2026-05-07');
    $publishedPast = makePastUseCaseConference('pub-1', 'Pub 過去', ConferenceStatus::Published, '2026-05-07');
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findAll')->once()->andReturn([$draft, $alreadyArchived, $publishedPast]);

    // 期待: pub-1 のみ archive される (= save 1 回)
    $repository->shouldReceive('save')
        ->once()
        ->with(Mockery::on(fn (Conference $c) => $c->conferenceId === 'pub-1'));

    // When
    $useCase = new ArchivePastConferencesUseCase($repository);
    $result = $useCase->execute(today: '2026-05-09', dryRun: false);

    // Then
    expect($result->archivedCount)->toBe(1);
    expect($result->archivedIds)->toBe(['pub-1']);
});

it('Published で eventEndDate > today なら対象外 (= 未来開催は温存)', function () {
    // Given: Published + 未来 1 件
    $future = makePastUseCaseConference('fut-1', '未来カンファ', ConferenceStatus::Published, '2026-06-30');
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findAll')->once()->andReturn([$future]);

    // save は呼ばれない (= shouldNotReceive)
    $repository->shouldNotReceive('save');

    // When
    $useCase = new ArchivePastConferencesUseCase($repository);
    $result = $useCase->execute(today: '2026-05-09', dryRun: false);

    // Then
    expect($result->archivedCount)->toBe(0);
    expect($result->archivedIds)->toBe([]);
});

it('dryRun=true なら save は呼ばれず、対象 ID のみ返す', function () {
    // Given: 過去 Published 2 件
    $past1 = makePastUseCaseConference('past-1', 'A', ConferenceStatus::Published, '2026-05-07');
    $past2 = makePastUseCaseConference('past-2', 'B', ConferenceStatus::Published, '2026-04-30');
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findAll')->once()->andReturn([$past1, $past2]);
    $repository->shouldNotReceive('save');

    // When
    $useCase = new ArchivePastConferencesUseCase($repository);
    $result = $useCase->execute(today: '2026-05-09', dryRun: true);

    // Then: ID は返るが save 無し
    expect($result->archivedCount)->toBe(2);
    expect($result->archivedIds)->toBe(['past-1', 'past-2']);
    expect($result->dryRun)->toBeTrue();
});

it('対象 0 件 (= 全件未来 or 全件 Draft) なら save 0 回 + count 0', function () {
    // Given: Draft 1 件 + Published 未来 1 件
    $draft = makePastUseCaseConference('d-1', 'Draft', ConferenceStatus::Draft, '2026-05-07');
    $future = makePastUseCaseConference('f-1', '未来', ConferenceStatus::Published, '2026-06-30');
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findAll')->once()->andReturn([$draft, $future]);
    $repository->shouldNotReceive('save');

    // When
    $useCase = new ArchivePastConferencesUseCase($repository);
    $result = $useCase->execute(today: '2026-05-09', dryRun: false);

    // Then
    expect($result->totalChecked)->toBe(2);
    expect($result->archivedCount)->toBe(0);
    expect($result->archivedIds)->toBe([]);
});

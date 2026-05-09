<?php

declare(strict_types=1);

use App\Application\Conferences\DedupeDraftsUseCase;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceRepository;
use App\Domain\Conferences\ConferenceStatus;

/**
 * DedupeDraftsUseCase の単体テスト (Issue #169 Phase 2)。
 *
 * 責務:
 * - 全 Draft Conference を取得
 * - OfficialUrl::normalize でグルーピング
 * - 同 URL に複数 Draft があれば最新 createdAt の 1 件を残し、それ以外を deleteById で削除
 *
 * 重複グルーピングの判定は OfficialUrl::normalize に依存するため、URL 表記揺れ
 * (https/http, trailing slash, www., query/fragment) も同一視される。
 */
function makeDedupeDraft(
    string $id,
    string $officialUrl,
    string $createdAt,
    ConferenceStatus $status = ConferenceStatus::Draft,
): Conference {
    return new Conference(
        conferenceId: $id,
        name: 'Conf '.$id,
        trackName: null,
        officialUrl: $officialUrl,
        cfpUrl: null,
        eventStartDate: null,
        eventEndDate: null,
        venue: null,
        format: ConferenceFormat::Offline,
        cfpStartDate: null,
        cfpEndDate: null,
        categories: [],
        description: null,
        themeColor: null,
        createdAt: $createdAt,
        updatedAt: $createdAt,
        status: $status,
    );
}

it('同じ URL の Draft が複数あれば最新 createdAt を残し古い方を削除する', function () {
    // Given: 同 URL の Draft 2 件 (5/8 古い、5/9 新しい)
    $old = makeDedupeDraft('draft-old', 'https://a.example.com/', '2026-05-08T10:00:00+09:00');
    $new = makeDedupeDraft('draft-new', 'https://a.example.com/', '2026-05-09T10:00:00+09:00');

    $repo = Mockery::mock(ConferenceRepository::class);
    $repo->shouldReceive('findAll')->once()->andReturn([$old, $new]);
    // 古い方が削除される
    $repo->shouldReceive('deleteById')
        ->once()
        ->with('draft-old')
        ->andReturn(true);

    // When
    $useCase = new DedupeDraftsUseCase($repo);
    $result = $useCase->execute(dryRun: false);

    // Then
    expect($result->totalDrafts)->toBe(2);
    expect($result->duplicateGroups)->toBe(1);
    expect($result->deletedCount)->toBe(1);
    expect($result->deletedIds)->toBe(['draft-old']);
    expect($result->dryRun)->toBeFalse();
});

it('複数の重複グループを跨いで適切に削除する', function () {
    // Given: 2 グループ × 各 2 件
    $a1 = makeDedupeDraft('a-old', 'https://a.example.com/', '2026-05-08T10:00:00+09:00');
    $a2 = makeDedupeDraft('a-new', 'https://a.example.com/', '2026-05-09T10:00:00+09:00');
    $b1 = makeDedupeDraft('b-old', 'https://b.example.com/', '2026-05-07T10:00:00+09:00');
    $b2 = makeDedupeDraft('b-new', 'https://b.example.com/', '2026-05-09T15:00:00+09:00');

    $repo = Mockery::mock(ConferenceRepository::class);
    $repo->shouldReceive('findAll')->once()->andReturn([$a1, $a2, $b1, $b2]);
    $repo->shouldReceive('deleteById')->once()->with('a-old')->andReturn(true);
    $repo->shouldReceive('deleteById')->once()->with('b-old')->andReturn(true);

    // When
    $useCase = new DedupeDraftsUseCase($repo);
    $result = $useCase->execute(dryRun: false);

    // Then
    expect($result->totalDrafts)->toBe(4);
    expect($result->duplicateGroups)->toBe(2);
    expect($result->deletedCount)->toBe(2);
    expect($result->deletedIds)->toContain('a-old');
    expect($result->deletedIds)->toContain('b-old');
});

it('重複が無ければ何も削除しない', function () {
    // Given: 全 Draft が異なる URL
    $a = makeDedupeDraft('a', 'https://a.example.com/', '2026-05-08T10:00:00+09:00');
    $b = makeDedupeDraft('b', 'https://b.example.com/', '2026-05-08T10:00:00+09:00');

    $repo = Mockery::mock(ConferenceRepository::class);
    $repo->shouldReceive('findAll')->once()->andReturn([$a, $b]);
    $repo->shouldNotReceive('deleteById');

    // When
    $useCase = new DedupeDraftsUseCase($repo);
    $result = $useCase->execute(dryRun: false);

    // Then
    expect($result->totalDrafts)->toBe(2);
    expect($result->duplicateGroups)->toBe(0);
    expect($result->deletedCount)->toBe(0);
});

it('Published / Archived は対象外 (= Draft 同士の重複のみ判定)', function () {
    // Given: 同 URL の Published 1 件 + Draft 1 件 (= AutoCrawl パターン、これは重複ではない)
    $pub = makeDedupeDraft('pub-1', 'https://a.example.com/', '2026-04-01T10:00:00+09:00', ConferenceStatus::Published);
    $draft = makeDedupeDraft('draft-1', 'https://a.example.com/', '2026-05-08T10:00:00+09:00', ConferenceStatus::Draft);

    $repo = Mockery::mock(ConferenceRepository::class);
    $repo->shouldReceive('findAll')->once()->andReturn([$pub, $draft]);
    $repo->shouldNotReceive('deleteById');

    // When
    $useCase = new DedupeDraftsUseCase($repo);
    $result = $useCase->execute(dryRun: false);

    // Then: Draft グループは 1 件しか無いので重複扱いされない
    expect($result->totalDrafts)->toBe(1);
    expect($result->duplicateGroups)->toBe(0);
    expect($result->deletedCount)->toBe(0);
});

it('URL 表記揺れがあっても OfficialUrl::normalize で同一視する', function () {
    // Given: 同じカンファだが trailing slash / www. / scheme で表記が違う
    $a = makeDedupeDraft('a-old', 'http://www.x.example.com/2026/?utm=x', '2026-05-08T10:00:00+09:00');
    $b = makeDedupeDraft('a-new', 'https://x.example.com/2026', '2026-05-09T10:00:00+09:00');

    $repo = Mockery::mock(ConferenceRepository::class);
    $repo->shouldReceive('findAll')->once()->andReturn([$a, $b]);
    $repo->shouldReceive('deleteById')->once()->with('a-old')->andReturn(true);

    // When
    $useCase = new DedupeDraftsUseCase($repo);
    $result = $useCase->execute(dryRun: false);

    // Then
    expect($result->duplicateGroups)->toBe(1);
    expect($result->deletedIds)->toBe(['a-old']);
});

it('dryRun=true なら deleteById は呼ばれず、削除予定 ID のみ返す', function () {
    // Given: 重複あり
    $old = makeDedupeDraft('draft-old', 'https://a.example.com/', '2026-05-08T10:00:00+09:00');
    $new = makeDedupeDraft('draft-new', 'https://a.example.com/', '2026-05-09T10:00:00+09:00');

    $repo = Mockery::mock(ConferenceRepository::class);
    $repo->shouldReceive('findAll')->once()->andReturn([$old, $new]);
    $repo->shouldNotReceive('deleteById');

    // When
    $useCase = new DedupeDraftsUseCase($repo);
    $result = $useCase->execute(dryRun: true);

    // Then
    expect($result->deletedCount)->toBe(1);
    expect($result->deletedIds)->toBe(['draft-old']);
    expect($result->dryRun)->toBeTrue();
});

it('Draft が 0 件 (= 全件 Published) でも安全に動く', function () {
    // Given
    $pub = makeDedupeDraft('pub-1', 'https://a.example.com/', '2026-04-01T10:00:00+09:00', ConferenceStatus::Published);

    $repo = Mockery::mock(ConferenceRepository::class);
    $repo->shouldReceive('findAll')->once()->andReturn([$pub]);
    $repo->shouldNotReceive('deleteById');

    // When
    $useCase = new DedupeDraftsUseCase($repo);
    $result = $useCase->execute(dryRun: false);

    // Then
    expect($result->totalDrafts)->toBe(0);
    expect($result->duplicateGroups)->toBe(0);
    expect($result->deletedCount)->toBe(0);
});

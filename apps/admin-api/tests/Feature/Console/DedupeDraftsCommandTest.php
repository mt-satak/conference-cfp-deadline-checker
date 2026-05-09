<?php

declare(strict_types=1);

use App\Application\Conferences\DedupeDraftsResult;
use App\Application\Conferences\DedupeDraftsUseCase;

/**
 * artisan conferences:dedupe-drafts の Feature テスト (Issue #169 Phase 2)。
 *
 * Command 自体は薄く UseCase に委譲するだけなので、UseCase を Mock して
 * 出力 / exit code を検証する。
 */
it('artisan conferences:dedupe-drafts は UseCase を呼んで件数を表示する', function () {
    // Given: UseCase の戻り値をモック (= 2 件削除)
    $result = new DedupeDraftsResult(
        totalDrafts: 5,
        duplicateGroups: 2,
        deletedCount: 2,
        deletedIds: ['old-1', 'old-2'],
        dryRun: false,
    );
    $useCase = Mockery::mock(DedupeDraftsUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn($result);
    app()->instance(DedupeDraftsUseCase::class, $useCase);

    // When / Then
    $this->artisan('conferences:dedupe-drafts')
        ->expectsOutputToContain('Draft 総数: 5')
        ->expectsOutputToContain('重複グループ: 2')
        ->expectsOutputToContain('削除件数: 2')
        ->assertExitCode(0);
});

it('artisan conferences:dedupe-drafts --dry-run は dry-run モードで UseCase を呼ぶ', function () {
    // Given
    $result = new DedupeDraftsResult(
        totalDrafts: 5,
        duplicateGroups: 2,
        deletedCount: 2,
        deletedIds: ['old-1', 'old-2'],
        dryRun: true,
    );
    $useCase = Mockery::mock(DedupeDraftsUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->with(true)
        ->andReturn($result);
    app()->instance(DedupeDraftsUseCase::class, $useCase);

    // When / Then
    $this->artisan('conferences:dedupe-drafts --dry-run')
        ->expectsOutputToContain('(dry-run)')
        ->assertExitCode(0);
});

it('artisan conferences:dedupe-drafts は重複 0 件でも正常終了する', function () {
    // Given
    $result = new DedupeDraftsResult(
        totalDrafts: 3,
        duplicateGroups: 0,
        deletedCount: 0,
        deletedIds: [],
        dryRun: false,
    );
    $useCase = Mockery::mock(DedupeDraftsUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn($result);
    app()->instance(DedupeDraftsUseCase::class, $useCase);

    // When / Then
    $this->artisan('conferences:dedupe-drafts')
        ->expectsOutputToContain('削除件数: 0')
        ->assertExitCode(0);
});

<?php

declare(strict_types=1);

use App\Application\Conferences\CleanupAutoCrawlDrafts\CleanupAutoCrawlDraftsResult;
use App\Application\Conferences\CleanupAutoCrawlDrafts\CleanupAutoCrawlDraftsUseCase;

/**
 * artisan conferences:cleanup-autocrawl-drafts の Feature テスト (Issue #188 PR-2)。
 *
 * Command 自体は薄く UseCase に委譲するだけなので、UseCase を Mock して
 * 出力 / exit code / dryRun フラグの渡し方を検証する。
 */
it('引数なしは dry-run で実行 (= UseCase に dryRun=true を渡す)', function () {
    // Given: dry-run 結果 (候補 2 件)
    $result = new CleanupAutoCrawlDraftsResult(
        dryRun: true,
        candidateIds: ['draft-1', 'draft-2'],
        deletedIds: [],
    );
    $useCase = Mockery::mock(CleanupAutoCrawlDraftsUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->with(true)
        ->andReturn($result);
    app()->instance(CleanupAutoCrawlDraftsUseCase::class, $useCase);

    // When / Then
    $this->artisan('conferences:cleanup-autocrawl-drafts')
        ->expectsOutputToContain('dry-run')
        ->expectsOutputToContain('削除候補件数: 2')
        ->expectsOutputToContain('draft-1')
        ->expectsOutputToContain('draft-2')
        ->assertExitCode(0);
});

it('--apply は実削除モード (= UseCase に dryRun=false を渡す)', function () {
    // Given: apply 結果 (候補 2 件全削除成功)
    $result = new CleanupAutoCrawlDraftsResult(
        dryRun: false,
        candidateIds: ['draft-1', 'draft-2'],
        deletedIds: ['draft-1', 'draft-2'],
    );
    $useCase = Mockery::mock(CleanupAutoCrawlDraftsUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->with(false)
        ->andReturn($result);
    app()->instance(CleanupAutoCrawlDraftsUseCase::class, $useCase);

    // When / Then
    $this->artisan('conferences:cleanup-autocrawl-drafts --apply')
        ->expectsOutputToContain('apply')
        ->expectsOutputToContain('削除候補件数: 2')
        ->expectsOutputToContain('削除済件数: 2')
        ->assertExitCode(0);
});

it('候補 0 件 (idempotent な再実行) でも正常終了する', function () {
    // Given: 候補 0 件
    $result = new CleanupAutoCrawlDraftsResult(
        dryRun: true,
        candidateIds: [],
        deletedIds: [],
    );
    $useCase = Mockery::mock(CleanupAutoCrawlDraftsUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn($result);
    app()->instance(CleanupAutoCrawlDraftsUseCase::class, $useCase);

    // When / Then
    $this->artisan('conferences:cleanup-autocrawl-drafts')
        ->expectsOutputToContain('削除候補件数: 0')
        ->assertExitCode(0);
});

it('--apply 中に一部の deleteById が失敗しても skipped を表示して正常終了する', function () {
    // Given: 候補 2 件のうち 1 件削除失敗 (= deleteById が false 返した想定)
    $result = new CleanupAutoCrawlDraftsResult(
        dryRun: false,
        candidateIds: ['draft-1', 'draft-2'],
        deletedIds: ['draft-1'],  // draft-2 だけ失敗
    );
    $useCase = Mockery::mock(CleanupAutoCrawlDraftsUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn($result);
    app()->instance(CleanupAutoCrawlDraftsUseCase::class, $useCase);

    // When / Then: skipped に draft-2 が含まれる
    $this->artisan('conferences:cleanup-autocrawl-drafts --apply')
        ->expectsOutputToContain('削除済件数: 1')
        ->expectsOutputToContain('削除に失敗した候補')
        ->expectsOutputToContain('draft-2')
        ->assertExitCode(0);
});

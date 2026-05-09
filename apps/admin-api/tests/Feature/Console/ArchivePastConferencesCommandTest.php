<?php

declare(strict_types=1);

use App\Application\Conferences\ArchivePastConferencesUseCase;
use App\Application\Conferences\ArchivePastResult;

/**
 * artisan conferences:archive-past の Feature テスト (Issue #165 Phase 2)。
 *
 * Command 自体は薄く UseCase に委譲するだけなので、UseCase を Mock して
 * 出力 / exit code を検証する。
 */
it('artisan conferences:archive-past は UseCase を呼んで件数を表示する', function () {
    // Given: UseCase の戻り値をモック (= 2 件 archived)
    $result = new ArchivePastResult(
        totalChecked: 5,
        archivedCount: 2,
        archivedIds: ['id-past-1', 'id-past-2'],
        dryRun: false,
    );
    $useCase = Mockery::mock(ArchivePastConferencesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn($result);
    app()->instance(ArchivePastConferencesUseCase::class, $useCase);

    // When / Then
    $this->artisan('conferences:archive-past')
        ->expectsOutputToContain('チェック件数: 5')
        ->expectsOutputToContain('アーカイブ件数: 2')
        ->assertExitCode(0);
});

it('artisan conferences:archive-past --dry-run は dry-run モードで UseCase を呼ぶ', function () {
    // Given
    $result = new ArchivePastResult(
        totalChecked: 5,
        archivedCount: 2,
        archivedIds: ['id-past-1', 'id-past-2'],
        dryRun: true,
    );
    $useCase = Mockery::mock(ArchivePastConferencesUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->with(Mockery::any(), true)
        ->andReturn($result);
    app()->instance(ArchivePastConferencesUseCase::class, $useCase);

    // When / Then
    $this->artisan('conferences:archive-past --dry-run')
        ->expectsOutputToContain('(dry-run)')
        ->assertExitCode(0);
});

it('artisan conferences:archive-past は対象 0 件でも正常終了する', function () {
    // Given
    $result = new ArchivePastResult(
        totalChecked: 5,
        archivedCount: 0,
        archivedIds: [],
        dryRun: false,
    );
    $useCase = Mockery::mock(ArchivePastConferencesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn($result);
    app()->instance(ArchivePastConferencesUseCase::class, $useCase);

    // When / Then
    $this->artisan('conferences:archive-past')
        ->expectsOutputToContain('アーカイブ件数: 0')
        ->assertExitCode(0);
});

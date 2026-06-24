<?php

declare(strict_types=1);

use App\Application\Conferences\DeletePast\DeletePastConferencesResult;
use App\Application\Conferences\DeletePast\DeletePastConferencesUseCase;

/**
 * artisan conferences:delete-past の Feature テスト (Issue #221 PR-1)。
 *
 * Command 自体は薄く UseCase に委譲するだけなので、UseCase を Mock して
 * 出力 / exit code / dry-run フラグの渡し方を検証する。
 */
it('引数なしは dry-run (= UseCase に dryRun=true を渡す)', function () {
    // Given
    $result = new DeletePastConferencesResult(
        totalChecked: 5,
        deletedCount: 2,
        deletedIds: ['id-past-1', 'id-past-2'],
        dryRun: true,
    );
    $useCase = Mockery::mock(DeletePastConferencesUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->with(Mockery::any(), true)
        ->andReturn($result);
    app()->instance(DeletePastConferencesUseCase::class, $useCase);

    // When / Then
    // NOTE: 「削除件数: 2 (dry-run)」のように 1 行に dry-run ラベルと件数が同居するため、
    //       '(dry-run)' 単体と '削除件数: 2' を別々に assert すると Laravel の substring
    //       マッチが同じ行を取り合って競合する。行ごとに一意な部分文字列で検証する。
    $this->artisan('conferences:delete-past')
        ->expectsOutputToContain('チェック件数: 5')
        ->expectsOutputToContain('削除件数: 2 (dry-run)')
        ->expectsOutputToContain('--apply で実行してください')
        ->assertExitCode(0);
});

it('--apply は実削除モード (= UseCase に dryRun=false を渡す)', function () {
    // Given
    $result = new DeletePastConferencesResult(
        totalChecked: 5,
        deletedCount: 3,
        deletedIds: ['id-1', 'id-2', 'id-3'],
        dryRun: false,
    );
    $useCase = Mockery::mock(DeletePastConferencesUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->with(Mockery::any(), false)
        ->andReturn($result);
    app()->instance(DeletePastConferencesUseCase::class, $useCase);

    // When / Then
    $this->artisan('conferences:delete-past --apply')
        ->expectsOutputToContain('削除件数: 3')
        ->assertExitCode(0);
});

it('--today で基準日を上書きして UseCase に渡す', function () {
    // Given
    $result = new DeletePastConferencesResult(
        totalChecked: 1,
        deletedCount: 0,
        deletedIds: [],
        dryRun: true,
    );
    $captured = null;
    $useCase = Mockery::mock(DeletePastConferencesUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->with(Mockery::on(function ($today) use (&$captured): bool {
            $captured = $today;

            return true;
        }), Mockery::any())
        ->andReturn($result);
    app()->instance(DeletePastConferencesUseCase::class, $useCase);

    // When / Then
    $this->artisan('conferences:delete-past --today=2026-06-24')
        ->assertExitCode(0);
    expect($captured)->toBe('2026-06-24');
});

it('削除対象 0 件のときはスキップ表示で正常終了する', function () {
    // Given
    $result = new DeletePastConferencesResult(
        totalChecked: 5,
        deletedCount: 0,
        deletedIds: [],
        dryRun: false,
    );
    $useCase = Mockery::mock(DeletePastConferencesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn($result);
    app()->instance(DeletePastConferencesUseCase::class, $useCase);

    // When / Then
    $this->artisan('conferences:delete-past --apply')
        ->expectsOutputToContain('削除対象はありませんでした')
        ->assertExitCode(0);
});

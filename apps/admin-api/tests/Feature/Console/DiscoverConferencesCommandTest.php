<?php

declare(strict_types=1);

use App\Application\Conferences\Discovery\DiscoverConferencesResult;
use App\Application\Conferences\Discovery\DiscoverConferencesUseCase;

/**
 * artisan conferences:discover-new の Feature テスト (Issue #200 PR-3)。
 *
 * Command 自体は薄く UseCase に委譲するだけなので、UseCase を Mockery で差し替えて
 * 出力 / exit code / dryRun フラグの渡し方を検証する。
 */
it('引数なしは dry-run (= UseCase に dryRun=true を渡す)', function () {
    // Given
    $result = new DiscoverConferencesResult(
        dryRun: true,
        totalSources: 2,
        sourcesFailed: 0,
        totalCandidateUrls: 5,
        newCandidateUrls: 3,
        draftsCreated: 0,
        extractionFailed: 0,
        failedSourceUrls: [],
        createdDraftIds: [],
    );
    $useCase = Mockery::mock(DiscoverConferencesUseCase::class);
    $useCase->shouldReceive('execute')->once()->with(true)->andReturn($result);
    app()->instance(DiscoverConferencesUseCase::class, $useCase);

    // When/Then
    $this->artisan('conferences:discover-new')
        ->expectsOutputToContain('dry-run')
        ->expectsOutputToContain('巡回 source 数: 2')
        ->expectsOutputToContain('候補 URL 総数: 5')
        ->expectsOutputToContain('新規候補 URL: 3')
        ->expectsOutputToContain('pass --apply')
        ->assertExitCode(0);
});

it('--apply は実行モード (= UseCase に dryRun=false を渡す)', function () {
    // Given
    $result = new DiscoverConferencesResult(
        dryRun: false,
        totalSources: 1,
        sourcesFailed: 0,
        totalCandidateUrls: 3,
        newCandidateUrls: 3,
        draftsCreated: 2,
        extractionFailed: 1,
        failedSourceUrls: [],
        createdDraftIds: ['d-1', 'd-2'],
        officialFollowCount: 2,
    );
    $useCase = Mockery::mock(DiscoverConferencesUseCase::class);
    $useCase->shouldReceive('execute')->once()->with(false)->andReturn($result);
    app()->instance(DiscoverConferencesUseCase::class, $useCase);

    // When/Then
    $this->artisan('conferences:discover-new --apply')
        ->expectsOutputToContain('apply')
        ->expectsOutputToContain('Draft 作成数: 2')
        ->expectsOutputToContain('詳細抽出失敗: 1')
        ->expectsOutputToContain('公式リンク追加抽出: 2')
        ->assertExitCode(0);
});

it('0 件 (= source 0 or 候補 0) でも正常終了する', function () {
    // Given
    $result = new DiscoverConferencesResult(
        dryRun: true,
        totalSources: 0,
        sourcesFailed: 0,
        totalCandidateUrls: 0,
        newCandidateUrls: 0,
        draftsCreated: 0,
        extractionFailed: 0,
        failedSourceUrls: [],
        createdDraftIds: [],
    );
    $useCase = Mockery::mock(DiscoverConferencesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn($result);
    app()->instance(DiscoverConferencesUseCase::class, $useCase);

    // When/Then: 新規候補 0 件なので「dry-run のため...」warning は出ない
    $this->artisan('conferences:discover-new')
        ->expectsOutputToContain('新規候補 URL: 0')
        ->doesntExpectOutputToContain('pass --apply')
        ->assertExitCode(0);
});

it('source 失敗 URL は warning で列挙される', function () {
    // Given
    $result = new DiscoverConferencesResult(
        dryRun: true,
        totalSources: 2,
        sourcesFailed: 1,
        totalCandidateUrls: 0,
        newCandidateUrls: 0,
        draftsCreated: 0,
        extractionFailed: 0,
        failedSourceUrls: ['https://broken.example.com/'],
        createdDraftIds: [],
    );
    $useCase = Mockery::mock(DiscoverConferencesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn($result);
    app()->instance(DiscoverConferencesUseCase::class, $useCase);

    // When/Then
    $this->artisan('conferences:discover-new')
        ->expectsOutputToContain('失敗 source URL')
        ->expectsOutputToContain('https://broken.example.com/')
        ->assertExitCode(0);
});

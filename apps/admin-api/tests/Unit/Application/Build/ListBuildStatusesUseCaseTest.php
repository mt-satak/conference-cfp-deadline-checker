<?php

use App\Application\Build\ListBuildStatusesUseCase;
use App\Domain\Build\BuildJobStatus;
use App\Domain\Build\BuildServiceNotConfiguredException;
use App\Domain\Build\BuildStatus;
use App\Domain\Build\BuildStatusReader;

/**
 * ListBuildStatusesUseCase の単体テスト。
 */
it('Reader->listRecent() の結果をそのまま返す', function () {
    // Given: 2 件返す Reader モック
    $statuses = [
        new BuildStatus('job-1', BuildJobStatus::Succeed, '2026-05-04T10:00:00+09:00', null, null, null, null),
        new BuildStatus('job-2', BuildJobStatus::Running, '2026-05-04T09:00:00+09:00', null, null, null, null),
    ];
    $reader = Mockery::mock(BuildStatusReader::class);
    $reader->shouldReceive('listRecent')
        ->once()
        ->with(10)
        ->andReturn($statuses);

    // When
    $useCase = new ListBuildStatusesUseCase($reader);
    $result = $useCase->execute();

    // Then
    expect($result)->toHaveCount(2);
    expect($result[0]->jobId)->toBe('job-1');
});

it('limit を引数で渡せる', function () {
    // Given
    $reader = Mockery::mock(BuildStatusReader::class);
    $reader->shouldReceive('listRecent')->once()->with(3)->andReturn([]);

    // When
    $useCase = new ListBuildStatusesUseCase($reader);
    $result = $useCase->execute(3);

    // Then
    expect($result)->toBe([]);
});

it('Reader が BuildServiceNotConfiguredException を投げたらそのまま伝搬する', function () {
    // Given
    $reader = Mockery::mock(BuildStatusReader::class);
    $reader->shouldReceive('listRecent')
        ->once()
        ->andThrow(BuildServiceNotConfiguredException::appIdMissing());

    // When/Then
    $useCase = new ListBuildStatusesUseCase($reader);
    expect(fn () => $useCase->execute())
        ->toThrow(BuildServiceNotConfiguredException::class);
});

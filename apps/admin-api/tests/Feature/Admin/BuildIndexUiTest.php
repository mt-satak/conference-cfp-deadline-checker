<?php

use App\Application\Build\ListBuildStatusesUseCase;
use App\Domain\Build\BuildJobStatus;
use App\Domain\Build\BuildServiceNotConfiguredException;
use App\Domain\Build\BuildStatus;

/**
 * /admin/build (ビルド状態) の Feature テスト。
 */
beforeEach(function () {
    test()->withoutVite();
});

it('GET /admin/build は履歴を 200 で表示し、トリガーボタンを描画する', function () {
    // Given: 2 件返す
    $useCase = Mockery::mock(ListBuildStatusesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn([
        new BuildStatus(
            jobId: 'job-1',
            status: BuildJobStatus::Succeed,
            startedAt: '2026-05-04T10:00:00+09:00',
            commitId: 'abc1234567',
            commitMessage: 'fix typo',
            endedAt: '2026-05-04T10:02:00+09:00',
            triggerSource: null,
        ),
        new BuildStatus(
            jobId: 'job-2',
            status: BuildJobStatus::Running,
            startedAt: '2026-05-04T11:00:00+09:00',
            commitId: null,
            commitMessage: null,
            endedAt: null,
            triggerSource: null,
        ),
    ]);
    app()->instance(ListBuildStatusesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/build');

    // Then
    $response->assertStatus(200);
    $response->assertSee('ビルド状態', false);
    $response->assertSee('job-1', false);
    $response->assertSee('SUCCEED', false);
    $response->assertSee('RUNNING', false);
    $response->assertSee('再ビルドをトリガー', false);
    $response->assertSee('2 件', false);
});

it('GET /admin/build は 0 件で empty state を表示する', function () {
    // Given
    $useCase = Mockery::mock(ListBuildStatusesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn([]);
    app()->instance(ListBuildStatusesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/build');

    // Then
    $response->assertStatus(200);
    $response->assertSee('ビルド履歴がありません', false);
});

it('GET /admin/build は GitHub App 未構成時に警告枠 + トリガーボタン非表示', function () {
    // Given: BuildServiceNotConfiguredException
    $useCase = Mockery::mock(ListBuildStatusesUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->andThrow(BuildServiceNotConfiguredException::appIdMissing());
    app()->instance(ListBuildStatusesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/build');

    // Then: 200 だが警告 + トリガーボタンは出ない
    $response->assertStatus(200);
    $response->assertSee('ビルドサービスが未構成', false);
    $response->assertSee('GITHUB_APP_ID', false);
    $response->assertDontSee('再ビルドをトリガー', false);
});

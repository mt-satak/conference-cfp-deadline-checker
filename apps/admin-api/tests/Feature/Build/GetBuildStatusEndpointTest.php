<?php

use App\Application\Build\ListBuildStatusesUseCase;
use App\Domain\Build\BuildJobStatus;
use App\Domain\Build\BuildServiceNotConfiguredException;
use App\Domain\Build\BuildStatus;
use App\Domain\Build\BuildTriggerSource;

/**
 * GET /admin/api/build/status (operationId: getBuildStatus) の Feature テスト。
 *
 *   - 200 OK: {"data": [<BuildStatus>], "meta": {"latestStatus": ...}}
 *   - 503 SERVICE_UNAVAILABLE: GitHub App 未構成 (app_id / installation_id /
 *     private_key のいずれかが欠け)
 */
it('GET /admin/api/build/status は 200 と data に BuildStatus 配列 + meta.latestStatus を返す', function () {
    // Given: UseCase が 2 件返すモック (新しい順)
    $statuses = [
        new BuildStatus(
            jobId: 'job-1',
            status: BuildJobStatus::Running,
            startedAt: '2026-05-04T10:00:00+09:00',
            commitId: 'abc',
            commitMessage: 'fix',
            endedAt: null,
            triggerSource: BuildTriggerSource::AdminManual,
        ),
        new BuildStatus(
            jobId: 'job-2',
            status: BuildJobStatus::Succeed,
            startedAt: '2026-05-04T09:00:00+09:00',
            commitId: null,
            commitMessage: null,
            endedAt: '2026-05-04T09:02:00+09:00',
            triggerSource: null,
        ),
    ];
    $useCase = Mockery::mock(ListBuildStatusesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn($statuses);
    app()->instance(ListBuildStatusesUseCase::class, $useCase);

    // When
    $response = $this->getJson('/admin/api/build/status');

    // Then
    $response->assertStatus(200);
    $response->assertJsonPath('data.0.jobId', 'job-1');
    $response->assertJsonPath('data.0.status', 'RUNNING');
    $response->assertJsonPath('data.0.commitId', 'abc');
    $response->assertJsonPath('data.0.triggerSource', 'admin-manual');
    $response->assertJsonPath('data.1.jobId', 'job-2');
    $response->assertJsonPath('data.1.endedAt', '2026-05-04T09:02:00+09:00');
    // optional フィールド省略時は出力しない
    expect($response->json('data.1.commitId'))->toBeNull();
    expect($response->json('data.1.triggerSource'))->toBeNull();
    // meta.latestStatus は最新ジョブのステータス
    $response->assertJsonPath('meta.latestStatus', 'RUNNING');
});

it('GET /admin/api/build/status は 0 件でも 200 + 空配列 + meta.latestStatus 省略', function () {
    // Given
    $useCase = Mockery::mock(ListBuildStatusesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn([]);
    app()->instance(ListBuildStatusesUseCase::class, $useCase);

    // When
    $response = $this->getJson('/admin/api/build/status');

    // Then
    $response->assertStatus(200);
    $response->assertJsonPath('data', []);
    expect($response->json('meta.latestStatus'))->toBeNull();
});

it('GET /admin/api/build/status は appId 未構成で 503 + SERVICE_UNAVAILABLE', function () {
    // Given
    $useCase = Mockery::mock(ListBuildStatusesUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->andThrow(BuildServiceNotConfiguredException::appIdMissing());
    app()->instance(ListBuildStatusesUseCase::class, $useCase);

    // When
    $response = $this->getJson('/admin/api/build/status');

    // Then
    $response->assertStatus(503);
    $response->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');
});

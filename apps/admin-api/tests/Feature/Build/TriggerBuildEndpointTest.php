<?php

use App\Application\Build\TriggerBuildUseCase;
use App\Domain\Build\BuildServiceNotConfiguredException;
use App\Http\Middleware\VerifyOrigin;

beforeEach(function () {
    test()->withoutMiddleware(VerifyOrigin::class);
});

/**
 * POST /admin/api/build/trigger (operationId: triggerBuild) の Feature テスト。
 *
 *   - 202 Accepted: {"data": {requestedAt, message}}
 *   - 503 SERVICE_UNAVAILABLE: Amplify Webhook URL 未構成
 */
it('POST /admin/api/build/trigger は 202 と data に requestedAt + message を返す', function () {
    // Given: UseCase が受付時刻を返すモック
    $useCase = Mockery::mock(TriggerBuildUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn('2026-05-04T10:00:00+09:00');
    app()->instance(TriggerBuildUseCase::class, $useCase);

    // When
    $response = $this->postJson('/admin/api/build/trigger');

    // Then
    $response->assertStatus(202);
    $response->assertJsonPath('data.requestedAt', '2026-05-04T10:00:00+09:00');
    expect($response->json('data.message'))->toBeString();
});

it('POST /admin/api/build/trigger は webhook 未構成で 503 + SERVICE_UNAVAILABLE', function () {
    // Given
    $useCase = Mockery::mock(TriggerBuildUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->andThrow(BuildServiceNotConfiguredException::webhookUrlMissing());
    app()->instance(TriggerBuildUseCase::class, $useCase);

    // When
    $response = $this->postJson('/admin/api/build/trigger');

    // Then: AdminApiExceptionRenderer が 503 + SERVICE_UNAVAILABLE に整形
    $response->assertStatus(503);
    $response->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');
});

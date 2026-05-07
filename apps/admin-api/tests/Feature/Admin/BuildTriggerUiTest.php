<?php

use App\Application\Build\TriggerBuildUseCase;
use App\Domain\Build\BuildServiceNotConfiguredException;
use App\Http\Middleware\VerifyOrigin;

/**
 * POST /admin/build/trigger の Feature テスト。
 */
beforeEach(function () {
    test()->withoutVite();
    test()->withoutMiddleware(VerifyOrigin::class);
});

it('POST /admin/build/trigger は成功時に index にリダイレクト + フラッシュ', function () {
    // Given
    $useCase = Mockery::mock(TriggerBuildUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn('2026-05-04T10:00:00+09:00');
    app()->instance(TriggerBuildUseCase::class, $useCase);

    // When
    $response = $this->post('/admin/build/trigger');

    // Then
    $response->assertStatus(302);
    $response->assertRedirect('/admin/build');
    expect(session('status'))->toContain('ビルドをトリガー');
});

it('POST /admin/build/trigger は GitHub App 未構成時にエラーフラッシュで戻る', function () {
    // Given
    $useCase = Mockery::mock(TriggerBuildUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->andThrow(BuildServiceNotConfiguredException::appIdMissing());
    app()->instance(TriggerBuildUseCase::class, $useCase);

    // When
    $response = $this->post('/admin/build/trigger');

    // Then
    $response->assertStatus(302);
    $response->assertRedirect('/admin/build');
    expect(session('error'))->toContain('GitHub app ID is missing');
});

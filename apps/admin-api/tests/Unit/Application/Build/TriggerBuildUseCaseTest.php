<?php

use App\Application\Build\TriggerBuildUseCase;
use App\Domain\Build\BuildServiceNotConfiguredException;
use App\Domain\Build\BuildTriggerer;

/**
 * TriggerBuildUseCase の単体テスト。
 *
 * BuildTriggerer (interface) に処理を委譲するだけの薄い UseCase なので
 * 「委譲が成立する」「未構成例外がそのまま伝搬する」の 2 ケースで十分。
 */
it('Triggerer->trigger() の戻り値をそのまま返す', function () {
    // Given
    $triggerer = Mockery::mock(BuildTriggerer::class);
    $triggerer->shouldReceive('trigger')
        ->once()
        ->andReturn('2026-05-04T10:00:00+09:00');

    // When
    $useCase = new TriggerBuildUseCase($triggerer);
    $requestedAt = $useCase->execute();

    // Then
    expect($requestedAt)->toBe('2026-05-04T10:00:00+09:00');
});

it('Triggerer が BuildServiceNotConfiguredException を投げたらそのまま伝搬する', function () {
    // Given: 未構成例外
    $triggerer = Mockery::mock(BuildTriggerer::class);
    $triggerer->shouldReceive('trigger')
        ->once()
        ->andThrow(BuildServiceNotConfiguredException::webhookUrlMissing());

    // When/Then
    $useCase = new TriggerBuildUseCase($triggerer);
    expect(fn () => $useCase->execute())
        ->toThrow(BuildServiceNotConfiguredException::class);
});

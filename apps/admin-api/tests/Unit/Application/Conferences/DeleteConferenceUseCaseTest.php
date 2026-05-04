<?php

use App\Application\Conferences\DeleteConferenceUseCase;
use App\Domain\Conferences\ConferenceNotFoundException;
use App\Domain\Conferences\ConferenceRepository;

/**
 * DeleteConferenceUseCase の単体テスト。
 *
 * 責務:
 * - Repository->deleteById() を呼ぶ
 * - 戻り値が false (該当無し) の場合は ConferenceNotFoundException を投げる
 *
 * OpenAPI 仕様で DELETE は 204 (成功) または 404 (該当無し) を返すため、
 * 後者は HTTP 層で例外を 404 + NOT_FOUND に整形する想定。
 */

it('Repository->deleteById() が true を返したら例外なく完了する', function () {
    // Given: Repository->deleteById() が true (削除済み) を返すモック
    $id = '550e8400-e29b-41d4-a716-446655440000';
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('deleteById')->once()->with($id)->andReturn(true);

    // When / Then: UseCase 実行で例外は投げられない
    $useCase = new DeleteConferenceUseCase($repository);
    expect(fn () => $useCase->execute($id))->not->toThrow(\Throwable::class);
});

it('Repository->deleteById() が false を返したら ConferenceNotFoundException を投げる', function () {
    // Given: Repository->deleteById() が false (該当無し) を返すモック
    $id = '550e8400-e29b-41d4-a716-446655440000';
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('deleteById')->once()->with($id)->andReturn(false);

    // When / Then: UseCase 実行で ConferenceNotFoundException が投げられる
    $useCase = new DeleteConferenceUseCase($repository);
    expect(fn () => $useCase->execute($id))
        ->toThrow(ConferenceNotFoundException::class);
});

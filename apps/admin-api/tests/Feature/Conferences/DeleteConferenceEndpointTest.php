<?php

use App\Application\Conferences\DeleteConferenceUseCase;
use App\Domain\Conferences\ConferenceNotFoundException;
use App\Http\Middleware\VerifyOrigin;

/**
 * DELETE /admin/api/conferences/{id} (operationId: deleteConference) の Feature テスト。
 *
 * OpenAPI 仕様 (data/openapi.yaml):
 *   - 204 No Content (削除成功)
 *   - 404 NOT_FOUND (該当無し)
 */

beforeEach(function () {
    // Given (共通): VerifyOrigin は別テスト責務なのでバイパス
    test()->withoutMiddleware(VerifyOrigin::class);
});

it('DELETE /admin/api/conferences/{id} で 204 が返り body は空', function () {
    // Given: UseCase が削除成功 (例外なし) で完了するモック
    $id = '550e8400-e29b-41d4-a716-446655440000';
    $useCase = Mockery::mock(DeleteConferenceUseCase::class);
    $useCase->shouldReceive('execute')->once()->with($id);
    app()->instance(DeleteConferenceUseCase::class, $useCase);

    // When: DELETE する
    $response = $this->deleteJson("/admin/api/conferences/{$id}");

    // Then: 204 No Content + body 空
    $response->assertStatus(204);
    expect($response->getContent())->toBe('');
});

it('DELETE /admin/api/conferences/{id} は該当無しなら 404 + NOT_FOUND', function () {
    // Given: UseCase が ConferenceNotFoundException を投げる
    $id = '550e8400-e29b-41d4-a716-446655440000';
    $useCase = Mockery::mock(DeleteConferenceUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->andThrow(ConferenceNotFoundException::withId($id));
    app()->instance(DeleteConferenceUseCase::class, $useCase);

    // When: DELETE する
    $response = $this->deleteJson("/admin/api/conferences/{$id}");

    // Then: 404 + NOT_FOUND に整形される
    $response->assertStatus(404);
    $response->assertJsonPath('error.code', 'NOT_FOUND');
});

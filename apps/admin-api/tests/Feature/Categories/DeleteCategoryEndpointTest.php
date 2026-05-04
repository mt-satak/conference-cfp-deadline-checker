<?php

use App\Application\Categories\DeleteCategoryUseCase;
use App\Domain\Categories\CategoryConflictException;
use App\Domain\Categories\CategoryNotFoundException;
use App\Http\Middleware\VerifyOrigin;

beforeEach(function () {
    test()->withoutMiddleware(VerifyOrigin::class);
});

/**
 * DELETE /admin/api/categories/{id} (operationId: deleteCategory) の Feature テスト。
 *
 *   - 204 No Content
 *   - 404 NOT_FOUND
 *   - 409 CONFLICT: 参照する Conference が存在
 */
it('DELETE /admin/api/categories/{id} は 204 を返す', function () {
    // Given: UseCase が成功 (例外なし)
    $id = '550e8400-e29b-41d4-a716-446655440000';
    $useCase = Mockery::mock(DeleteCategoryUseCase::class);
    $useCase->shouldReceive('execute')->once()->with($id);
    app()->instance(DeleteCategoryUseCase::class, $useCase);

    // When
    $response = $this->deleteJson("/admin/api/categories/{$id}");

    // Then
    $response->assertStatus(204);
});

it('DELETE /admin/api/categories/{id} は該当無しなら 404 + NOT_FOUND', function () {
    // Given
    $id = '550e8400-e29b-41d4-a716-446655440000';
    $useCase = Mockery::mock(DeleteCategoryUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->andThrow(CategoryNotFoundException::withId($id));
    app()->instance(DeleteCategoryUseCase::class, $useCase);

    // When
    $response = $this->deleteJson("/admin/api/categories/{$id}");

    // Then
    $response->assertStatus(404);
    $response->assertJsonPath('error.code', 'NOT_FOUND');
});

it('DELETE /admin/api/categories/{id} は参照する Conference があると 409 + CONFLICT', function () {
    // Given
    $id = '550e8400-e29b-41d4-a716-446655440000';
    $useCase = Mockery::mock(DeleteCategoryUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->andThrow(CategoryConflictException::referencedByConferences($id, 3));
    app()->instance(DeleteCategoryUseCase::class, $useCase);

    // When
    $response = $this->deleteJson("/admin/api/categories/{$id}");

    // Then
    $response->assertStatus(409);
    $response->assertJsonPath('error.code', 'CONFLICT');
});

<?php

use App\Application\Categories\GetCategoryUseCase;
use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryAxis;
use App\Domain\Categories\CategoryNotFoundException;

/**
 * GET /admin/api/categories/{id} (operationId: getCategory) の Feature テスト。
 *
 * - 200 OK: {"data": <Category>}
 * - 404 NOT_FOUND
 */

it('GET /admin/api/categories/{id} は 200 と data に Category を返す', function () {
    // Given
    $id = '550e8400-e29b-41d4-a716-446655440000';
    $category = new Category(
        categoryId: $id,
        name: 'PHP',
        slug: 'php',
        displayOrder: 100,
        axis: CategoryAxis::A,
        createdAt: '2026-01-01T00:00:00+09:00',
        updatedAt: '2026-01-01T00:00:00+09:00',
    );
    $useCase = Mockery::mock(GetCategoryUseCase::class);
    $useCase->shouldReceive('execute')->once()->with($id)->andReturn($category);
    app()->instance(GetCategoryUseCase::class, $useCase);

    // When
    $response = $this->getJson("/admin/api/categories/{$id}");

    // Then
    $response->assertStatus(200);
    $response->assertJsonPath('data.categoryId', $id);
    $response->assertJsonPath('data.name', 'PHP');
    $response->assertJsonPath('data.slug', 'php');
});

it('GET /admin/api/categories/{id} は該当無しなら 404 + NOT_FOUND', function () {
    // Given
    $id = '550e8400-e29b-41d4-a716-446655440000';
    $useCase = Mockery::mock(GetCategoryUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->with($id)
        ->andThrow(CategoryNotFoundException::withId($id));
    app()->instance(GetCategoryUseCase::class, $useCase);

    // When
    $response = $this->getJson("/admin/api/categories/{$id}");

    // Then: AdminApiExceptionRenderer が 404 + NOT_FOUND に整形
    $response->assertStatus(404);
    $response->assertJsonPath('error.code', 'NOT_FOUND');
});

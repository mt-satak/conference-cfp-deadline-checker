<?php

use App\Application\Categories\ListCategoriesUseCase;
use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryAxis;

/**
 * GET /admin/api/categories (operationId: listCategories) の Feature テスト。
 *
 * OpenAPI 仕様 (data/openapi.yaml):
 *   - 200 OK: {"data": [<Category>], "meta": {"count": N}}
 *   - displayOrder 昇順
 */

it('GET /admin/api/categories は 200 と data に Category 配列を返す', function () {
    // Given: ListCategoriesUseCase が 2 件返すモック (displayOrder 昇順)
    $cat1 = new Category(
        categoryId: 'id-1',
        name: 'PHP',
        slug: 'php',
        displayOrder: 100,
        axis: CategoryAxis::A,
        createdAt: '2026-01-01T00:00:00+09:00',
        updatedAt: '2026-01-01T00:00:00+09:00',
    );
    $cat2 = new Category(
        categoryId: 'id-2',
        name: 'Python',
        slug: 'python',
        displayOrder: 200,
        axis: null,
        createdAt: '2026-01-01T00:00:00+09:00',
        updatedAt: '2026-01-01T00:00:00+09:00',
    );
    $useCase = Mockery::mock(ListCategoriesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn([$cat1, $cat2]);
    app()->instance(ListCategoriesUseCase::class, $useCase);

    // When
    $response = $this->getJson('/admin/api/categories');

    // Then: 200 + data 配列 + meta.count
    $response->assertStatus(200);
    $response->assertJsonPath('data.0.categoryId', 'id-1');
    $response->assertJsonPath('data.0.name', 'PHP');
    $response->assertJsonPath('data.0.axis', 'A');
    $response->assertJsonPath('data.1.categoryId', 'id-2');
    $response->assertJsonPath('data.1.name', 'Python');
    // axis が null のとき出力しない
    expect($response->json('data.1.axis'))->toBeNull();
    $response->assertJsonPath('meta.count', 2);
});

it('GET /admin/api/categories は 0 件でも 200 と空配列を返す', function () {
    // Given: 0 件
    $useCase = Mockery::mock(ListCategoriesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn([]);
    app()->instance(ListCategoriesUseCase::class, $useCase);

    // When
    $response = $this->getJson('/admin/api/categories');

    // Then
    $response->assertStatus(200);
    $response->assertJsonPath('data', []);
    $response->assertJsonPath('meta.count', 0);
});

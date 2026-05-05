<?php

use App\Application\Categories\ListCategoriesUseCase;
use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryAxis;

/**
 * /admin/categories (一覧) の Blade SSR Feature テスト。
 */
beforeEach(function () {
    test()->withoutVite();
});

it('GET /admin/categories は 200 を返し、UseCase の結果を一覧表示する', function () {
    // Given
    $useCase = Mockery::mock(ListCategoriesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn([
        new Category('id-1', 'PHP', 'php', 100, CategoryAxis::A, '', ''),
        new Category('id-2', 'Python', 'python', 200, null, '', ''),
    ]);
    app()->instance(ListCategoriesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/categories');

    // Then
    $response->assertStatus(200);
    $response->assertSee('PHP', false);
    $response->assertSee('Python', false);
    $response->assertSee('php', false);
    $response->assertSee('python', false);
    $response->assertSee('2 件', false);
});

it('GET /admin/categories は 0 件で empty state を表示する', function () {
    // Given
    $useCase = Mockery::mock(ListCategoriesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn([]);
    app()->instance(ListCategoriesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/categories');

    // Then
    $response->assertStatus(200);
    $response->assertSee('登録されたカテゴリがありません', false);
});

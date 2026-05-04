<?php

use App\Application\Categories\GetCategoryUseCase;
use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryAxis;
use App\Domain\Categories\CategoryNotFoundException;
use App\Domain\Categories\CategoryRepository;

/**
 * GetCategoryUseCase の単体テスト。
 *
 * 責務: Repository->findById() の結果を返す。null なら CategoryNotFoundException。
 */

it('Repository が Category を返した場合はそのまま返す', function () {
    // Given: Repository が Category を返す
    $category = new Category(
        categoryId: 'id-1',
        name: 'PHP',
        slug: 'php',
        displayOrder: 100,
        axis: CategoryAxis::A,
        createdAt: '2026-05-04T10:00:00+09:00',
        updatedAt: '2026-05-04T10:00:00+09:00',
    );
    $repository = Mockery::mock(CategoryRepository::class);
    $repository->shouldReceive('findById')->once()->with('id-1')->andReturn($category);

    // When
    $useCase = new GetCategoryUseCase($repository);
    $result = $useCase->execute('id-1');

    // Then: Repository が返した Category がそのまま返る
    expect($result->categoryId)->toBe('id-1');
});

it('Repository が null を返した場合 CategoryNotFoundException を投げる', function () {
    // Given: Repository が null
    $repository = Mockery::mock(CategoryRepository::class);
    $repository->shouldReceive('findById')->once()->with('missing')->andReturn(null);

    // When/Then
    $useCase = new GetCategoryUseCase($repository);
    expect(fn () => $useCase->execute('missing'))
        ->toThrow(CategoryNotFoundException::class);
});

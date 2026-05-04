<?php

use App\Application\Categories\ListCategoriesUseCase;
use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryAxis;
use App\Domain\Categories\CategoryRepository;

/**
 * ListCategoriesUseCase の単体テスト。
 *
 * 責務: Repository から取得したカテゴリ一覧を displayOrder 昇順で返す。
 */

function makeCategory(string $id, string $name, int $displayOrder): Category
{
    return new Category(
        categoryId: $id,
        name: $name,
        slug: strtolower($name),
        displayOrder: $displayOrder,
        axis: CategoryAxis::A,
        createdAt: '2026-05-04T10:00:00+09:00',
        updatedAt: '2026-05-04T10:00:00+09:00',
    );
}

it('Repository から取得した一覧を displayOrder 昇順で返す', function () {
    // Given: Repository が displayOrder 順序ばらばらの 3 件を返す
    $a = makeCategory('id-a', 'A', 300);
    $b = makeCategory('id-b', 'B', 100);
    $c = makeCategory('id-c', 'C', 200);
    $repository = Mockery::mock(CategoryRepository::class);
    $repository->shouldReceive('findAll')->once()->andReturn([$a, $b, $c]);

    // When: UseCase を実行する
    $useCase = new ListCategoriesUseCase($repository);
    $result = $useCase->execute();

    // Then: displayOrder 昇順 (B 100 → C 200 → A 300)
    expect($result[0]->categoryId)->toBe('id-b');
    expect($result[1]->categoryId)->toBe('id-c');
    expect($result[2]->categoryId)->toBe('id-a');
});

it('Repository が空配列を返した場合はそのまま空配列を返す', function () {
    // Given: 0 件
    $repository = Mockery::mock(CategoryRepository::class);
    $repository->shouldReceive('findAll')->once()->andReturn([]);

    // When
    $useCase = new ListCategoriesUseCase($repository);
    $result = $useCase->execute();

    // Then
    expect($result)->toBe([]);
});

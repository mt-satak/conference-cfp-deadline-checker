<?php

namespace App\Application\Categories;

use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryRepository;

/**
 * カテゴリ一覧取得 UseCase。
 *
 * 責務: 全カテゴリを取得し、displayOrder 昇順で返す
 * (OpenAPI 仕様: listCategories は displayOrder 昇順)。
 */
class ListCategoriesUseCase
{
    public function __construct(
        private readonly CategoryRepository $repository,
    ) {}

    /**
     * @return Category[] displayOrder 昇順
     */
    public function execute(): array
    {
        $categories = $this->repository->findAll();

        usort(
            $categories,
            static fn (Category $a, Category $b): int => $a->displayOrder <=> $b->displayOrder,
        );

        return $categories;
    }
}

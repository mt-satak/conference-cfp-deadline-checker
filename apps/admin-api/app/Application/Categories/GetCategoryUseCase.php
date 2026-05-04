<?php

namespace App\Application\Categories;

use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryNotFoundException;
use App\Domain\Categories\CategoryRepository;

/**
 * カテゴリ 1 件取得 UseCase。
 *
 * 該当無しの場合は CategoryNotFoundException を投げる
 * (HTTP 層で 404 + NOT_FOUND に整形される)。
 */
class GetCategoryUseCase
{
    public function __construct(
        private readonly CategoryRepository $repository,
    ) {}

    /**
     * @throws CategoryNotFoundException
     */
    public function execute(string $categoryId): Category
    {
        $category = $this->repository->findById($categoryId);
        if ($category === null) {
            throw CategoryNotFoundException::withId($categoryId);
        }

        return $category;
    }
}

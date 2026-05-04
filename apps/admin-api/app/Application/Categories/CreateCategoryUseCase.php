<?php

namespace App\Application\Categories;

use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryConflictException;
use App\Domain\Categories\CategoryRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * カテゴリ新規登録 UseCase。
 *
 * 責務:
 * - name / slug の重複チェック (重複時 CategoryConflictException)
 * - 入力 DTO から categoryId / createdAt / updatedAt を補完して Category 構築
 * - Repository->save() で永続化
 *
 * バリデーション (slug 形式 / displayOrder 整数 等) は HTTP 層 (FormRequest) で
 * 行う前提なので本 UseCase 内では再検証しない。
 */
class CreateCategoryUseCase
{
    public function __construct(
        private readonly CategoryRepository $repository,
    ) {}

    /**
     * @throws CategoryConflictException name / slug 重複時
     */
    public function execute(CreateCategoryInput $input): Category
    {
        if ($this->repository->findByName($input->name) !== null) {
            throw CategoryConflictException::nameAlreadyExists($input->name);
        }
        if ($this->repository->findBySlug($input->slug) !== null) {
            throw CategoryConflictException::slugAlreadyExists($input->slug);
        }

        $now = Carbon::now('Asia/Tokyo')->toIso8601String();

        $category = new Category(
            categoryId: (string) Str::uuid(),
            name: $input->name,
            slug: $input->slug,
            displayOrder: $input->displayOrder,
            axis: $input->axis,
            createdAt: $now,
            updatedAt: $now,
        );

        $this->repository->save($category);

        return $category;
    }
}

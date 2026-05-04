<?php

namespace App\Application\Categories;

use App\Domain\Categories\CategoryAxis;

/**
 * CreateCategoryUseCase の入力 DTO。
 *
 * Domain Entity (Category) との違い:
 * - categoryId / createdAt / updatedAt は持たない (UseCase が生成・付与)
 *
 * バリデーション (slug の正規表現等) は HTTP 層 (FormRequest) で行う前提。
 */
final readonly class CreateCategoryInput
{
    public function __construct(
        public string $name,
        public string $slug,
        public int $displayOrder,
        public ?CategoryAxis $axis,
    ) {}
}

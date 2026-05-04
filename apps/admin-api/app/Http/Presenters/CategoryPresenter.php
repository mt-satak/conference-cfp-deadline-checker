<?php

namespace App\Http\Presenters;

use App\Domain\Categories\Category;

/**
 * Category Domain Entity → JSON 配列変換。
 *
 * OpenAPI 仕様 (data/openapi.yaml の Category) に整合する shape を作る。
 * axis は null のとき出力しない (OpenAPI で required ではないため)。
 */
class CategoryPresenter
{
    /**
     * @return array{
     *     categoryId: string,
     *     name: string,
     *     slug: string,
     *     displayOrder: int,
     *     axis?: string,
     *     createdAt: string,
     *     updatedAt: string,
     * }
     */
    public static function toArray(Category $category): array
    {
        $payload = [
            'categoryId' => $category->categoryId,
            'name' => $category->name,
            'slug' => $category->slug,
            'displayOrder' => $category->displayOrder,
            'createdAt' => $category->createdAt,
            'updatedAt' => $category->updatedAt,
        ];

        if ($category->axis !== null) {
            $payload['axis'] = $category->axis->value;
        }

        return $payload;
    }
}

<?php

namespace App\Http\Presenters;

use App\Domain\Categories\Category;

/**
 * 公開フロント (cfp-checker.dev) 向け Category Presenter (Issue #178 #4)。
 *
 * 設計意図は PublicConferencePresenter と同様。admin Presenter と projection を分離し、
 * 将来 Category Entity に admin 専用フィールドを追加した際の公開漏洩を防ぐ。
 */
class PublicCategoryPresenter
{
    /**
     * 公開フロントへ出力してよいフィールドのホワイトリスト (Issue #178 #4)。
     *
     * axis は OpenAPI 上 optional のため、null 時は出力に含まれない。
     * リストには載っているが、実際の出力に出るかは Category.axis の値次第。
     *
     * @var list<string>
     */
    public const PUBLIC_FIELDS = [
        'categoryId',
        'name',
        'slug',
        'displayOrder',
        'axis',
        'createdAt',
        'updatedAt',
    ];

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

    /**
     * Category[] を toArray した配列のリストに一括変換する。
     *
     * @param  Category[]  $categories
     * @return list<array<string, mixed>>
     */
    public static function toList(array $categories): array
    {
        return array_values(array_map(
            static fn (Category $c): array => self::toArray($c),
            $categories,
        ));
    }
}

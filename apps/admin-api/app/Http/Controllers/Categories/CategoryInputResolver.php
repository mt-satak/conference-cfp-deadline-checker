<?php

declare(strict_types=1);

namespace App\Http\Controllers\Categories;

use App\Application\Categories\CreateCategoryInput;
use App\Domain\Categories\CategoryAxis;

/**
 * Api / Admin の CategoryController で重複していた `CategoryAxis` 文字列 → enum 変換と
 * `CreateCategoryInput` 組み立てロジックを集約する静的ヘルパ (Issue #178 #2)。
 *
 * `ConferenceInputResolver` (Issue #178 #1) と同じ思想・パターン。Trait ではなく
 * Static class にしてテスト容易性 + 多重継承の心配を回避する。
 */
class CategoryInputResolver
{
    /**
     * POST validated データから `CreateCategoryInput` を組み立てる。
     *
     * axis は string で来るので enum に cast。axis 未指定 / null は null として保持。
     *
     * @param  array<string, mixed>  $validated
     */
    public static function buildCreateInput(array $validated): CreateCategoryInput
    {
        $axisRaw = $validated['axis'] ?? null;
        $axis = is_string($axisRaw) ? CategoryAxis::from($axisRaw) : null;

        /** @var string $name */
        $name = $validated['name'];
        /** @var string $slug */
        $slug = $validated['slug'];
        /** @var int $displayOrder */
        $displayOrder = $validated['displayOrder'];

        return new CreateCategoryInput(
            name: $name,
            slug: $slug,
            displayOrder: $displayOrder,
            axis: $axis,
        );
    }

    /**
     * PUT validated データの axis を string → enum に cast する。
     *
     * partial update セマンティクス (= キーが存在する場合のみ更新) を維持するため、
     * `isset` で「存在 + 非 null のときのみ」cast する。
     *
     * 戻り型は UpdateCategoryUseCase::execute() の `$fields` 引数 shape と一致させる。
     *
     * @param  array<string, mixed>  $validated
     * @return array{
     *     name?: string,
     *     slug?: string,
     *     displayOrder?: int,
     *     axis?: CategoryAxis,
     * }
     */
    public static function castUpdateFields(array $validated): array
    {
        $fields = $validated;

        if (isset($validated['axis']) && is_string($validated['axis'])) {
            $fields['axis'] = CategoryAxis::from($validated['axis']);
        }

        /** @var array{
         *     name?: string,
         *     slug?: string,
         *     displayOrder?: int,
         *     axis?: CategoryAxis,
         * } $fields
         */
        return $fields;
    }
}

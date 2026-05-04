<?php

namespace App\Http\Requests\Categories;

use App\Domain\Categories\CategoryAxis;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /admin/api/categories/{id} (operationId: updateCategory) の入力バリデーション。
 *
 * 部分更新 (PATCH 相当) を PUT で許容: すべてのフィールド optional。
 * 重複チェック (409) は UseCase 側で実施。
 */
class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // sometimes ルールで「キーが存在する場合のみ後続ルールを適用」する
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'slug' => ['sometimes', 'string', 'min:1', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'displayOrder' => ['sometimes', 'integer'],
            'axis' => ['sometimes', 'nullable', Rule::in(array_column(CategoryAxis::cases(), 'value'))],
        ];
    }

    /**
     * @return array{
     *     name?: string,
     *     slug?: string,
     *     displayOrder?: int,
     *     axis?: string|null,
     * }
     */
    public function validated($key = null, $default = null): array
    {
        /** @var array{
         *     name?: string,
         *     slug?: string,
         *     displayOrder?: int,
         *     axis?: string|null,
         * } $validated
         */
        $validated = parent::validated($key, $default);

        return $validated;
    }
}

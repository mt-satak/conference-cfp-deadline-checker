<?php

namespace App\Http\Requests\Categories;

use App\Domain\Categories\CategoryAxis;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /admin/api/categories (operationId: createCategory) の入力バリデーション。
 *
 * 整合先: data/openapi.yaml の CategoryCreate スキーマ。
 * name / slug の重複チェック (HTTP 409) は UseCase 側で実施するため本クラスでは扱わない
 * (ストレージ参照を伴うため)。
 */
class StoreCategoryRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'slug' => ['required', 'string', 'min:1', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'displayOrder' => ['required', 'integer'],
            'axis' => ['nullable', Rule::in(array_column(CategoryAxis::cases(), 'value'))],
        ];
    }

    /**
     * @return array{
     *     name: string,
     *     slug: string,
     *     displayOrder: int,
     *     axis?: string|null,
     * }
     */
    public function validated($key = null, $default = null): array
    {
        /** @var array{
         *     name: string,
         *     slug: string,
         *     displayOrder: int,
         *     axis?: string|null,
         * } $validated
         */
        $validated = parent::validated($key, $default);

        return $validated;
    }
}

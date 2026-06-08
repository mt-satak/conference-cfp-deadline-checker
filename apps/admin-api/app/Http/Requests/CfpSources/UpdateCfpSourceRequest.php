<?php

declare(strict_types=1);

namespace App\Http\Requests\CfpSources;

use Illuminate\Foundation\Http\FormRequest;

/**
 * CfP ソース更新の入力バリデーション (Issue #200 PR-1)。
 *
 * 部分更新セマンティクス: キーが存在する場合のみ形式チェック (sometimes)。
 */
class UpdateCfpSourceRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'url' => ['sometimes', 'string', 'url', 'starts_with:https://', 'max:2000'],
            'enabled' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array{
     *     name?: string,
     *     url?: string,
     *     enabled?: bool|null,
     * }
     */
    public function validated($key = null, $default = null): array
    {
        /** @var array{
         *     name?: string,
         *     url?: string,
         *     enabled?: bool|null,
         * } $validated
         */
        $validated = parent::validated($key, $default);

        return $validated;
    }
}

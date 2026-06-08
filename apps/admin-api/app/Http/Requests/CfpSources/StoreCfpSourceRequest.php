<?php

declare(strict_types=1);

namespace App\Http\Requests\CfpSources;

use Illuminate\Foundation\Http\FormRequest;

/**
 * CfP ソース新規登録の入力バリデーション (Issue #200 PR-1)。
 *
 * url 重複チェックは UseCase 側 (ストレージ参照を伴う) で実施。
 */
class StoreCfpSourceRequest extends FormRequest
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
            'url' => ['required', 'string', 'url', 'starts_with:https://', 'max:2000'],
            // HTML checkbox は未チェックだと送信されない。FormRequest validation
            // 前に Controller で補完するか、nullable + boolean キャストで吸収する。
            // ここでは nullable で受けて Controller 側でデフォルト判断する。
            'enabled' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array{
     *     name: string,
     *     url: string,
     *     enabled?: bool|null,
     * }
     */
    public function validated($key = null, $default = null): array
    {
        /** @var array{
         *     name: string,
         *     url: string,
         *     enabled?: bool|null,
         * } $validated
         */
        $validated = parent::validated($key, $default);

        return $validated;
    }
}

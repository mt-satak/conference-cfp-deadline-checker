<?php

namespace App\Http\Requests\Conferences;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /admin/conferences/bulk-delete の入力バリデーション (Issue #219)。
 *
 * 一覧画面の checkbox (name="ids[]") でチェックした conferenceId 配列を受け取る。
 */
class BulkDeleteConferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 認証は CloudFront 前段の Lambda@Edge Basic 認証で完結する想定。
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 最低 1 件必須。誤操作での全件巨大送信を防ぐため上限も設ける
            // (一覧は全件表示で件数が限られるため 500 で十分な余裕)。
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => '削除するカンファレンスを 1 件以上選択してください。',
            'ids.min' => '削除するカンファレンスを 1 件以上選択してください。',
        ];
    }

    /**
     * バリデーション済みの ID リストを取り出す。
     *
     * @return list<string>
     */
    public function conferenceIds(): array
    {
        $validated = $this->validated();
        $ids = $validated['ids'] ?? [];

        // 文字列のみに narrow (PHPStan 向け + defensive)
        return array_values(array_filter(
            is_array($ids) ? $ids : [],
            static fn ($id): bool => is_string($id),
        ));
    }
}

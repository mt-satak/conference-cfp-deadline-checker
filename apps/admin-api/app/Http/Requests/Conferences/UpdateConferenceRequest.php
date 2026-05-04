<?php

namespace App\Http\Requests\Conferences;

use App\Domain\Conferences\ConferenceFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /admin/api/conferences/{id} (operationId: updateConference) の入力バリデーション。
 *
 * OpenAPI 仕様 ConferenceUpdate に整合: 部分更新 (PATCH 相当) を PUT で許容。
 * すべてのフィールドが任意で、含まれていないキーは更新対象外。
 *
 * 各フィールドの shape 検証 (URL https / 形式 / enum 等) は本クラスで実施。
 *
 * NOTE: 整合性ルール (cfpEndDate <= eventStartDate 等) の cross-field 検証は
 * 部分更新では既存値とのマージ後に行う必要があり、現時点では未実装。
 * 将来 UseCase 内で既存 Conference を取得してから検証する設計を検討する。
 */
class UpdateConferenceRequest extends FormRequest
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
        // sometimes ルールで「キーが存在する場合のみ後続ルールを適用」する。
        // これにより部分更新セマンティクスが実現できる。
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:200'],
            'trackName' => ['sometimes', 'nullable', 'string', 'max:100'],
            'officialUrl' => ['sometimes', 'string', 'url:https'],
            'cfpUrl' => ['sometimes', 'string', 'url:https'],
            'eventStartDate' => ['sometimes', 'date_format:Y-m-d'],
            'eventEndDate' => ['sometimes', 'date_format:Y-m-d'],
            'venue' => ['sometimes', 'string', 'min:1', 'max:100'],
            'format' => ['sometimes', Rule::in(array_column(ConferenceFormat::cases(), 'value'))],
            'cfpStartDate' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'cfpEndDate' => ['sometimes', 'date_format:Y-m-d'],
            'categories' => ['sometimes', 'array', 'min:1'],
            'categories.*' => ['string', 'uuid'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'themeColor' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}

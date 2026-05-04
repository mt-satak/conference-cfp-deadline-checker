<?php

namespace App\Http\Requests\Conferences;

use App\Domain\Conferences\ConferenceFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /admin/api/conferences (operationId: createConference) の入力バリデーション。
 *
 * 整合先: data/openapi.yaml の components.schemas.ConferenceCreate および
 * Conference スキーマ。整合性ルール (cfpStartDate <= cfpEndDate <=
 * eventStartDate <= eventEndDate / URL https / 等) も本クラスで担う。
 *
 * NOTE: categories の参照整合性 (categoryId が categories テーブルに存在
 * すること) は Categories Repository が無いため本コミット時点では検証しない。
 * Categories CRUD 実装時に追加する。
 */
class StoreConferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 認証は CloudFront 前段の Lambda@Edge Basic 認証で完結する想定。
        // Laravel 側ではポリシーチェックは行わないので常に true。
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:200'],
            'trackName' => ['nullable', 'string', 'max:100'],
            'officialUrl' => ['required', 'string', 'url:https'],
            'cfpUrl' => ['required', 'string', 'url:https'],
            'eventStartDate' => ['required', 'date_format:Y-m-d'],
            'eventEndDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:eventStartDate'],
            'venue' => ['required', 'string', 'min:1', 'max:100'],
            'format' => ['required', Rule::in(array_column(ConferenceFormat::cases(), 'value'))],
            'cfpStartDate' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:cfpEndDate'],
            'cfpEndDate' => ['required', 'date_format:Y-m-d', 'before_or_equal:eventStartDate'],
            'categories' => ['required', 'array', 'min:1'],
            'categories.*' => ['string', 'uuid'],
            'description' => ['nullable', 'string', 'max:2000'],
            'themeColor' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}

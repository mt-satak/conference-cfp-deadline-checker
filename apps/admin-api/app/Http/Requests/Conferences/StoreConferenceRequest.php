<?php

namespace App\Http\Requests\Conferences;

use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /admin/api/conferences (operationId: createConference) の入力バリデーション。
 *
 * 整合先: data/openapi.yaml の components.schemas.ConferenceCreate および
 * Conference スキーマ。整合性ルール (cfpStartDate <= cfpEndDate <=
 * eventStartDate <= eventEndDate / URL https / 等) も本クラスで担う。
 *
 * Phase 0.5 (Issue #41) で status による条件分岐:
 * - Draft: name + officialUrl のみ必須。それ以外は任意 (来た場合は shape 検証)
 * - Published: 従来通り全 9 項目必須 (cfpUrl, eventStartDate, eventEndDate, venue,
 *   format, cfpEndDate, categories) + 整合性ルール
 *
 * status 自体が省略された場合 published として扱う (= 後方互換)。
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
        $statusValues = array_column(ConferenceStatus::cases(), 'value');
        $isPublished = $this->resolveStatus() === ConferenceStatus::Published;

        return [
            'status' => ['sometimes', Rule::in($statusValues)],
            'name' => ['required', 'string', 'min:1', 'max:200'],
            'trackName' => ['nullable', 'string', 'max:100'],
            'officialUrl' => ['required', 'string', 'url:https'],
            'cfpUrl' => $this->publishedRequiredOrNullable($isPublished, ['string', 'url:https']),
            'eventStartDate' => $this->publishedRequiredOrNullable($isPublished, ['date_format:Y-m-d']),
            'eventEndDate' => $this->publishedRequiredOrNullable($isPublished, ['date_format:Y-m-d', 'after_or_equal:eventStartDate']),
            'venue' => $this->publishedRequiredOrNullable($isPublished, ['string', 'min:1', 'max:100']),
            'format' => $this->publishedRequiredOrNullable($isPublished, [Rule::in(array_column(ConferenceFormat::cases(), 'value'))]),
            'cfpStartDate' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:cfpEndDate'],
            'cfpEndDate' => $this->publishedRequiredOrNullable($isPublished, ['date_format:Y-m-d', 'before_or_equal:eventStartDate']),
            // Issue #121: Published / Draft どちらもカテゴリは任意 (0 件 OK)。
            // 運用上「カテゴリ未確定でもとりあえず公開して CfP 開始情報を出したい」
            // ケースに対応する。Conference Domain VO は categories: string[] で
            // 0 件を許容済みなのでデータ整合性は保たれる。
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['string', 'uuid'],
            'description' => ['nullable', 'string', 'max:2000'],
            'themeColor' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }

    /**
     * status 入力値を ConferenceStatus に解決する (省略時は Published)。
     */
    private function resolveStatus(): ConferenceStatus
    {
        $value = $this->input('status');
        if (! is_string($value)) {
            return ConferenceStatus::Published;
        }

        return ConferenceStatus::tryFrom($value) ?? ConferenceStatus::Published;
    }

    /**
     * Published 時は required + 内部 shape ルール、Draft 時は nullable + 内部 shape ルール、
     * を共通組み立てするヘルパ。
     *
     * @param  array<int, mixed>  $shapeRules
     * @return array<int, mixed>
     */
    private function publishedRequiredOrNullable(bool $isPublished, array $shapeRules): array
    {
        return $isPublished
            ? array_merge(['required'], $shapeRules)
            : array_merge(['nullable'], $shapeRules);
    }

    /**
     * バリデーション通過後の入力を Controller / UseCase が型安全にアクセスできる形で返す。
     *
     * Laravel の親 validated() は array<string, mixed> を返すので、PHPDoc の
     * 配列 shape (PHPStan extension の概念) で実際の型を宣言する。これにより
     * Controller 側の `$validated['name']` 等が string と推論される。
     *
     * Phase 0.5 (Issue #41) で Draft 入力時に Published 必須項目 (cfpUrl 等) が
     * 欠落 / null 許容になったため、対応するキーは optional + string|null union に変更。
     *
     * @return array{
     *     status?: string,
     *     name: string,
     *     trackName?: string|null,
     *     officialUrl: string,
     *     cfpUrl?: string|null,
     *     eventStartDate?: string|null,
     *     eventEndDate?: string|null,
     *     venue?: string|null,
     *     format?: string|null,
     *     cfpStartDate?: string|null,
     *     cfpEndDate?: string|null,
     *     categories?: array<int, string>,
     *     description?: string|null,
     *     themeColor?: string|null,
     * }
     */
    public function validated($key = null, $default = null): array
    {
        /** @var array{
         *     status?: string,
         *     name: string,
         *     trackName?: string|null,
         *     officialUrl: string,
         *     cfpUrl?: string|null,
         *     eventStartDate?: string|null,
         *     eventEndDate?: string|null,
         *     venue?: string|null,
         *     format?: string|null,
         *     cfpStartDate?: string|null,
         *     cfpEndDate?: string|null,
         *     categories?: array<int, string>,
         *     description?: string|null,
         *     themeColor?: string|null,
         * } $validated
         */
        $validated = parent::validated($key, $default);

        return $validated;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Conferences;

use App\Application\Conferences\CreateConferenceInput;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceStatus;

/**
 * Api / Admin の ConferenceController で重複していた共通変換ロジックを集約する
 * 静的ヘルパ (Issue #178 #1)。
 *
 * 各 Controller は HTTP layer 固有の責務 (= JsonResponse vs View / Redirect、
 * 例外マッピング等) に集中し、validated データから Domain 入力 (DTO / enum)
 * への変換は本ヘルパに委譲する。
 *
 * Trait ではなく Static class にしている理由:
 *  - Trait は単体テストが書きづらい (= ダミー class が必要)
 *  - Static class なら直接 ConferenceInputResolver::method() でテスト可能
 *  - 多重継承の心配が無い
 */
class ConferenceInputResolver
{
    /**
     * URL ?status クエリ文字列を ListConferencesUseCase に渡す
     * `ConferenceStatus[]` 配列に解決する (Issue #165 / #178)。
     *
     * @param  mixed  $statusParam  通常 string だが request->query() は mixed を返すため受ける
     * @param  ConferenceStatus[]|null  $defaultForUnknown  入力が想定外の時に返す配列。
     *                                                      Admin UI は `[Draft, Published]` (= active タブ default)、
     *                                                      Api は `null` (= 全件 fail-soft) を渡す。
     * @return ConferenceStatus[]|null
     */
    public static function resolveStatusFilters(mixed $statusParam, ?array $defaultForUnknown): ?array
    {
        if (! is_string($statusParam)) {
            return $defaultForUnknown;
        }

        return match ($statusParam) {
            'draft' => [ConferenceStatus::Draft],
            'published' => [ConferenceStatus::Published],
            'archived' => [ConferenceStatus::Archived],
            'active' => [ConferenceStatus::Draft, ConferenceStatus::Published],
            default => $defaultForUnknown,
        };
    }

    /**
     * POST validated データの status (string) を ConferenceStatus enum に解決する。
     *
     * 旧 status カラム未指定 / 不正値は Published で fail-soft (= Phase 0.5 の後方互換)。
     *
     * @param  array<string, mixed>  $validated
     */
    public static function resolveCreateStatus(array $validated): ConferenceStatus
    {
        if (! isset($validated['status']) || ! is_string($validated['status'])) {
            return ConferenceStatus::Published;
        }

        return ConferenceStatus::tryFrom($validated['status']) ?? ConferenceStatus::Published;
    }

    /**
     * POST validated データ + 既に解決済の status から `CreateConferenceInput` を組み立てる。
     *
     * format は string で来るので enum に cast。optional フィールド未指定は
     * null / 空配列で埋める (= Conference の Draft 表現に整合)。
     *
     * @param  array<string, mixed>  $validated
     */
    public static function buildCreateInput(array $validated, ConferenceStatus $status): CreateConferenceInput
    {
        $formatRaw = $validated['format'] ?? null;
        $format = is_string($formatRaw) ? ConferenceFormat::from($formatRaw) : null;

        /** @var string $name */
        $name = $validated['name'];
        /** @var string $officialUrl */
        $officialUrl = $validated['officialUrl'];

        /** @var array<int, string> $categories */
        $categories = $validated['categories'] ?? [];

        return new CreateConferenceInput(
            name: $name,
            trackName: self::nullableString($validated, 'trackName'),
            officialUrl: $officialUrl,
            cfpUrl: self::nullableString($validated, 'cfpUrl'),
            eventStartDate: self::nullableString($validated, 'eventStartDate'),
            eventEndDate: self::nullableString($validated, 'eventEndDate'),
            venue: self::nullableString($validated, 'venue'),
            format: $format,
            cfpStartDate: self::nullableString($validated, 'cfpStartDate'),
            cfpEndDate: self::nullableString($validated, 'cfpEndDate'),
            categories: $categories,
            description: self::nullableString($validated, 'description'),
            themeColor: self::nullableString($validated, 'themeColor'),
            status: $status,
        );
    }

    /**
     * PUT validated データの format / status を string → enum に cast する。
     *
     * partial update セマンティクス (= キーが存在する場合のみ更新) を維持するため、
     * 各 cast は `array_key_exists` / `isset` で「存在するときのみ」差し替える。
     *
     * 戻り型は UpdateConferenceUseCase::execute() の `$fields` 引数 shape と一致させる
     * (= partial update なので全フィールド optional)。
     *
     * @param  array<string, mixed>  $validated
     * @return array{
     *     name?: string,
     *     trackName?: string|null,
     *     officialUrl?: string,
     *     cfpUrl?: string|null,
     *     eventStartDate?: string|null,
     *     eventEndDate?: string|null,
     *     venue?: string|null,
     *     format?: ConferenceFormat|null,
     *     cfpStartDate?: string|null,
     *     cfpEndDate?: string|null,
     *     categories?: array<int, string>,
     *     description?: string|null,
     *     themeColor?: string|null,
     *     status?: ConferenceStatus,
     * }
     */
    public static function castUpdateFields(array $validated): array
    {
        $fields = $validated;

        if (array_key_exists('format', $validated)) {
            $formatRaw = $validated['format'];
            $fields['format'] = is_string($formatRaw) ? ConferenceFormat::from($formatRaw) : null;
        }
        if (isset($validated['status']) && is_string($validated['status'])) {
            $fields['status'] = ConferenceStatus::from($validated['status']);
        }

        /** @var array{
         *     name?: string,
         *     trackName?: string|null,
         *     officialUrl?: string,
         *     cfpUrl?: string|null,
         *     eventStartDate?: string|null,
         *     eventEndDate?: string|null,
         *     venue?: string|null,
         *     format?: ConferenceFormat|null,
         *     cfpStartDate?: string|null,
         *     cfpEndDate?: string|null,
         *     categories?: array<int, string>,
         *     description?: string|null,
         *     themeColor?: string|null,
         *     status?: ConferenceStatus,
         * } $fields
         */
        return $fields;
    }

    /**
     * @param  array<string, mixed>  $array
     */
    private static function nullableString(array $array, string $key): ?string
    {
        $value = $array[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Application\Conferences\CreateConferenceInput;
use App\Application\Conferences\CreateConferenceUseCase;
use App\Application\Conferences\DeleteConferenceUseCase;
use App\Application\Conferences\GetConferenceUseCase;
use App\Application\Conferences\ListConferencesUseCase;
use App\Application\Conferences\UpdateConferenceUseCase;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceSortKey;
use App\Domain\Conferences\ConferenceStatus;
use App\Domain\Conferences\SortOrder;
use App\Http\Presenters\ConferencePresenter;
use App\Http\Requests\Conferences\StoreConferenceRequest;
use App\Http\Requests\Conferences\UpdateConferenceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Conferences リソースの HTTP エンドポイント群。
 *
 * 設計方針 (Standard DDD):
 * - 本コントローラは「HTTP リクエスト/レスポンスの変換」のみを担う
 * - ビジネスロジックは UseCase (Application 層) に委譲する
 * - Domain Entity の JSON シリアライズは Presenter (HTTP 層) に分離する
 *
 * OpenAPI 仕様 (data/openapi.yaml) の Conferences タグ参照。
 */
class ConferenceController extends BaseController
{
    /**
     * GET /admin/api/conferences (operationId: listConferences)
     *
     * クエリパラメータ:
     * - ?status=draft|published|archived|active: status フィルタ (Issue #165 で archived/active 追加)
     *   - draft / published / archived: 各 status 単独
     *   - active: Draft + Published (Archived 除外)
     *   - 未指定 / 不正値: フィルタ無し (= 全件返却、Admin UI のデフォルトとは異なる)
     * - ?sort=cfpEndDate|eventStartDate|cfpStartDate|name|createdAt: ソートキー (Issue #47 Phase A)
     * - ?order=asc|desc: 並び順 (Issue #47 Phase A)
     *
     * 未知の status / sort / order 値は無視 (= デフォルト挙動) で fail-soft。
     * ?q / ?category の対応は Issue #47 Phase B で扱う。
     *
     * Admin UI とは挙動が違う点に注意 (Issue #165): API consumer (= スクリプト / 自動化) は
     * 明示しない限り全件を期待するため、デフォルトを「フィルタ無し」(= Archived も含む) にしている。
     * 一方 Admin UI は人間が操作する画面なので、ノイズ削減のため未指定時に active 扱いにしている。
     */
    public function index(Request $request, ListConferencesUseCase $useCase): JsonResponse
    {
        $statusParam = $request->query('status');
        $statusFilters = is_string($statusParam) ? self::resolveStatusFilters($statusParam) : null;

        $sortParam = $request->query('sort');
        $sortKey = is_string($sortParam) ? ConferenceSortKey::tryFrom($sortParam) : null;

        $orderParam = $request->query('order');
        $order = is_string($orderParam)
            ? (SortOrder::tryFrom($orderParam) ?? SortOrder::Asc)
            : SortOrder::Asc;

        $conferences = $useCase->execute($statusFilters, $sortKey, $order);

        $data = array_map(
            static fn (Conference $c): array => ConferencePresenter::toArray($c),
            $conferences,
        );

        return $this->ok($data, ['count' => count($data)]);
    }

    /**
     * URL の ?status 文字列を ListConferencesUseCase に渡す配列に解決する (Issue #165)。
     *
     * Admin UI 側 (`Admin\ConferenceController`) と違い、API は未指定時に null (= 全件) を返す前提で
     * `is_string($statusParam)` のときだけ呼ばれる (= 明示指定された場合のみここに来る)。
     * 不正値は null 返却で UseCase 側にも null が渡り、結果として全件返る fail-soft 挙動。
     *
     * @return ConferenceStatus[]|null
     */
    private static function resolveStatusFilters(string $statusParam): ?array
    {
        return match ($statusParam) {
            'draft' => [ConferenceStatus::Draft],
            'published' => [ConferenceStatus::Published],
            'archived' => [ConferenceStatus::Archived],
            'active' => [ConferenceStatus::Draft, ConferenceStatus::Published],
            default => null,
        };
    }

    /**
     * GET /admin/api/conferences/{id} (operationId: getConference)
     *
     * 該当無しの場合 UseCase が ConferenceNotFoundException を投げ、
     * グローバル例外ハンドラ (AdminApiExceptionRenderer) が 404 + NOT_FOUND
     * に整形する。本コントローラでは catch せず素通しでよい。
     */
    public function show(string $id, GetConferenceUseCase $useCase): JsonResponse
    {
        $conference = $useCase->execute($id);

        return $this->ok(ConferencePresenter::toArray($conference));
    }

    /**
     * POST /admin/api/conferences (operationId: createConference)
     *
     * StoreConferenceRequest が OpenAPI 整合のバリデーションを担う。
     * 違反時は ValidationException → AdminApiExceptionRenderer 経由で
     * 422 + VALIDATION_FAILED に整形される。
     */
    public function store(StoreConferenceRequest $request, CreateConferenceUseCase $useCase): JsonResponse
    {
        $validated = $request->validated();

        // status 省略 / 未知値は Published で扱う (Draft 入力時のみ status='draft' 必須)
        $status = isset($validated['status'])
            ? (ConferenceStatus::tryFrom($validated['status']) ?? ConferenceStatus::Published)
            : ConferenceStatus::Published;

        $formatRaw = $validated['format'] ?? null;
        $input = new CreateConferenceInput(
            name: $validated['name'],
            trackName: $validated['trackName'] ?? null,
            officialUrl: $validated['officialUrl'],
            cfpUrl: $validated['cfpUrl'] ?? null,
            eventStartDate: $validated['eventStartDate'] ?? null,
            eventEndDate: $validated['eventEndDate'] ?? null,
            venue: $validated['venue'] ?? null,
            format: $formatRaw !== null ? ConferenceFormat::from($formatRaw) : null,
            cfpStartDate: $validated['cfpStartDate'] ?? null,
            cfpEndDate: $validated['cfpEndDate'] ?? null,
            categories: $validated['categories'] ?? [],
            description: $validated['description'] ?? null,
            themeColor: $validated['themeColor'] ?? null,
            status: $status,
        );

        $conference = $useCase->execute($input);

        return $this->created(ConferencePresenter::toArray($conference));
    }

    /**
     * PUT /admin/api/conferences/{id} (operationId: updateConference)
     *
     * UpdateConferenceRequest が部分更新のバリデーション (キーが存在する
     * 場合のみ shape チェック) を担う。
     * 該当無し時は UseCase が ConferenceNotFoundException を投げ、
     * グローバル例外ハンドラが 404 + NOT_FOUND に整形する。
     */
    public function update(string $id, UpdateConferenceRequest $request, UpdateConferenceUseCase $useCase): JsonResponse
    {
        $validated = $request->validated();

        // format / status は string で来るので enum に変換する。
        // 元の $validated を変更すると PHPStan の array shape が string|enum union に
        // なるので、UseCase に渡す配列を新規構築して型を確定させる。
        // Phase 0.5 (Issue #41) で format と status の enum 化、cfpUrl 等の null 受付。
        /** @var array{
         *     status?: ConferenceStatus,
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
         * } $fields
         */
        $fields = $validated;
        if (array_key_exists('format', $validated)) {
            $fields['format'] = $validated['format'] !== null ? ConferenceFormat::from($validated['format']) : null;
        }
        if (isset($validated['status'])) {
            $fields['status'] = ConferenceStatus::from($validated['status']);
        }

        $conference = $useCase->execute($id, $fields);

        return $this->ok(ConferencePresenter::toArray($conference));
    }

    /**
     * DELETE /admin/api/conferences/{id} (operationId: deleteConference)
     *
     * 該当無し時は UseCase が ConferenceNotFoundException を投げ、
     * グローバル例外ハンドラが 404 + NOT_FOUND に整形する。
     */
    public function destroy(string $id, DeleteConferenceUseCase $useCase): JsonResponse
    {
        $useCase->execute($id);

        return $this->noContent();
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Application\Conferences\CreateConferenceUseCase;
use App\Application\Conferences\DeleteConferenceUseCase;
use App\Application\Conferences\GetConferenceUseCase;
use App\Application\Conferences\ListConferencesUseCase;
use App\Application\Conferences\UpdateConferenceUseCase;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceSortKey;
use App\Domain\Conferences\SortOrder;
use App\Http\Controllers\Conferences\ConferenceInputResolver;
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
        // API は未指定 / 不正値で全件返却 (= defaultForUnknown=null)
        $statusFilters = ConferenceInputResolver::resolveStatusFilters($request->query('status'), null);

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
        $status = ConferenceInputResolver::resolveCreateStatus($validated);
        $input = ConferenceInputResolver::buildCreateInput($validated, $status);

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
        $fields = ConferenceInputResolver::castUpdateFields($request->validated());

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

<?php

namespace App\Http\Controllers\Api;

use App\Application\Conferences\GetConferenceUseCase;
use App\Application\Conferences\ListConferencesUseCase;
use App\Domain\Conferences\Conference;
use App\Http\Presenters\ConferencePresenter;
use Illuminate\Http\JsonResponse;

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
     * 本コミット時点ではフィルタ・ソートを実装していない (全件返却)。
     * ?q / ?category / ?status / ?sort / ?order のクエリパラメータ対応は
     * 後続コミット または別 Issue で扱う。
     */
    public function index(ListConferencesUseCase $useCase): JsonResponse
    {
        $conferences = $useCase->execute();

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
}

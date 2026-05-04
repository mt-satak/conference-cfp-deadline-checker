<?php

namespace App\Http\Controllers\Api;

use App\Application\Conferences\CreateConferenceInput;
use App\Application\Conferences\CreateConferenceUseCase;
use App\Application\Conferences\GetConferenceUseCase;
use App\Application\Conferences\ListConferencesUseCase;
use App\Application\Conferences\UpdateConferenceUseCase;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Http\Presenters\ConferencePresenter;
use App\Http\Requests\Conferences\StoreConferenceRequest;
use App\Http\Requests\Conferences\UpdateConferenceRequest;
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

        $input = new CreateConferenceInput(
            name: $validated['name'],
            trackName: $validated['trackName'] ?? null,
            officialUrl: $validated['officialUrl'],
            cfpUrl: $validated['cfpUrl'],
            eventStartDate: $validated['eventStartDate'],
            eventEndDate: $validated['eventEndDate'],
            venue: $validated['venue'],
            format: ConferenceFormat::from($validated['format']),
            cfpStartDate: $validated['cfpStartDate'] ?? null,
            cfpEndDate: $validated['cfpEndDate'],
            categories: $validated['categories'],
            description: $validated['description'] ?? null,
            themeColor: $validated['themeColor'] ?? null,
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
        $fields = $request->validated();

        // format は string で来るので enum に変換する (UseCase が ConferenceFormat
        // 期待のため)。空キーや不正値は FormRequest 側で弾かれている前提。
        if (isset($fields['format']) && is_string($fields['format'])) {
            $fields['format'] = ConferenceFormat::from($fields['format']);
        }

        $conference = $useCase->execute($id, $fields);

        return $this->ok(ConferencePresenter::toArray($conference));
    }
}

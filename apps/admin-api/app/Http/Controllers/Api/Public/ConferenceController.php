<?php

namespace App\Http\Controllers\Api\Public;

use App\Application\Conferences\ListConferencesUseCase;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceSortKey;
use App\Domain\Conferences\ConferenceStatus;
use App\Domain\Conferences\SortOrder;
use App\Http\Controllers\Api\BaseController;
use App\Http\Presenters\ConferencePresenter;
use Illuminate\Http\JsonResponse;

/**
 * 公開フロント (Astro) 向けの read-only Conferences エンドポイント (Issue #91 / Phase 4.1)。
 *
 * 設計:
 * - 認証なし (誰でも GET 可能)、ただし CloudFrontSecretMiddleware で直アクセス防御
 * - 常に Published のみ返す (= Draft は admin UI でのみ管理)
 * - cfpEndDate 昇順、null は末尾 (= 締切が近い順、未確定は末尾)
 * - レスポンス shape は admin/api と同じ {data, meta: {count}} (Presenter 共通)
 *
 * 将来 admin 専用フィールドを Conference に追加する場合は PublicConferencePresenter を
 * 切り出して projection を分けることを検討。
 */
class ConferenceController extends BaseController
{
    /**
     * GET /api/public/conferences
     */
    public function index(ListConferencesUseCase $useCase): JsonResponse
    {
        $conferences = $useCase->execute(
            ConferenceStatus::Published,
            ConferenceSortKey::CfpEndDate,
            SortOrder::Asc,
        );

        $data = array_map(
            static fn (Conference $c): array => ConferencePresenter::toArray($c),
            $conferences,
        );

        return $this->ok($data, ['count' => count($data)]);
    }
}

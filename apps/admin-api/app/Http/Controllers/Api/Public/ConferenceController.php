<?php

namespace App\Http\Controllers\Api\Public;

use App\Application\Conferences\ListConferencesUseCase;
use App\Domain\Conferences\ConferenceSortKey;
use App\Domain\Conferences\ConferenceStatus;
use App\Domain\Conferences\SortOrder;
use App\Http\Controllers\Api\BaseController;
use App\Http\Presenters\PublicConferencePresenter;
use Illuminate\Http\JsonResponse;

/**
 * 公開フロント (Astro) 向けの read-only Conferences エンドポイント (Issue #91 / Phase 4.1)。
 *
 * 設計:
 * - 認証なし (誰でも GET 可能)、ただし CloudFrontSecretMiddleware で直アクセス防御
 * - 常に Published のみ返す (= Draft は admin UI でのみ管理)
 * - cfpEndDate 昇順、null は末尾 (= 締切が近い順、未確定は末尾)
 * - レスポンス shape は admin/api と同じ {data, meta: {count}} だが、
 *   要素の projection は PublicConferencePresenter 経由で「公開許可フィールド」のみ (Issue #178 #4)
 */
class ConferenceController extends BaseController
{
    /**
     * GET /api/public/conferences
     */
    public function index(ListConferencesUseCase $useCase): JsonResponse
    {
        // 公開フロント (cfp-checker.dev) には Published のみ返す。
        // Issue #165 で Archived を追加したが、過去カンファ (= Archived) も Draft も
        // 公開対象外。配列に Published 単独を渡すことで明示する。
        $data = PublicConferencePresenter::toList($useCase->execute(
            [ConferenceStatus::Published],
            ConferenceSortKey::CfpEndDate,
            SortOrder::Asc,
        ));

        return $this->ok($data, ['count' => count($data)]);
    }
}

<?php

namespace App\Http\Controllers\Api\Public;

use App\Application\Categories\ListCategoriesUseCase;
use App\Http\Controllers\Api\BaseController;
use App\Http\Presenters\PublicCategoryPresenter;
use Illuminate\Http\JsonResponse;

/**
 * 公開フロント (Astro) 向けの read-only Categories エンドポイント (Issue #95 / Phase 4.4)。
 *
 * 公開フロントが Conference.categories (UUID v4 配列) を slug に解決するための
 * ソースとして提供する。
 *
 * - 認証なし、CloudFrontSecretMiddleware で直アクセス防御
 * - ListCategoriesUseCase は admin と共有、出力 projection だけ PublicCategoryPresenter に分離 (Issue #178 #4)
 * - displayOrder 昇順 (= UseCase のデフォルト)
 */
class CategoryController extends BaseController
{
    /**
     * GET /api/public/categories
     */
    public function index(ListCategoriesUseCase $useCase): JsonResponse
    {
        $data = PublicCategoryPresenter::toList($useCase->execute());

        return $this->ok($data, ['count' => count($data)]);
    }
}

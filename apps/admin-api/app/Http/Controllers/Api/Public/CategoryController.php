<?php

namespace App\Http\Controllers\Api\Public;

use App\Application\Categories\ListCategoriesUseCase;
use App\Http\Controllers\Api\BaseController;
use App\Http\Presenters\CategoryPresenter;
use Illuminate\Http\JsonResponse;

/**
 * 公開フロント (Astro) 向けの read-only Categories エンドポイント (Issue #95 / Phase 4.4)。
 *
 * 公開フロントが Conference.categories (UUID v4 配列) を slug に解決するための
 * ソースとして提供する。
 *
 * - 認証なし、CloudFrontSecretMiddleware で直アクセス防御
 * - 既存 ListCategoriesUseCase + CategoryPresenter を再利用
 * - displayOrder 昇順 (= UseCase のデフォルト)
 */
class CategoryController extends BaseController
{
    /**
     * GET /api/public/categories
     */
    public function index(ListCategoriesUseCase $useCase): JsonResponse
    {
        $data = CategoryPresenter::toList($useCase->execute());

        return $this->ok($data, ['count' => count($data)]);
    }
}

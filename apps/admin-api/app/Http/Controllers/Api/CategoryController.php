<?php

namespace App\Http\Controllers\Api;

use App\Application\Categories\CreateCategoryUseCase;
use App\Application\Categories\DeleteCategoryUseCase;
use App\Application\Categories\GetCategoryUseCase;
use App\Application\Categories\ListCategoriesUseCase;
use App\Application\Categories\UpdateCategoryUseCase;
use App\Domain\Categories\Category;
use App\Http\Controllers\Categories\CategoryInputResolver;
use App\Http\Presenters\CategoryPresenter;
use App\Http\Requests\Categories\StoreCategoryRequest;
use App\Http\Requests\Categories\UpdateCategoryRequest;
use Illuminate\Http\JsonResponse;

/**
 * Categories リソースの HTTP エンドポイント群。
 *
 * 設計方針 (Standard DDD):
 * - HTTP リクエスト/レスポンスの変換のみを担当
 * - ビジネスロジック (重複チェック・参照整合性等) は UseCase に委譲
 * - 永続化は Repository (Domain interface) 経由
 *
 * OpenAPI 仕様 (data/openapi.yaml) の Categories タグ参照。
 */
class CategoryController extends BaseController
{
    /**
     * GET /admin/api/categories (operationId: listCategories)
     *
     * displayOrder 昇順で全件返す。
     */
    public function index(ListCategoriesUseCase $useCase): JsonResponse
    {
        $categories = $useCase->execute();

        $data = array_map(
            static fn (Category $c): array => CategoryPresenter::toArray($c),
            $categories,
        );

        return $this->ok($data, ['count' => count($data)]);
    }

    /**
     * GET /admin/api/categories/{id} (operationId: getCategory)
     *
     * 該当無し: UseCase が CategoryNotFoundException を投げ、
     * AdminApiExceptionRenderer が 404 + NOT_FOUND に整形する。
     */
    public function show(string $id, GetCategoryUseCase $useCase): JsonResponse
    {
        $category = $useCase->execute($id);

        return $this->ok(CategoryPresenter::toArray($category));
    }

    /**
     * POST /admin/api/categories (operationId: createCategory)
     *
     * バリデーション (shape) は StoreCategoryRequest が担う。
     * name / slug 重複検出は UseCase が行い CategoryConflictException → 409 に整形。
     */
    public function store(StoreCategoryRequest $request, CreateCategoryUseCase $useCase): JsonResponse
    {
        $input = CategoryInputResolver::buildCreateInput($request->validated());
        $category = $useCase->execute($input);

        return $this->created(CategoryPresenter::toArray($category));
    }

    /**
     * PUT /admin/api/categories/{id} (operationId: updateCategory)
     *
     * 部分更新。axis は string で来るので enum に変換 (UseCase が CategoryAxis 期待)。
     * 該当無し: 404 / 重複: 409 (UseCase が判定)。
     */
    public function update(string $id, UpdateCategoryRequest $request, UpdateCategoryUseCase $useCase): JsonResponse
    {
        $fields = CategoryInputResolver::castUpdateFields($request->validated());

        $category = $useCase->execute($id, $fields);

        return $this->ok(CategoryPresenter::toArray($category));
    }

    /**
     * DELETE /admin/api/categories/{id} (operationId: deleteCategory)
     *
     * 該当無し: 404 / 参照する Conference あり: 409 (UseCase が判定)。
     */
    public function destroy(string $id, DeleteCategoryUseCase $useCase): JsonResponse
    {
        $useCase->execute($id);

        return $this->noContent();
    }
}

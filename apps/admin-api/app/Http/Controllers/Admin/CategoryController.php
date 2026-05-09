<?php

namespace App\Http\Controllers\Admin;

use App\Application\Categories\CreateCategoryUseCase;
use App\Application\Categories\DeleteCategoryUseCase;
use App\Application\Categories\GetCategoryUseCase;
use App\Application\Categories\ListCategoriesUseCase;
use App\Application\Categories\UpdateCategoryUseCase;
use App\Domain\Categories\CategoryAxis;
use App\Domain\Categories\CategoryConflictException;
use App\Domain\Categories\CategoryNotFoundException;
use App\Http\Controllers\Categories\CategoryInputResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Categories\StoreCategoryRequest;
use App\Http\Requests\Categories\UpdateCategoryRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * 管理画面のカテゴリ管理 UI (Blade SSR)。
 *
 * /admin/api/categories と機能は重複するが、こちらは UI 用。UseCase / FormRequest /
 * Input DTO は API 側と完全共有して二重実装を避ける (Conferences と同パターン)。
 *
 * 例外マッピング:
 * - CategoryNotFoundException → abort(404) (HTML エラーページ)
 * - CategoryConflictException → redirect back + errors flash (form 上にメッセージ表示)
 */
class CategoryController extends Controller
{
    /**
     * GET /admin/categories — 一覧画面 (displayOrder 昇順)。
     */
    public function index(ListCategoriesUseCase $useCase): View
    {
        return view('admin.categories.index', [
            'categories' => $useCase->execute(),
        ]);
    }

    /**
     * GET /admin/categories/create — 新規作成フォーム。
     */
    public function create(): View
    {
        return view('admin.categories.create', [
            'axes' => CategoryAxis::cases(),
        ]);
    }

    /**
     * POST /admin/categories — 新規作成サブミット。
     */
    public function store(StoreCategoryRequest $request, CreateCategoryUseCase $useCase): RedirectResponse
    {
        $input = CategoryInputResolver::buildCreateInput($request->validated());

        try {
            $category = $useCase->execute($input);
        } catch (CategoryConflictException $e) {
            // name / slug の重複は form に戻して表示
            return redirect()
                ->back()
                ->withInput()
                ->withErrors(['conflict' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.categories.index')
            ->with('status', "「{$category->name}」を作成しました");
    }

    /**
     * GET /admin/categories/{id}/edit — 編集フォーム。
     */
    public function edit(string $id, GetCategoryUseCase $useCase): View
    {
        try {
            $category = $useCase->execute($id);
        } catch (CategoryNotFoundException) {
            abort(404);
        }

        return view('admin.categories.edit', [
            'category' => $category,
            'axes' => CategoryAxis::cases(),
        ]);
    }

    /**
     * PUT /admin/categories/{id} — 更新サブミット (部分更新)。
     */
    public function update(
        string $id,
        UpdateCategoryRequest $request,
        UpdateCategoryUseCase $useCase,
    ): RedirectResponse {
        $fields = CategoryInputResolver::castUpdateFields($request->validated());

        try {
            $category = $useCase->execute($id, $fields);
        } catch (CategoryNotFoundException) {
            abort(404);
        } catch (CategoryConflictException $e) {
            return redirect()
                ->back()
                ->withInput()
                ->withErrors(['conflict' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.categories.index')
            ->with('status', "「{$category->name}」を更新しました");
    }

    /**
     * DELETE /admin/categories/{id} — 削除実行。
     *
     * 参照中の Conference があると CategoryConflictException → index へ戻して
     * フラッシュエラーを表示する (form 不在のため withErrors ではなく with('error'))。
     */
    public function destroy(string $id, DeleteCategoryUseCase $useCase): RedirectResponse
    {
        try {
            $useCase->execute($id);
        } catch (CategoryNotFoundException) {
            abort(404);
        } catch (CategoryConflictException $e) {
            return redirect()
                ->route('admin.categories.index')
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.categories.index')
            ->with('status', 'カテゴリを削除しました');
    }
}

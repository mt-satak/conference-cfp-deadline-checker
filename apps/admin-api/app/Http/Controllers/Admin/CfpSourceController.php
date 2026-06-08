<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\CfpSources\CreateCfpSourceInput;
use App\Application\CfpSources\CreateCfpSourceUseCase;
use App\Application\CfpSources\DeleteCfpSourceUseCase;
use App\Application\CfpSources\GetCfpSourceUseCase;
use App\Application\CfpSources\ListCfpSourcesUseCase;
use App\Application\CfpSources\UpdateCfpSourceUseCase;
use App\Domain\CfpSources\CfpSourceConflictException;
use App\Domain\CfpSources\CfpSourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CfpSources\StoreCfpSourceRequest;
use App\Http\Requests\CfpSources\UpdateCfpSourceRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * 管理画面の CfP ソース管理 UI (Issue #200 PR-1)。
 *
 * 週次自動 CfP 発見 (Issue #200) で巡回する集約ページ URL を CRUD する。
 * Categories と同パターン (薄い HTTP オーケストレーション、ロジックは UseCase に委譲)。
 *
 * 例外マッピング:
 * - CfpSourceNotFoundException → abort(404)
 * - CfpSourceConflictException → redirect back + errors flash (url 重複時)
 */
class CfpSourceController extends Controller
{
    /**
     * GET /admin/cfp-sources — 一覧画面 (createdAt 昇順)。
     */
    public function index(ListCfpSourcesUseCase $useCase): View
    {
        return view('admin.cfp-sources.index', [
            'sources' => $useCase->execute(),
        ]);
    }

    /**
     * GET /admin/cfp-sources/create — 新規作成フォーム。
     */
    public function create(): View
    {
        return view('admin.cfp-sources.create');
    }

    /**
     * POST /admin/cfp-sources — 新規作成サブミット。
     */
    public function store(StoreCfpSourceRequest $request, CreateCfpSourceUseCase $useCase): RedirectResponse
    {
        $validated = $request->validated();
        // HTML checkbox は未チェック時に送信されないため null をデフォルト true 扱い。
        // 「新規追加時は有効状態が自然」という前提に倒す (= 後から無効化は可能)。
        $enabled = (bool) ($validated['enabled'] ?? true);

        $input = new CreateCfpSourceInput(
            name: $validated['name'],
            url: $validated['url'],
            enabled: $enabled,
        );

        try {
            $source = $useCase->execute($input);
        } catch (CfpSourceConflictException $e) {
            return redirect()
                ->back()
                ->withInput()
                ->withErrors(['conflict' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.cfp-sources.index')
            ->with('status', "「{$source->name}」を作成しました");
    }

    /**
     * GET /admin/cfp-sources/{id}/edit — 編集フォーム。
     */
    public function edit(string $id, GetCfpSourceUseCase $useCase): View
    {
        try {
            $source = $useCase->execute($id);
        } catch (CfpSourceNotFoundException) {
            abort(404);
        }

        return view('admin.cfp-sources.edit', [
            'source' => $source,
        ]);
    }

    /**
     * PUT /admin/cfp-sources/{id} — 更新サブミット (部分更新)。
     *
     * enabled checkbox 未送信 = ユーザーが意図的に外したと解釈し、false で上書き
     * (= ConferenceController::update の categories 補完と同パターン)。
     */
    public function update(
        string $id,
        UpdateCfpSourceRequest $request,
        UpdateCfpSourceUseCase $useCase,
    ): RedirectResponse {
        $validated = $request->validated();

        $fields = [];
        if (array_key_exists('name', $validated)) {
            $fields['name'] = $validated['name'];
        }
        if (array_key_exists('url', $validated)) {
            $fields['url'] = $validated['url'];
        }
        // checkbox 未送信なら false を明示セット (= ユーザーの意図的なオフ)
        $fields['enabled'] = (bool) ($validated['enabled'] ?? false);

        try {
            $source = $useCase->execute($id, $fields);
        } catch (CfpSourceNotFoundException) {
            abort(404);
        } catch (CfpSourceConflictException $e) {
            return redirect()
                ->back()
                ->withInput()
                ->withErrors(['conflict' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.cfp-sources.index')
            ->with('status', "「{$source->name}」を更新しました");
    }

    /**
     * DELETE /admin/cfp-sources/{id} — 削除実行。
     */
    public function destroy(string $id, DeleteCfpSourceUseCase $useCase): RedirectResponse
    {
        try {
            $useCase->execute($id);
        } catch (CfpSourceNotFoundException) {
            abort(404);
        }

        return redirect()
            ->route('admin.cfp-sources.index')
            ->with('status', 'CfP ソースを削除しました');
    }
}

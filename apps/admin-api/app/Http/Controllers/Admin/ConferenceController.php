<?php

namespace App\Http\Controllers\Admin;

use App\Application\Categories\ListCategoriesUseCase;
use App\Application\Conferences\CreateConferenceInput;
use App\Application\Conferences\CreateConferenceUseCase;
use App\Application\Conferences\DeleteConferenceUseCase;
use App\Application\Conferences\GetConferenceUseCase;
use App\Application\Conferences\ListConferencesUseCase;
use App\Application\Conferences\UpdateConferenceUseCase;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceNotFoundException;
use App\Domain\Conferences\ConferenceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conferences\StoreConferenceRequest;
use App\Http\Requests\Conferences\UpdateConferenceRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * 管理画面のカンファレンス管理 UI (Blade SSR)。
 *
 * /admin/api/conferences と機能は重複するが、こちらは UI 用 (HTML を返す / リダイレクトする)。
 * UseCase / FormRequest / 入力 DTO は API 側と完全に共有して二重実装を避ける。
 *
 * ConferenceNotFoundException は API 側では Renderer が 404 JSON に整形するが、
 * UI 側では HTML エラー画面として 404 abort する (Laravel デフォルトの 404 view)。
 *
 * バリデーション違反時は FormRequest が自動で 302 リダイレクト + errors / old() を
 * session に flash する (Accept: application/json でない通常 POST)。
 */
class ConferenceController extends Controller
{
    /**
     * GET /admin/conferences — 一覧画面。
     */
    public function index(ListConferencesUseCase $useCase): View
    {
        $conferences = $useCase->execute();

        return view('admin.conferences.index', [
            'conferences' => $conferences,
        ]);
    }

    /**
     * GET /admin/conferences/create — 新規作成フォーム。
     * categories / formats を選択肢として渡す。
     */
    public function create(ListCategoriesUseCase $listCategoriesUseCase): View
    {
        return view('admin.conferences.create', [
            'categories' => $listCategoriesUseCase->execute(),
            'formats' => ConferenceFormat::cases(),
        ]);
    }

    /**
     * POST /admin/conferences — 新規作成サブミット。
     */
    public function store(StoreConferenceRequest $request, CreateConferenceUseCase $useCase): RedirectResponse
    {
        $v = $request->validated();

        // status 省略時は Published (= 後方互換)。Phase 0.5 (Issue #41) で nullable 受付化。
        $status = isset($v['status'])
            ? (ConferenceStatus::tryFrom($v['status']) ?? ConferenceStatus::Published)
            : ConferenceStatus::Published;

        $formatRaw = $v['format'] ?? null;
        $input = new CreateConferenceInput(
            name: $v['name'],
            trackName: $v['trackName'] ?? null,
            officialUrl: $v['officialUrl'],
            cfpUrl: $v['cfpUrl'] ?? null,
            eventStartDate: $v['eventStartDate'] ?? null,
            eventEndDate: $v['eventEndDate'] ?? null,
            venue: $v['venue'] ?? null,
            format: $formatRaw !== null ? ConferenceFormat::from($formatRaw) : null,
            cfpStartDate: $v['cfpStartDate'] ?? null,
            cfpEndDate: $v['cfpEndDate'] ?? null,
            categories: $v['categories'] ?? [],
            description: $v['description'] ?? null,
            themeColor: $v['themeColor'] ?? null,
            status: $status,
        );

        $conference = $useCase->execute($input);

        return redirect()
            ->route('admin.conferences.index')
            ->with('status', "「{$conference->name}」を作成しました");
    }

    /**
     * GET /admin/conferences/{id}/edit — 編集フォーム。
     */
    public function edit(
        string $id,
        GetConferenceUseCase $getUseCase,
        ListCategoriesUseCase $listCategoriesUseCase,
    ): View {
        try {
            $conference = $getUseCase->execute($id);
        } catch (ConferenceNotFoundException) {
            abort(404);
        }

        return view('admin.conferences.edit', [
            'conference' => $conference,
            'categories' => $listCategoriesUseCase->execute(),
            'formats' => ConferenceFormat::cases(),
        ]);
    }

    /**
     * PUT /admin/conferences/{id} — 更新サブミット (部分更新)。
     */
    public function update(
        string $id,
        UpdateConferenceRequest $request,
        UpdateConferenceUseCase $useCase,
    ): RedirectResponse {
        $validated = $request->validated();

        // ConferenceController (API) と同じ「format / status 変換 + typed shape」パターン。
        // Phase 0.5 (Issue #41) で cfpUrl 等を nullable 受付化、status 受付追加。
        /** @var array{
         *     status?: ConferenceStatus,
         *     name?: string,
         *     trackName?: string|null,
         *     officialUrl?: string,
         *     cfpUrl?: string|null,
         *     eventStartDate?: string|null,
         *     eventEndDate?: string|null,
         *     venue?: string|null,
         *     format?: ConferenceFormat|null,
         *     cfpStartDate?: string|null,
         *     cfpEndDate?: string|null,
         *     categories?: array<int, string>,
         *     description?: string|null,
         *     themeColor?: string|null,
         * } $fields
         */
        $fields = $validated;
        if (array_key_exists('format', $validated)) {
            $fields['format'] = $validated['format'] !== null ? ConferenceFormat::from($validated['format']) : null;
        }
        if (isset($validated['status'])) {
            $fields['status'] = ConferenceStatus::from($validated['status']);
        }

        try {
            $conference = $useCase->execute($id, $fields);
        } catch (ConferenceNotFoundException) {
            abort(404);
        }

        return redirect()
            ->route('admin.conferences.index')
            ->with('status', "「{$conference->name}」を更新しました");
    }

    /**
     * DELETE /admin/conferences/{id} — 削除実行。
     */
    public function destroy(string $id, DeleteConferenceUseCase $useCase): RedirectResponse
    {
        try {
            $useCase->execute($id);
        } catch (ConferenceNotFoundException) {
            abort(404);
        }

        return redirect()
            ->route('admin.conferences.index')
            ->with('status', 'カンファレンスを削除しました');
    }
}

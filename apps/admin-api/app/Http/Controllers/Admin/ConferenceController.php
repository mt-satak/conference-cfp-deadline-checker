<?php

namespace App\Http\Controllers\Admin;

use App\Application\Conferences\ListConferencesUseCase;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * 管理画面のカンファレンス管理 UI (Blade SSR)。
 *
 * /admin/api/conferences と機能は重複するが、こちらは UI 用 (HTML を返す)。
 * UseCase を直接呼ぶ SSR で /admin/api を経由しない (同一プロセス内のため)。
 *
 * 設計判断:
 * - UseCase は Application 層 のものをそのまま使い、HTTP API と UI で 1 本化
 * - Controller では「Domain Entity 配列 → Blade に渡す配列」に整形するだけ
 *   (View 層と Domain 層を直結させない)
 */
class ConferenceController extends Controller
{
    /**
     * GET /admin/conferences — カンファレンス一覧画面。
     */
    public function index(ListConferencesUseCase $useCase): View
    {
        $conferences = $useCase->execute();

        // displayOrder 等のロジックは UseCase 側で持っているため、ここではそのまま渡す。
        // 表示用のフォーマット (日付の表示形式変換等) は Blade 側で行う。
        return view('admin.conferences.index', [
            'conferences' => $conferences,
        ]);
    }
}

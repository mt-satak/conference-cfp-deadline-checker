<?php

namespace App\Http\Controllers\Admin;

use App\Application\Build\ListBuildStatusesUseCase;
use App\Application\Build\TriggerBuildUseCase;
use App\Domain\Build\BuildServiceNotConfiguredException;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * 管理画面の静的サイト再ビルド UI (Blade SSR)。
 *
 * /admin/api/build/trigger / /admin/api/build/status と機能は重複するが、
 * こちらは UI 用 (HTML を返す / リダイレクトする)。UseCase は API 側と共有。
 *
 * 例外マッピング:
 * - BuildServiceNotConfiguredException (GitHub App 未構成):
 *     index ではエラー枠を表示しつつ画面自体は描画 (status 0 件で empty state)
 *     trigger ではエラーフラッシュを乗せて index にリダイレクト
 *
 * GitHub App 未構成は API 側では 503 SERVICE_UNAVAILABLE だが UI では 200 で
 * 「設定が無いと使えません」を見せる。Phase 5.3 で AWS Amplify から移行済。
 */
class BuildController extends Controller
{
    /**
     * GET /admin/build — ビルド状態一覧 + トリガーボタン。
     */
    public function index(ListBuildStatusesUseCase $useCase): View
    {
        $statuses = [];
        $configured = true;

        try {
            $statuses = $useCase->execute();
        } catch (BuildServiceNotConfiguredException $e) {
            $configured = false;
        }

        return view('admin.build.index', [
            'statuses' => $statuses,
            'configured' => $configured,
        ]);
    }

    /**
     * POST /admin/build/trigger — 再ビルドを起動する。
     */
    public function trigger(TriggerBuildUseCase $useCase): RedirectResponse
    {
        try {
            $requestedAt = $useCase->execute();
        } catch (BuildServiceNotConfiguredException $e) {
            return redirect()
                ->route('admin.build.index')
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.build.index')
            ->with('status', "ビルドをトリガーしました ({$requestedAt})。完了まで 1〜2 分かかります。");
    }
}

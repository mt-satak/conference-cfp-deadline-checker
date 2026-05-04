<?php

use App\Exceptions\AdminApiExceptionRenderer;
use App\Http\Middleware\VerifyOrigin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // /admin/api 配下のルートは web ミドルウェアグループ (session / cookie /
        // csrf 等) と VerifyOrigin (Origin/Referer 検証) を踏ませた上で、
        // "/admin/api" プレフィックスで登録する。
        // Laravel 13 の api: パラメータは 'api' ミドルウェアグループ (stateless)
        // を適用するが、本アプリは CSRF と Session に依存するため then で明示的に
        // web グループを指定する。VerifyOrigin は CSRF の二重防御として状態変更系
        // メソッドにのみ適用される (Origin / Referer ヘッダ検証)。
        then: function (): void {
            // /admin/api: バックエンド API (JSON 応答)
            Route::middleware(['web', VerifyOrigin::class])
                ->prefix('admin/api')
                ->group(__DIR__.'/../routes/admin-api.php');

            // /admin: 管理画面 UI (Blade SSR)。Issue #9 で Blade SSR 採用が確定済。
            // CSRF / Origin 検証は VerifyOrigin で行う (form 送信用)。
            Route::middleware(['web', VerifyOrigin::class])
                ->prefix('admin')
                ->group(__DIR__.'/../routes/admin-ui.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // /admin/api 配下では OpenAPI 仕様の {"error": {...}} 形式で例外を整形して返す。
        // それ以外のパスでは null を返してデフォルトハンドラに委譲する。
        $exceptions->render(new AdminApiExceptionRenderer);
    })->create();

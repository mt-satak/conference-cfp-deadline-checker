<?php

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
        // csrf 等) を踏ませた上で、"/admin/api" プレフィックスで登録する。
        // Laravel 13 の api: パラメータは 'api' ミドルウェアグループ (stateless)
        // を適用するが、本アプリは CSRF と Session に依存するため then で明示的に
        // web グループを指定する。
        then: function (): void {
            Route::middleware('web')
                ->prefix('admin/api')
                ->group(__DIR__.'/../routes/admin-api.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

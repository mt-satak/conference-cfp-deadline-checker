<?php

use App\Http\Controllers\Admin\ConferenceController;
use App\Http\Controllers\Admin\HomeController;
use Illuminate\Support\Facades\Route;

/**
 * /admin 配下の UI ルート定義 (Blade SSR)。
 *
 * - bootstrap/app.php 側で web ミドルウェア + VerifyOrigin + "/admin" prefix が
 *   自動付与される
 * - 認証は CloudFront 前段の Lambda@Edge Basic 認証で完結する (architecture.md §6)
 * - 個別エンドポイント (Conferences / Categories / Build) は順次追加していく
 */

// ── ダッシュボード ──
Route::get('/', [HomeController::class, 'index'])->name('admin.home');

// ── Conferences ──
Route::get('/conferences', [ConferenceController::class, 'index'])->name('admin.conferences.index');
Route::get('/conferences/create', [ConferenceController::class, 'create'])->name('admin.conferences.create');
Route::post('/conferences', [ConferenceController::class, 'store'])->name('admin.conferences.store');
Route::get('/conferences/{id}/edit', [ConferenceController::class, 'edit'])->name('admin.conferences.edit');
Route::put('/conferences/{id}', [ConferenceController::class, 'update'])->name('admin.conferences.update');
Route::delete('/conferences/{id}', [ConferenceController::class, 'destroy'])->name('admin.conferences.destroy');

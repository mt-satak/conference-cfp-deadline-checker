<?php

use App\Http\Controllers\Admin\BuildController;
use App\Http\Controllers\Admin\CategoryController;
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
// Draft → Published 昇格専用 (Phase 0.5 / Issue #41 PR-3)
Route::post('/conferences/{id}/publish', [ConferenceController::class, 'publish'])->name('admin.conferences.publish');

// ── Categories ──
Route::get('/categories', [CategoryController::class, 'index'])->name('admin.categories.index');
Route::get('/categories/create', [CategoryController::class, 'create'])->name('admin.categories.create');
Route::post('/categories', [CategoryController::class, 'store'])->name('admin.categories.store');
Route::get('/categories/{id}/edit', [CategoryController::class, 'edit'])->name('admin.categories.edit');
Route::put('/categories/{id}', [CategoryController::class, 'update'])->name('admin.categories.update');
Route::delete('/categories/{id}', [CategoryController::class, 'destroy'])->name('admin.categories.destroy');

// ── Build (静的サイト再ビルド) ──
Route::get('/build', [BuildController::class, 'index'])->name('admin.build.index');
Route::post('/build/trigger', [BuildController::class, 'trigger'])->name('admin.build.trigger');

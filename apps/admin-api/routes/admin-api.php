<?php

use App\Http\Controllers\Api\ConferenceController;
use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

/**
 * /admin/api 配下のすべてのルートを定義するファイル。
 *
 * - bootstrap/app.php 側で web ミドルウェアグループ (session / cookie / csrf 等)
 *   と VerifyOrigin ミドルウェア、"/admin/api" プレフィックスが自動付与される。
 * - 個別エンドポイント (Conferences / Categories / Donations / Build) は
 *   後続 Issue で順次追加する。
 *
 * NOTE: /csrf-token エンドポイントは管理画面 UI を Blade SSR で実装する方針
 * (Issue #9) のため不要となり削除。Blade 側で @csrf ディレクティブや
 * csrf_token() ヘルパで HTML 内に直接トークンを埋め込む形を取る。
 */

// ── Health ──
// GET /admin/api/health — 認証不要のサーバー稼働確認エンドポイント。
// OpenAPI 仕様 (operationId: healthCheck) 準拠。
Route::get('/health', [HealthController::class, 'check']);

// ── Conferences ──
// 個別エンドポイントを順次追加していく。OpenAPI 仕様の Conferences タグ参照。
Route::get('/conferences', [ConferenceController::class, 'index']);
Route::post('/conferences', [ConferenceController::class, 'store']);
Route::get('/conferences/{id}', [ConferenceController::class, 'show']);
Route::put('/conferences/{id}', [ConferenceController::class, 'update']);

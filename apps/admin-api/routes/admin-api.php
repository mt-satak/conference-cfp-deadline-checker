<?php

use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

/**
 * /admin/api 配下のすべてのルートを定義するファイル。
 *
 * - bootstrap/app.php 側で web ミドルウェアグループ (session / cookie / csrf 等)
 *   と VerifyOrigin ミドルウェア、"/admin/api" プレフィックスが自動付与される。
 * - 個別エンドポイント (Conferences / Categories / Donations / Build) は
 *   後続 Issue で順次追加する。
 */

// ── Health ──
// GET /admin/api/health — 認証不要のサーバー稼働確認エンドポイント。
// OpenAPI 仕様 (operationId: healthCheck) 準拠。
Route::get('/health', [HealthController::class, 'check']);

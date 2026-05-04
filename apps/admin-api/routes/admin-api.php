<?php

use Illuminate\Support\Facades\Route;

/**
 * /admin/api 配下のすべてのルートを定義するファイル。
 *
 * - bootstrap/app.php 側で web ミドルウェアグループ (session / cookie / csrf 等)
 *   と "/admin/api" プレフィックスが自動付与される。
 * - 個別エンドポイント (Health / CSRF / Conferences / Categories / Donations /
 *   Build) は後続 Issue で順次追加する。
 */

// スモーク用スタブルート。ルーティング基盤が動作していることを確認する目的のみ。
// Step 3 で正式な /admin/api/health に置き換える想定。
Route::get('/_ping', fn () => response()->json([
    'data' => ['message' => 'admin-api alive'],
    'meta' => [],
]));

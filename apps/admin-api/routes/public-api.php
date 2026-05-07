<?php

use App\Http\Controllers\Api\Public\ConferenceController;
use Illuminate\Support\Facades\Route;

/**
 * 公開フロント (Astro) 向け read-only API ルート (Issue #91 / Phase 4.1)。
 *
 * bootstrap/app.php で `prefix: api/public` + `middleware: [CloudFrontSecretMiddleware]`
 * で登録される。
 *
 * web group (session / CSRF / VerifyOrigin) は不要 (= read-only stateless)。
 */
Route::get('/conferences', [ConferenceController::class, 'index']);

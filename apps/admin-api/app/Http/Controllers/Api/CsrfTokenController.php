<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;

/**
 * GET /admin/api/csrf-token の実装。
 *
 * OpenAPI 仕様 (data/openapi.yaml の operationId: getCsrfToken):
 *   - 200 OK
 *   - body: {"data": {"csrfToken": "<string>"}}
 *   - Set-Cookie: XSRF-TOKEN cookie が併せてセットされる (web ミドルウェアの
 *     EncryptCookies / AddQueuedCookiesToResponse / StartSession による)
 *
 * SPA フロントから状態変更系リクエストを行う前に呼び出すことで、
 * XSRF-TOKEN cookie と body の csrfToken が同期される想定。
 * SSR Blade / Inertia 利用時は通常不要 (HTML 側で meta タグから取得)。
 */
class CsrfTokenController extends BaseController
{
    public function token(): JsonResponse
    {
        // session()->token() でも csrf_token() でも同値を返す。
        // BaseController->ok() で {data: {csrfToken: ...}, meta: {}} 形式に整形。
        return $this->ok([
            'csrfToken' => csrf_token(),
        ]);
    }
}

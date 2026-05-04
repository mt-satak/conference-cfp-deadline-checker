<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /admin/api/health の実装。
 *
 * OpenAPI 仕様 (data/openapi.yaml の operationId: healthCheck):
 *   - 認証不要 (本クラスはルート登録時に web ミドルウェアの恩恵を受けるが、
 *     CSRF / Origin 検証は GET のため対象外、Lambda@Edge の Basic 認証も
 *     設計上 health に対しては将来的に除外する予定)。
 *   - レスポンスは BaseController の {data, meta} ラップ形式ではなく、
 *     直接プロパティ (status / timestamp) を返す。
 */
class HealthController extends Controller
{
    public function check(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            // OpenAPI の format: date-time に整合する RFC 3339 表現。
            // Carbon::toIso8601String() はタイムゾーン付き (例: 2026-05-04T10:00:00+09:00)。
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}

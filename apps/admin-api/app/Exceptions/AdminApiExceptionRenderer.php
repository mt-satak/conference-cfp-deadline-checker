<?php

namespace App\Exceptions;

use App\Domain\Build\BuildServiceNotConfiguredException;
use App\Domain\Categories\CategoryConflictException;
use App\Domain\Categories\CategoryNotFoundException;
use App\Domain\Conferences\ConferenceNotFoundException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * /admin/api 配下のリクエスト時に発生した例外を OpenAPI 仕様
 * {"error": {"code", "message", "details"}} 形式の JSON レスポンスに
 * 変換するレンダラ。
 *
 * - パスが /admin/api/* に一致しない場合は null を返し、Laravel デフォルトの
 *   例外ハンドラに処理を委譲する。
 * - bootstrap/app.php の withExceptions() に render コールバックとして登録する。
 */
class AdminApiExceptionRenderer
{
    /**
     * @return JsonResponse|null null を返した場合は Laravel デフォルトに委譲
     */
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('admin/api/*')) {
            return null;
        }

        return match (true) {
            $e instanceof ValidationException => $this->renderValidation($e),
            $e instanceof InvalidOriginException => $this->renderInvalidOrigin(),
            // Domain 層の Not Found 系例外は 404 + NOT_FOUND に整形する。
            // 将来リソース別 NotFoundException が増えたら共通の親クラス /
            // インタフェースに抽出することを検討する。
            $e instanceof ConferenceNotFoundException => $this->renderNotFound(),
            $e instanceof CategoryNotFoundException => $this->renderNotFound(),
            $e instanceof ModelNotFoundException => $this->renderNotFound(),
            $e instanceof NotFoundHttpException => $this->renderNotFound(),
            // Domain 層の Conflict 例外 (重複・参照整合性違反) は 409 + CONFLICT
            $e instanceof CategoryConflictException => $this->renderConflict($e),
            // Build サービス未構成 (Amplify Webhook URL / App ID が未設定) は 503
            $e instanceof BuildServiceNotConfiguredException => $this->renderServiceUnavailable(),
            // CSRF (Laravel が TokenMismatchException → HttpException(419) に
            // 事前変換するパス) は判定の compound 条件を helper に切り出して
            // match arm 1 行あたりの分岐数を減らす。
            $this->isCsrfMismatch($e) => $this->renderCsrfMismatch(),
            $e instanceof HttpException => $this->renderHttp($e),
            default => $this->renderInternal(),
        };
    }

    /**
     * Laravel の prepareException() が TokenMismatchException を HttpException(419)
     * に変換した状態を判定する。419 (Page Expired) は Symfony / Laravel Response 定数
     * に存在しない Laravel 拡張ステータスのためリテラルで持つ。
     */
    private function isCsrfMismatch(Throwable $e): bool
    {
        return $e instanceof HttpException && $e->getStatusCode() === 419;
    }

    private function renderValidation(ValidationException $e): JsonResponse
    {
        // Laravel の Validator->failed() は ['field' => ['RuleClass' => [...args]]] を返す。
        // OpenAPI 仕様の details では {field, rule} の配列が期待されるため、
        // ルールクラス名を snake_case に正規化して詰め直す。
        /** @var array<string, array<string, array<int, mixed>>> $failed */
        $failed = $e->validator->failed();

        $details = [];
        foreach ($failed as $field => $rules) {
            foreach (array_keys($rules) as $ruleClass) {
                // foreach の key は string|int になるが、failed() の内側 array key は
                // ルールクラス名 (string) なので string に決め打って良い
                $details[] = [
                    'field' => $field,
                    'rule' => $this->normalizeRuleName((string) $ruleClass),
                ];
            }
        }

        return new JsonResponse([
            'error' => [
                'code' => 'VALIDATION_FAILED',
                'message' => 'Validation failed for one or more fields',
                'details' => $details,
            ],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function renderNotFound(): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => 'NOT_FOUND',
                'message' => 'Resource not found',
            ],
        ], Response::HTTP_NOT_FOUND);
    }

    private function renderConflict(CategoryConflictException $e): JsonResponse
    {
        // 409 Conflict は OpenAPI 仕様の Conflict レスポンス形式
        // (Categories の name/slug 重複や、Conference 参照中の削除拒否)。
        // 個別事由は example の message でフロントへ伝える。
        return new JsonResponse([
            'error' => [
                'code' => 'CONFLICT',
                'message' => $e->getMessage(),
            ],
        ], Response::HTTP_CONFLICT);
    }

    private function renderServiceUnavailable(): JsonResponse
    {
        // 503 SERVICE_UNAVAILABLE は OpenAPI 仕様の Build endpoint 503 ケースで使う。
        // 主に Amplify アプリ未構成 (Webhook URL / App ID 未設定) を表現する。
        return new JsonResponse([
            'error' => [
                'code' => 'SERVICE_UNAVAILABLE',
                'message' => 'Build service is not configured',
            ],
        ], Response::HTTP_SERVICE_UNAVAILABLE);
    }

    private function renderCsrfMismatch(): JsonResponse
    {
        // Laravel デフォルトの 419 (Page Expired) ではなく、
        // OpenAPI 仕様 (data/openapi.yaml) の CsrfMismatch レスポンス例に揃え
        // 403 + CSRF_TOKEN_MISMATCH を返す。
        return new JsonResponse([
            'error' => [
                'code' => 'CSRF_TOKEN_MISMATCH',
                'message' => 'Invalid or missing CSRF token',
            ],
        ], Response::HTTP_FORBIDDEN);
    }

    private function renderInvalidOrigin(): JsonResponse
    {
        // OpenAPI 仕様 (data/openapi.yaml) の CsrfMismatch レスポンス
        // (invalidOrigin example) に整合: 403 + INVALID_ORIGIN。
        return new JsonResponse([
            'error' => [
                'code' => 'INVALID_ORIGIN',
                'message' => 'Request origin does not match the admin domain',
            ],
        ], Response::HTTP_FORBIDDEN);
    }

    private function renderHttp(HttpException $e): JsonResponse
    {
        $status = $e->getStatusCode();

        return new JsonResponse([
            'error' => [
                'code' => $this->codeForHttpStatus($status),
                'message' => $e->getMessage() !== '' ? $e->getMessage() : "HTTP {$status}",
            ],
        ], $status);
    }

    private function renderInternal(): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => 'INTERNAL_ERROR',
                'message' => 'An internal error occurred',
            ],
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * Laravel の Validator->failed() が返すルール識別子 (例: "Required", "Email",
     * "Illuminate\\Validation\\Rules\\Email") を snake_case の短縮形に正規化する。
     */
    private function normalizeRuleName(string $ruleIdentifier): string
    {
        // クラス名が含まれていれば basename を取る
        $basename = basename(str_replace('\\', '/', $ruleIdentifier));

        // PascalCase → snake_case (preg_replace は実用上 null を返さない入力で
        // 呼ぶが、PHPStan は null 可能性を見るため ?? で fallback)
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $basename) ?? $basename);
    }

    /**
     * HTTP ステータスから OpenAPI Error.code 列挙値を導出する。
     * 現状実コードベースで上位 match 分岐を抜けて renderHttp に到達するのは
     * 主に 403 (AccessDenied 等) と Laravel が投げるその他の HttpException のみ。
     * 必要になった時点でマッピングを追加する。
     */
    private function codeForHttpStatus(int $status): string
    {
        return match ($status) {
            Response::HTTP_FORBIDDEN => 'FORBIDDEN',
            default => 'HTTP_ERROR',
        };
    }
}

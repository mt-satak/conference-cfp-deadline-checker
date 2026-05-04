<?php

namespace App\Exceptions;

use App\Domain\Conferences\ConferenceNotFoundException;
use App\Exceptions\InvalidOriginException;
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
     * @return JsonResponse|null  null を返した場合は Laravel デフォルトに委譲
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
            $e instanceof ModelNotFoundException => $this->renderNotFound(),
            $e instanceof NotFoundHttpException => $this->renderNotFound(),
            // Laravel の prepareException() が TokenMismatchException を
            // HttpException(419) に事前変換するため、ここでは status code で判定する。
            // 419 (Page Expired) は Symfony 標準にも Laravel Response 定数にも
            // 存在しない Laravel 拡張ステータスのため、リテラルで指定する。
            ($e instanceof HttpException && $e->getStatusCode() === 419) => $this->renderCsrfMismatch(),
            $e instanceof HttpException => $this->renderHttp($e),
            default => $this->renderInternal(),
        };
    }

    private function renderValidation(ValidationException $e): JsonResponse
    {
        // Laravel の Validator->failed() は ['field' => ['RuleClass' => [...args]]] を返す。
        // OpenAPI 仕様の details では {field, rule} の配列が期待されるため、
        // ルールクラス名を snake_case に正規化して詰め直す。
        $details = [];
        foreach ($e->validator->failed() as $field => $rules) {
            foreach (array_keys($rules) as $ruleClass) {
                $details[] = [
                    'field' => $field,
                    'rule' => $this->normalizeRuleName($ruleClass),
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
                'message' => $e->getMessage() !== '' ? $e->getMessage() : $this->defaultMessageForStatus($status),
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
        // PascalCase → snake_case
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $basename));
    }

    /**
     * HTTP ステータスから OpenAPI Error.code 列挙値を導出する。
     * 仕様にマップが無いステータスは汎用 'HTTP_ERROR' を返す。
     */
    private function codeForHttpStatus(int $status): string
    {
        return match ($status) {
            Response::HTTP_NOT_FOUND => 'NOT_FOUND',
            Response::HTTP_FORBIDDEN => 'FORBIDDEN',
            Response::HTTP_CONFLICT => 'CONFLICT',
            Response::HTTP_UNPROCESSABLE_ENTITY => 'VALIDATION_FAILED',
            Response::HTTP_TOO_MANY_REQUESTS => 'RATE_LIMITED',
            Response::HTTP_SERVICE_UNAVAILABLE => 'SERVICE_UNAVAILABLE',
            default => 'HTTP_ERROR',
        };
    }

    private function defaultMessageForStatus(int $status): string
    {
        return match ($status) {
            Response::HTTP_NOT_FOUND => 'Not found',
            Response::HTTP_FORBIDDEN => 'Forbidden',
            Response::HTTP_INTERNAL_SERVER_ERROR => 'Internal server error',
            default => "HTTP {$status}",
        };
    }
}

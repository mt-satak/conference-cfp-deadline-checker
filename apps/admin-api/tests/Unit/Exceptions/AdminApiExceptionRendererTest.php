<?php

use App\Domain\Conferences\ConferenceNotFoundException;
use App\Exceptions\AdminApiExceptionRenderer;
use App\Exceptions\InvalidOriginException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * AdminApiExceptionRenderer の各 match 分岐を直接ユニットテストで網羅する。
 *
 * AdminApiExceptionHandlingTest (Feature) では HTTP 経由の主要シナリオを
 * 検証しているが、HttpException の status code 毎の codeForHttpStatus()
 * 分岐や defaultMessageForStatus() の分岐は HTTP 経由では到達困難なため、
 * 本ファイルでは Renderer を直接呼んで網羅する。
 *
 * 検証スコープ:
 *   - /admin/api/* 以外のリクエストはスキップ (null 返却)
 *   - 各 Throwable 種別の整形分岐
 *   - codeForHttpStatus / defaultMessageForStatus の各 match arm
 */

function adminApiRequest(string $path = '/admin/api/test'): Request
{
    return Request::create($path, 'GET');
}

function nonAdminApiRequest(): Request
{
    return Request::create('/some/other/path', 'GET');
}

it('/admin/api 配下でないリクエストには null を返してデフォルト処理に委譲する', function () {
    // Given: /admin/api 以外のパスへのリクエスト + 任意の例外
    $renderer = new AdminApiExceptionRenderer();
    $request = nonAdminApiRequest();

    // When: 整形を実行する
    $result = $renderer(new \RuntimeException('any'), $request);

    // Then: null が返り Laravel デフォルトハンドラに委譲される
    expect($result)->toBeNull();
});

it('ConferenceNotFoundException を 404 + NOT_FOUND に整形する', function () {
    // Given: ConferenceNotFoundException
    $renderer = new AdminApiExceptionRenderer();

    // When: 整形を実行する
    $response = $renderer(ConferenceNotFoundException::withId('abc'), adminApiRequest());

    // Then: 404 + NOT_FOUND
    expect($response->getStatusCode())->toBe(404);
    $payload = json_decode($response->getContent(), associative: true);
    expect($payload['error']['code'])->toBe('NOT_FOUND');
});

it('ModelNotFoundException を 404 + NOT_FOUND に整形する', function () {
    // Given: Eloquent の ModelNotFoundException
    $renderer = new AdminApiExceptionRenderer();

    // When: 整形を実行する
    $response = $renderer(new ModelNotFoundException(), adminApiRequest());

    // Then: 404 + NOT_FOUND
    expect($response->getStatusCode())->toBe(404);
    expect(json_decode($response->getContent(), true)['error']['code'])->toBe('NOT_FOUND');
});

it('NotFoundHttpException を 404 + NOT_FOUND に整形する', function () {
    // Given: ルート未定義などで投げられる NotFoundHttpException
    $renderer = new AdminApiExceptionRenderer();

    // When: 整形を実行する
    $response = $renderer(new NotFoundHttpException(), adminApiRequest());

    // Then: 404 + NOT_FOUND
    expect($response->getStatusCode())->toBe(404);
    expect(json_decode($response->getContent(), true)['error']['code'])->toBe('NOT_FOUND');
});

it('InvalidOriginException を 403 + INVALID_ORIGIN に整形する', function () {
    // Given: VerifyOrigin が投げる InvalidOriginException
    $renderer = new AdminApiExceptionRenderer();

    // When: 整形を実行する
    $response = $renderer(new InvalidOriginException('mismatch'), adminApiRequest());

    // Then: 403 + INVALID_ORIGIN
    expect($response->getStatusCode())->toBe(403);
    expect(json_decode($response->getContent(), true)['error']['code'])->toBe('INVALID_ORIGIN');
});

it('HttpException(419) を 403 + CSRF_TOKEN_MISMATCH に整形する (Laravel が TokenMismatchException を 419 に変換するため)', function () {
    // Given: Laravel が prepareException() で TokenMismatchException → HttpException(419) に変換した状態
    $renderer = new AdminApiExceptionRenderer();

    // When: 整形を実行する
    $response = $renderer(new HttpException(419, 'CSRF token mismatch.'), adminApiRequest());

    // Then: 403 + CSRF_TOKEN_MISMATCH (デフォルト Laravel の 419 とは異なる)
    expect($response->getStatusCode())->toBe(403);
    expect(json_decode($response->getContent(), true)['error']['code'])->toBe('CSRF_TOKEN_MISMATCH');
});

it('予期しない Throwable を 500 + INTERNAL_ERROR に整形する', function () {
    // Given: 任意の RuntimeException
    $renderer = new AdminApiExceptionRenderer();

    // When: 整形を実行する
    $response = $renderer(new \RuntimeException('boom'), adminApiRequest());

    // Then: 500 + INTERNAL_ERROR
    expect($response->getStatusCode())->toBe(500);
    expect(json_decode($response->getContent(), true)['error']['code'])->toBe('INTERNAL_ERROR');
});

describe('renderHttp 経由の HTTP ステータスコード -> コード変換', function () {
    it('HttpException(403) → 403 + FORBIDDEN', function () {
        // Given: 403 HttpException
        $renderer = new AdminApiExceptionRenderer();

        // When: 整形を実行する
        $response = $renderer(new HttpException(403, 'access denied'), adminApiRequest());

        // Then: 403 + FORBIDDEN
        expect($response->getStatusCode())->toBe(403);
        expect(json_decode($response->getContent(), true)['error']['code'])->toBe('FORBIDDEN');
    });

    it('HttpException(409) → 409 + CONFLICT', function () {
        // Given: 409 HttpException
        $renderer = new AdminApiExceptionRenderer();

        // When: 整形を実行する
        $response = $renderer(new HttpException(409, 'conflict'), adminApiRequest());

        // Then: 409 + CONFLICT
        expect($response->getStatusCode())->toBe(409);
        expect(json_decode($response->getContent(), true)['error']['code'])->toBe('CONFLICT');
    });

    it('HttpException(422) → 422 + VALIDATION_FAILED', function () {
        // Given: 422 HttpException (renderHttp 経由の汎用 422、ValidationException ではない)
        $renderer = new AdminApiExceptionRenderer();

        // When: 整形を実行する
        $response = $renderer(new HttpException(422, 'unprocessable'), adminApiRequest());

        // Then: 422 + VALIDATION_FAILED
        expect($response->getStatusCode())->toBe(422);
        expect(json_decode($response->getContent(), true)['error']['code'])->toBe('VALIDATION_FAILED');
    });

    it('HttpException(429) → 429 + RATE_LIMITED', function () {
        // Given: 429 HttpException
        $renderer = new AdminApiExceptionRenderer();

        // When: 整形を実行する
        $response = $renderer(new HttpException(429, 'too many requests'), adminApiRequest());

        // Then: 429 + RATE_LIMITED
        expect($response->getStatusCode())->toBe(429);
        expect(json_decode($response->getContent(), true)['error']['code'])->toBe('RATE_LIMITED');
    });

    it('HttpException(503) → 503 + SERVICE_UNAVAILABLE', function () {
        // Given: 503 HttpException
        $renderer = new AdminApiExceptionRenderer();

        // When: 整形を実行する
        $response = $renderer(new HttpException(503, 'maintenance'), adminApiRequest());

        // Then: 503 + SERVICE_UNAVAILABLE
        expect($response->getStatusCode())->toBe(503);
        expect(json_decode($response->getContent(), true)['error']['code'])->toBe('SERVICE_UNAVAILABLE');
    });

    it('未マップのステータスは汎用 HTTP_ERROR にフォールバックする', function () {
        // Given: マップに無い 418 (I'm a teapot)
        $renderer = new AdminApiExceptionRenderer();

        // When: 整形を実行する
        $response = $renderer(new HttpException(418, 'i am a teapot'), adminApiRequest());

        // Then: 418 + HTTP_ERROR (汎用フォールバック)
        expect($response->getStatusCode())->toBe(418);
        expect(json_decode($response->getContent(), true)['error']['code'])->toBe('HTTP_ERROR');
    });
});

describe('defaultMessageForStatus (HttpException メッセージ空のフォールバック)', function () {
    it('HttpException(404, "") → "Not found"', function () {
        // Given: メッセージ空の 404 HttpException
        $renderer = new AdminApiExceptionRenderer();

        // When: 整形を実行する
        $response = $renderer(new HttpException(404, ''), adminApiRequest());

        // Then: メッセージは defaultMessageForStatus の "Not found"
        expect(json_decode($response->getContent(), true)['error']['message'])->toBe('Not found');
    });

    it('HttpException(403, "") → "Forbidden"', function () {
        // Given: メッセージ空の 403 HttpException
        $renderer = new AdminApiExceptionRenderer();

        // When: 整形を実行する
        $response = $renderer(new HttpException(403, ''), adminApiRequest());

        // Then: "Forbidden"
        expect(json_decode($response->getContent(), true)['error']['message'])->toBe('Forbidden');
    });

    it('HttpException(500, "") → "Internal server error"', function () {
        // Given: メッセージ空の 500 HttpException
        $renderer = new AdminApiExceptionRenderer();

        // When: 整形を実行する
        $response = $renderer(new HttpException(500, ''), adminApiRequest());

        // Then: "Internal server error"
        expect(json_decode($response->getContent(), true)['error']['message'])->toBe('Internal server error');
    });

    it('未マップのステータスは "HTTP {status}" 形式にフォールバックする', function () {
        // Given: マップに無い 418、メッセージ空
        $renderer = new AdminApiExceptionRenderer();

        // When: 整形を実行する
        $response = $renderer(new HttpException(418, ''), adminApiRequest());

        // Then: "HTTP 418" 形式
        expect(json_decode($response->getContent(), true)['error']['message'])->toBe('HTTP 418');
    });
});

// NOTE: ValidationException 由来の details 整形 (renderValidation + normalizeRuleName)
// は AdminApiExceptionHandlingTest (Feature) で実 HTTP リクエスト経由で検証済み。
// 当該パスを Unit から呼ぶには Laravel コンテナ初期化が必要 (Validator::make が
// Facade 経由) なため、本 Unit テストファイルでは扱わない。

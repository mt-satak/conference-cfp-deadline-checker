<?php

use App\Exceptions\AdminApiExceptionRenderer;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * AdminApiExceptionRenderer の Unit テスト。
 *
 * Renderer の振る舞いの主要シナリオは Feature 群 (HTTP 経由) で検証済み:
 *   - tests/Feature/AdminApiExceptionHandlingTest (404/403/405/500/Validation)
 *   - tests/Feature/AdminApiVerifyOriginTest (InvalidOrigin)
 *   - tests/Feature/AdminApiCsrfTest (TokenMismatch → 419)
 *   - tests/Feature/Conferences/* (ConferenceNotFoundException)
 *
 * 本ファイルでは「Feature では構造的に踏めない 2 つの分岐のみ」を Unit から直接
 * __invoke() を呼んで検証する。それ以外の例外型を Unit から呼ぶテストは
 * Feature と同じ assertion を別の起動点で繰り返すだけになる (= padding) ため
 * 意図的に書かない。
 *
 * Renderer の C1 (Branch Coverage) が層別閾値ギリギリに留まる理由は
 * docs/test-strategy.md (TODO #15) で詳述する: match (true) + instanceof + && の
 * compound 条件を xdebug が micro-branch に分割カウントする tooling 都合であり、
 * 振る舞いの担保は Feature 側の assertion で完結している。
 */
it('/admin/api 配下でないリクエストには null を返してデフォルト処理に委譲する', function () {
    // Given: 非 admin/api パス
    // (Feature では非 admin パスの例外が Laravel 別経路を通って Renderer に到達せず、
    //  $request->is('admin/api/*') の FALSE 分岐が踏めないため Unit 必須)
    $renderer = new AdminApiExceptionRenderer;
    $request = Request::create('/some/other/path', 'GET');

    // When
    $result = $renderer(new RuntimeException('any'), $request);

    // Then: null = Laravel デフォルトハンドラに委譲される
    expect($result)->toBeNull();
});

it('HttpException で message 空の場合は "HTTP {status}" にフォールバックする', function () {
    // Given: 空メッセージ HttpException
    // (HTTP リクエスト経由では空メッセージ HttpException を構築する自然な経路が
    //  存在しないため Unit 必須。renderHttp の ternary FALSE 側分岐をカバー)
    $renderer = new AdminApiExceptionRenderer;
    $request = Request::create('/admin/api/test', 'GET');

    // When
    $response = $renderer(new HttpException(403, ''), $request);

    // Then: 元の getMessage() が空なので "HTTP 403" が代入される
    $payload = json_decode($response->getContent(), associative: true);
    expect($payload['error']['message'])->toBe('HTTP 403');
});

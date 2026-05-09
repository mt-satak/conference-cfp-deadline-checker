<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * CloudFront 経由のリクエストかを Custom Origin Header で検証するミドルウェア (Issue #77)。
 *
 * 構成:
 * - Lambda Function URL の AuthType=NONE (= 認証なしで誰でも叩ける状態)
 * - CloudFront が Origin に転送する際 Custom Header `X-CloudFront-Secret: <secret>` を付与
 * - 本ミドルウェアでこの header が config('cloudfront.origin_secret') と一致するかを検証
 *
 * 不一致 / 未指定 = Function URL 直アクセスとみなして 403。
 *
 * secret 未設定時の挙動 (Issue #177 #1 で fail-closed 化):
 * - APP_ENV=local / testing: 無効化 (= ローカル開発 + テストの利便性維持)
 * - APP_ENV=production: 例外 throw して 500 で停止 (= CDK 配線ミス / config:cache stale 等の
 *   事故時に Function URL を素通しさせない)
 */
class CloudFrontSecretMiddleware
{
    /** CloudFront Custom Origin Header の名前 */
    private const HEADER_NAME = 'X-CloudFront-Secret';

    /**
     * 本番判定で使う APP_ENV 値以外を「開発系環境」として bypass する。
     * Laravel の慣習に従い production / staging を本番扱いとする。
     */
    private const PROD_LIKE_ENVS = ['production', 'staging'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('cloudfront.origin_secret');
        if (! is_string($expected) || $expected === '') {
            $env = config('app.env');
            if (is_string($env) && in_array($env, self::PROD_LIKE_ENVS, true)) {
                // 本番系で secret が無いのは設定事故。silent disable せず明示的に止める。
                throw new RuntimeException(
                    'CLOUDFRONT_ORIGIN_SECRET is required in production but is not configured. '
                    .'Check Lambda env wiring (CDK admin-api.ts) and config cache.',
                );
            }

            // ローカル / テスト環境では無効化 (= Issue #77 当初の挙動)
            return $next($request);
        }

        $provided = $request->header(self::HEADER_NAME);
        if (! is_string($provided) || ! hash_equals($expected, $provided)) {
            return new Response('Forbidden', 403);
        }

        return $next($request);
    }
}

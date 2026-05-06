<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
 * 注意:
 * - secret は CDK 経由で Lambda 環境変数として渡される。Lambda 環境変数なので
 *   config:cache で固定化されても問題なし (rotate しない静的値)。
 * - config('cloudfront.origin_secret') が null/空の場合は **無効化** され next を呼ぶ。
 *   ローカル開発で env を入れない場合に admin UI を起動できるようにするため。
 *   本番では必ず env を設定すること (CDK で自動的に設定される)。
 */
class CloudFrontSecretMiddleware
{
    /** CloudFront Custom Origin Header の名前 */
    private const HEADER_NAME = 'X-CloudFront-Secret';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('cloudfront.origin_secret');
        if (! is_string($expected) || $expected === '') {
            // ローカル開発時 (config 未設定) は無効化
            return $next($request);
        }

        $provided = $request->header(self::HEADER_NAME);
        if (! is_string($provided) || ! hash_equals($expected, $provided)) {
            return new Response('Forbidden', 403);
        }

        return $next($request);
    }
}

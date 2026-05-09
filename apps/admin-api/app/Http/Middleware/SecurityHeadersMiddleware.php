<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * admin 応答にセキュリティヘッダを付与するミドルウェア (Issue #177 #2)。
 *
 * 公開フロント (cfp-checker.dev) は CDK の CloudFront response headers policy で
 * これらが付与されているが、admin (admin.cfp-checker.dev) は Lambda 経由応答で
 * 同等の保護が無かった。Basic 認証で守られているとはいえ、Blade の output 漏れ等の
 * defense-in-depth として CSP / X-Frame-Options / nosniff を付ける。
 *
 * 各ヘッダの目的:
 * - Content-Security-Policy:
 *     XSS の defense-in-depth。inline script を全面禁止 (= 'unsafe-inline' なし)。
 *     vite 経由でビルドした /build/* は同一オリジン提供のため 'self' で OK。
 *     style-src も 'self' のみで Tailwind v4 ビルド成果物 (= 外部 CSS) のみ許可。
 * - X-Content-Type-Options: nosniff
 *     Content-Type 偏移による誤実行を防ぐ。
 * - Referrer-Policy: strict-origin-when-cross-origin
 *     クロスオリジンへの Referer 漏れを最小化。同一オリジン内では full URL 許可。
 * - X-Frame-Options: DENY
 *     CSP frame-ancestors の旧ブラウザ互換。clickjacking 防御。
 *
 * 適用範囲:
 *   admin Blade SSR (= /admin) を主目的とするが、/admin/api / /api/public の
 *   JSON 応答にも付与する (= 害は無く、適用範囲を route group ごとに切り替える
 *   コストの方が大きい)。
 */
class SecurityHeadersMiddleware
{
    /**
     * CSP ディレクティブ文字列。
     *
     * directive ごとの根拠:
     *   default-src 'self': 全リソースは同一オリジンのみ
     *   script-src  'self': inline script 禁止 (= 'unsafe-inline' を排除)。
     *                       admin Blade の onsubmit 等は data 属性 + 外部 JS に外出し済
     *   style-src   'self': Tailwind ビルド成果物のみ。inline style 不要
     *   img-src     'self' data:: アイコン等の data URI は許容
     *   font-src    'self' data:: 同上
     *   connect-src 'self': admin UI から fetch する先は同一オリジンのみ
     *   frame-ancestors 'none': clickjacking 全面禁止
     *   base-uri    'self': base 要素による redirect 攻撃防止
     *   object-src  'none': <object> / <embed> / <applet> 禁止
     *   form-action 'self': form の action は同一オリジンのみ
     */
    private const CSP =
        "default-src 'self'; "
        ."script-src 'self'; "
        ."style-src 'self'; "
        ."img-src 'self' data:; "
        ."font-src 'self' data:; "
        ."connect-src 'self'; "
        ."frame-ancestors 'none'; "
        ."base-uri 'self'; "
        ."object-src 'none'; "
        ."form-action 'self'";

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', self::CSP);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }
}

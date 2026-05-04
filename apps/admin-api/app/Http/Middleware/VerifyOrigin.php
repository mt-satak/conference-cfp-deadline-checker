<?php

namespace App\Http\Middleware;

use App\Exceptions\InvalidOriginException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /admin/api 配下の状態変更系リクエストで Origin / Referer ヘッダを検証するミドルウェア。
 *
 * セキュリティ要件 S3 (Origin / Referer 検証) の実装。CSRF の二重防御として、
 * トークンとは独立した経路でクロスサイトリクエストを排除する。
 *
 * - 安全性メソッド (GET/HEAD/OPTIONS): 検証対象外、素通し。
 * - 状態変更系メソッド (POST/PUT/PATCH/DELETE): Origin が APP_URL と一致しない場合
 *   は InvalidOriginException を投げる。
 * - Origin ヘッダが無い場合は Referer ヘッダから origin (scheme://host[:port])
 *   を抽出して比較する。両方無い場合は不一致扱い。
 *
 * 失敗時のレスポンス整形 (403 + INVALID_ORIGIN) は AdminApiExceptionRenderer 側で行う。
 */
class VerifyOrigin
{
    /** 状態変更系の HTTP メソッド (この一覧外は検証対象外) */
    private const STATE_CHANGING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), self::STATE_CHANGING_METHODS, true)) {
            return $next($request);
        }

        $allowed = $this->getAllowedOrigin();
        $actual = $this->extractRequestOrigin($request);

        if ($actual === null || $actual !== $allowed) {
            throw new InvalidOriginException('Request origin does not match the admin domain');
        }

        return $next($request);
    }

    /**
     * 許可する origin を取得する (config('app.url') の正規化形)。
     * パースに失敗した場合は元の URL の末尾スラッシュだけ落とした文字列で代替。
     */
    private function getAllowedOrigin(): string
    {
        $raw = config('app.url');
        // config('app.url') は string|null|... を返しうるため明示変換
        $url = is_string($raw) ? $raw : '';

        return $this->normalizeUrlToOrigin($url) ?? rtrim($url, '/');
    }

    /**
     * リクエストから origin を抽出する。Origin ヘッダ優先、無ければ Referer から復元。
     * 両方とも欠落・不正な形式の場合は null を返す (= 検証失敗扱い)。
     */
    private function extractRequestOrigin(Request $request): ?string
    {
        $origin = $request->header('Origin');
        if (is_string($origin) && $origin !== '') {
            return rtrim($origin, '/');
        }

        $referer = $request->header('Referer');
        if (is_string($referer) && $referer !== '') {
            return $this->normalizeUrlToOrigin($referer);
        }

        return null;
    }

    /**
     * URL 文字列を origin 形式 (scheme://host[:port]) に正規化する。
     * パース不可・必須フィールド不足の場合は null。
     */
    private function normalizeUrlToOrigin(string $url): ?string
    {
        $parsed = parse_url($url);
        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            return null;
        }

        $origin = $parsed['scheme'].'://'.$parsed['host'];
        if (isset($parsed['port'])) {
            $origin .= ':'.$parsed['port'];
        }

        return $origin;
    }
}

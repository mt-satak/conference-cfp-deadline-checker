<?php

declare(strict_types=1);

namespace App\Domain\Conferences;

/**
 * Conference の officialUrl 操作ユーティリティ (Issue #152 Phase 1)。
 *
 * 自動巡回 (LLM 抽出 → 既存 conference 重複検知) で URL の表記揺れを吸収して
 * 同一カンファレンスを同じ key にマップするための純粋関数を提供する。
 *
 * Domain 層に置く理由:
 *   - 「officialUrl をどう同一視するか」は Conference aggregate 固有の業務ルール
 *   - 純粋関数 (副作用なし) なので Infrastructure 層から自由に呼べる
 *   - 重複検知の単位を変えたくなった時 (例: path 末尾を残すかどうか) に
 *     1 箇所変えれば admin API と auto-crawl 両方に反映される
 *
 * 正規化ルール:
 *   - scheme: lowercase + http → https に統一
 *   - host:   lowercase + 先頭の www. 削除
 *   - path:   trailing slash 削除 (root "/" は維持)
 *   - query / fragment: 捨てる
 *   - path の case は維持 (= サーバー側の case sensitivity に依存するため)
 *
 * 詳細議論は Issue #152 のコメント参照。
 */
final class OfficialUrl
{
    /** 正規化を行う。host が抽出できない不正 URL は入力をそのまま返す (defensive)。 */
    public static function normalize(string $url): string
    {
        $parsed = parse_url($url);
        if ($parsed === false || ! isset($parsed['host'])) {
            return $url;
        }

        // scheme: 小文字化 + http → https に統一 (= 同一サイトを 1 つに収束)
        $rawScheme = $parsed['scheme'] ?? 'https';
        $scheme = strtolower($rawScheme) === 'http' ? 'https' : strtolower($rawScheme);

        // host: 小文字 + 先頭 www. を削除
        $host = strtolower((string) $parsed['host']);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        // path: trailing slash 削除 (root "/" は維持)
        $path = $parsed['path'] ?? '/';
        $path = $path === '/' ? '/' : rtrim($path, '/');

        // query / fragment は捨てる (= UTM や anchor を吸収)
        return "{$scheme}://{$host}{$path}";
    }
}

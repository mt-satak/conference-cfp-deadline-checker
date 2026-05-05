<?php

namespace App\Application\Conferences\Extraction;

use RuntimeException;

/**
 * 公式サイト HTML 取得失敗を表す例外 (Issue #40 Phase 3)。
 *
 * 主に HTTP 層で 502 (Bad Gateway) 相当に整形してユーザに「URL を確認してください」
 * と促す UX を想定。本例外は Application 層から bubble up し、UI 側で握り直す。
 *
 * カテゴリ別 named constructor で原因を区別し、観測ログ整形時に種別が分かるようにする。
 */
class HtmlFetchFailedException extends RuntimeException
{
    public static function networkError(string $url, string $reason): self
    {
        return new self("Failed to fetch HTML from {$url}: network error ({$reason})");
    }

    public static function statusError(string $url, int $status): self
    {
        return new self("Failed to fetch HTML from {$url}: HTTP {$status}");
    }

    public static function tooLarge(string $url, int $size, int $limit): self
    {
        return new self("HTML from {$url} exceeds size limit ({$size} > {$limit} bytes)");
    }

    public static function notHttps(string $url): self
    {
        return new self("URL must be HTTPS: {$url}");
    }
}

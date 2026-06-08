<?php

declare(strict_types=1);

namespace App\Domain\CfpSources;

use Exception;

/**
 * CfP ソースの一意性違反 (= 同じ URL の source が既に存在) で投げる Domain 例外。
 *
 * 一意性キー: url (正規化後)。
 *
 * HTTP レイヤでは AdminApiExceptionRenderer が 409 + CONFLICT に整形する。
 */
class CfpSourceConflictException extends Exception
{
    public static function withUrl(string $url): self
    {
        return new self("CfP source URL already exists: {$url}");
    }
}

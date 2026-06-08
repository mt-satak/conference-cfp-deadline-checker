<?php

declare(strict_types=1);

namespace App\Domain\CfpSources;

use Exception;

/**
 * 指定された sourceId の CfP ソースが見つからなかった時に投げる Domain 例外。
 *
 * HTTP レイヤでは AdminApiExceptionRenderer が 404 + NOT_FOUND に整形する。
 */
class CfpSourceNotFoundException extends Exception
{
    public static function withId(string $sourceId): self
    {
        return new self("CfP source not found: {$sourceId}");
    }
}

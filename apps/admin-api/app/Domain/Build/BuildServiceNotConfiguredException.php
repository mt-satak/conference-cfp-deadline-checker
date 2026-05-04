<?php

namespace App\Domain\Build;

use Exception;

/**
 * ビルドサービス (Amplify Webhook URL / アプリ ID) が未構成の時に投げる Domain 例外。
 *
 * HTTP 層では AdminApiExceptionRenderer が 503 + SERVICE_UNAVAILABLE に整形する
 * (OpenAPI 仕様の Build endpoint 503 ケースに対応)。
 *
 * 想定発生条件:
 * - 開発初期で Amplify アプリがまだ作られていない
 * - 環境変数 (config/amplify.php 経由) が未設定
 */
class BuildServiceNotConfiguredException extends Exception
{
    public static function webhookUrlMissing(): self
    {
        return new self('Build service is not configured: Amplify webhook URL is missing');
    }

    public static function appIdMissing(): self
    {
        return new self('Build service is not configured: Amplify app ID is missing');
    }
}

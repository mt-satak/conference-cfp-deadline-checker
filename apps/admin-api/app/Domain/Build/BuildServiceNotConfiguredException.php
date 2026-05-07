<?php

namespace App\Domain\Build;

use Exception;

/**
 * ビルドサービス (GitHub App) が未構成の時に投げる Domain 例外。
 *
 * HTTP 層では AdminApiExceptionRenderer が 503 + SERVICE_UNAVAILABLE に整形する
 * (OpenAPI 仕様の Build endpoint 503 ケースに対応)。
 *
 * 想定発生条件:
 * - 開発初期で GitHub App がまだ未作成 / Secrets Manager 値が未設定
 * - .env や AWS Secrets Manager に GitHub App の 3 値 (app_id / installation_id /
 *   private_key) のいずれかが空
 *
 * Phase 5.3 で AWS Amplify から GitHub Actions (GitHub App 経由) に経路を
 * 切り替えた際に factory を新経路用に揃えた。
 */
class BuildServiceNotConfiguredException extends Exception
{
    public static function appIdMissing(): self
    {
        return new self('Build service is not configured: GitHub app ID is missing');
    }

    public static function installationIdMissing(): self
    {
        return new self('Build service is not configured: GitHub App installation ID is missing');
    }

    public static function privateKeyMissing(): self
    {
        return new self('Build service is not configured: GitHub App private key is missing');
    }
}

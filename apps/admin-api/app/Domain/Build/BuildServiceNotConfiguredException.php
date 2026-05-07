<?php

namespace App\Domain\Build;

use Exception;

/**
 * ビルドサービスが未構成の時に投げる Domain 例外。
 *
 * HTTP 層では AdminApiExceptionRenderer が 503 + SERVICE_UNAVAILABLE に整形する
 * (OpenAPI 仕様の Build endpoint 503 ケースに対応)。
 *
 * 想定発生条件:
 * - 開発初期で外部サービス (Amplify / GitHub App) がまだ未構成
 * - 環境変数 / Secrets Manager の値が未設定
 *
 * Phase 5.3 で AWS Amplify から GitHub Actions (GitHub App 経由) に経路が
 * 切り替わったため、新経路用の factory (installationIdMissing / privateKeyMissing)
 * を追加。旧 factory は移行期間の互換のため保持する。
 */
class BuildServiceNotConfiguredException extends Exception
{
    public static function webhookUrlMissing(): self
    {
        return new self('Build service is not configured: Amplify webhook URL is missing');
    }

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

<?php

declare(strict_types=1);

namespace App\Domain\GitHubApp;

use InvalidArgumentException;

/**
 * GitHub App 認証に必要な 3 値を保持する不変な値オブジェクト (Phase 5.3)。
 *
 * AWS Secrets Manager から取得した raw 値をこの VO に詰めてから Infrastructure
 * 層に渡す設計。空文字を Domain 段階で弾くことで、無効な credential のまま
 * 外部 API を叩いて 401 を踏むパスを防ぐ。
 *
 * privateKey は PEM 形式 (BEGIN/END で囲まれた base64) を想定するが、Domain
 * では形式詳細を強制せず空文字チェックのみに留める。形式不正は JWT 署名時に
 * Infrastructure 層で OpenSSL がエラーを返すため、検証経路は重複させない。
 */
final readonly class GitHubAppCredentials
{
    public function __construct(
        public string $appId,
        public string $installationId,
        public string $privateKey,
    ) {
        if ($appId === '') {
            throw new InvalidArgumentException('GitHubAppCredentials: appId must not be empty');
        }
        if ($installationId === '') {
            throw new InvalidArgumentException('GitHubAppCredentials: installationId must not be empty');
        }
        if ($privateKey === '') {
            throw new InvalidArgumentException('GitHubAppCredentials: privateKey must not be empty');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\GitHubApp;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * GitHub App の installation access token と失効時刻を保持する不変な VO。
 *
 * GitHub の `POST /app/installations/{id}/access_tokens` のレスポンスを
 * Domain 値として表現する。期限内なら再利用、期限切れなら再取得する判定を
 * Infrastructure 層に明示する責務を持つ。
 *
 * 失効判定は厳密に「expiresAt 以降は失効」とする (= ちょうど境界も失効扱い)。
 * 境界を有効と扱うと API 呼び出しのレースで 401 を踏み得るため、安全サイドに倒す。
 */
final readonly class InstallationToken
{
    public function __construct(
        public string $token,
        public DateTimeImmutable $expiresAt,
    ) {
        if ($token === '') {
            throw new InvalidArgumentException('InstallationToken: token must not be empty');
        }
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }
}

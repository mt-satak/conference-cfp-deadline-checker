<?php

namespace App\Application\Build;

use App\Domain\Build\BuildServiceNotConfiguredException;
use App\Domain\Build\BuildStatus;
use App\Domain\Build\BuildStatusReader;

/**
 * 直近のビルド状態を取得する UseCase。
 *
 * OpenAPI 仕様: 履歴は最大 10 件まで返却。
 * BuildStatusReader (interface) に委譲して取得する。
 */
class ListBuildStatusesUseCase
{
    /** OpenAPI 仕様: ビルド履歴は最大 10 件まで */
    public const DEFAULT_LIMIT = 10;

    public function __construct(
        private readonly BuildStatusReader $reader,
    ) {}

    /**
     * @return BuildStatus[] 新しい順
     *
     * @throws BuildServiceNotConfiguredException
     */
    public function execute(int $limit = self::DEFAULT_LIMIT): array
    {
        return $this->reader->listRecent($limit);
    }
}

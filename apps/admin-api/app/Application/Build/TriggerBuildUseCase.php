<?php

namespace App\Application\Build;

use App\Domain\Build\BuildServiceNotConfiguredException;
use App\Domain\Build\BuildTriggerer;

/**
 * ビルド再実行をトリガーする UseCase。
 *
 * 責務:
 * - BuildTriggerer (interface) に委譲してビルドを起動する
 * - 起動時刻 (ISO 8601 / Asia/Tokyo) を返す
 *
 * GitHub App 未構成等の場合は BuildTriggerer が
 * BuildServiceNotConfiguredException を投げ、HTTP 層で 503 に整形される。
 */
class TriggerBuildUseCase
{
    public function __construct(
        private readonly BuildTriggerer $triggerer,
    ) {}

    /**
     * @return string 受付時刻 (ISO 8601)
     *
     * @throws BuildServiceNotConfiguredException
     */
    public function execute(): string
    {
        return $this->triggerer->trigger();
    }
}

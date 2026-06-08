<?php

declare(strict_types=1);

namespace App\Application\CfpSources;

use App\Domain\CfpSources\CfpSourceNotFoundException;
use App\Domain\CfpSources\CfpSourceRepository;

/**
 * CfP ソース削除 UseCase (Issue #200 PR-1)。
 *
 * Repository->deleteById() の戻り値で実削除有無を判定し、削除対象が無ければ
 * CfpSourceNotFoundException を投げる (= UI 側で 404 整形)。
 */
class DeleteCfpSourceUseCase
{
    public function __construct(
        private readonly CfpSourceRepository $repository,
    ) {}

    /**
     * @throws CfpSourceNotFoundException
     */
    public function execute(string $sourceId): void
    {
        if (! $this->repository->deleteById($sourceId)) {
            throw CfpSourceNotFoundException::withId($sourceId);
        }
    }
}

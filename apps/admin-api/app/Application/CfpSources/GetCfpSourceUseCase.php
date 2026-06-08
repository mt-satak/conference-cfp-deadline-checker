<?php

declare(strict_types=1);

namespace App\Application\CfpSources;

use App\Domain\CfpSources\CfpSource;
use App\Domain\CfpSources\CfpSourceNotFoundException;
use App\Domain\CfpSources\CfpSourceRepository;

/**
 * CfP ソース 1 件取得 UseCase (Issue #200 PR-1)。
 *
 * 該当無し: CfpSourceNotFoundException。HTTP 層で 404 整形。
 */
class GetCfpSourceUseCase
{
    public function __construct(
        private readonly CfpSourceRepository $repository,
    ) {}

    /**
     * @throws CfpSourceNotFoundException
     */
    public function execute(string $sourceId): CfpSource
    {
        $source = $this->repository->findById($sourceId);
        if ($source === null) {
            throw CfpSourceNotFoundException::withId($sourceId);
        }

        return $source;
    }
}

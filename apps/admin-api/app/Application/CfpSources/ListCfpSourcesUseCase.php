<?php

declare(strict_types=1);

namespace App\Application\CfpSources;

use App\Domain\CfpSources\CfpSource;
use App\Domain\CfpSources\CfpSourceRepository;

/**
 * CfP ソース一覧取得 UseCase (Issue #200 PR-1)。
 *
 * Repository から全件取得し、createdAt 昇順 (= 追加順) で返す。
 */
class ListCfpSourcesUseCase
{
    public function __construct(
        private readonly CfpSourceRepository $repository,
    ) {}

    /**
     * @return CfpSource[]
     */
    public function execute(): array
    {
        $sources = $this->repository->findAll();

        usort(
            $sources,
            static fn (CfpSource $a, CfpSource $b): int => strcmp($a->createdAt, $b->createdAt),
        );

        return $sources;
    }
}

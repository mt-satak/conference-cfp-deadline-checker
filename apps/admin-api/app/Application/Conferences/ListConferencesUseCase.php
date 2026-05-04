<?php

namespace App\Application\Conferences;

use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceRepository;

/**
 * カンファレンス全件取得 UseCase。
 *
 * 責務:
 * - Repository から全件を取得して呼び出し元に返す
 *
 * フィルタ・ソート・ページネーションは UseCase スコープ外。
 * HTTP 層 (Controller) で OpenAPI のクエリパラメータに従って加工する。
 */
class ListConferencesUseCase
{
    public function __construct(
        private readonly ConferenceRepository $repository,
    ) {}

    /**
     * @return Conference[]
     */
    public function execute(): array
    {
        return $this->repository->findAll();
    }
}

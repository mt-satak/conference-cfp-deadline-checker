<?php

namespace App\Application\Conferences;

use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceRepository;
use App\Domain\Conferences\ConferenceStatus;

/**
 * カンファレンス全件取得 UseCase。
 *
 * 責務:
 * - Repository から全件を取得して呼び出し元に返す
 * - status 指定があれば該当ステータスのみに絞り込む
 *
 * ソート・ページネーションは UseCase スコープ外。
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
    public function execute(?ConferenceStatus $statusFilter = null): array
    {
        $all = $this->repository->findAll();
        if ($statusFilter === null) {
            return $all;
        }

        return array_values(array_filter(
            $all,
            static fn (Conference $c): bool => $c->status === $statusFilter,
        ));
    }
}

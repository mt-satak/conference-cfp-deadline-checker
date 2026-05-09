<?php

declare(strict_types=1);

namespace App\Application\Conferences;

/**
 * DedupeDraftsUseCase の結果サマリ (Issue #169 Phase 2)。
 *
 * artisan command の出力 + 構造化ログ用。
 */
final readonly class DedupeDraftsResult
{
    /**
     * @param  string[]  $deletedIds  削除した (or dry-run 時に削除予定の) Draft conferenceId 一覧
     */
    public function __construct(
        public int $totalDrafts,
        public int $duplicateGroups,
        public int $deletedCount,
        public array $deletedIds,
        public bool $dryRun,
    ) {}
}

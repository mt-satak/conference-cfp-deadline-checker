<?php

declare(strict_types=1);

namespace App\Application\Conferences;

/**
 * ArchivePastConferencesUseCase の結果サマリ (Issue #165 Phase 2)。
 *
 * 構造化ログや artisan コマンドの出力に使う。
 */
final readonly class ArchivePastResult
{
    /**
     * @param  string[]  $archivedIds  実際に Archived 状態に遷移させた Conference の ID 一覧
     *                                 (dryRun=true の場合は「対象になったが save しなかった」一覧)
     */
    public function __construct(
        public int $totalChecked,
        public int $archivedCount,
        public array $archivedIds,
        public bool $dryRun,
    ) {}
}

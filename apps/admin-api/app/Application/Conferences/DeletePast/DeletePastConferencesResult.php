<?php

declare(strict_types=1);

namespace App\Application\Conferences\DeletePast;

/**
 * DeletePastConferencesUseCase の結果サマリ (Issue #221 PR-1)。
 *
 * 構造化ログや artisan コマンドの出力に使う。
 */
final readonly class DeletePastConferencesResult
{
    /**
     * @param  list<string>  $deletedIds  実際に削除した Conference の ID 一覧
     *                                    (dryRun=true の場合は「対象になったが削除しなかった」一覧)
     */
    public function __construct(
        public int $totalChecked,
        public int $deletedCount,
        public array $deletedIds,
        public bool $dryRun,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Application\Conferences\CleanupAutoCrawlDrafts;

/**
 * AutoCrawl 起源 Draft 一括削除 (Issue #188 PR-2) の結果サマリ。
 *
 * フィールド:
 *   - dryRun: 実削除を行ったかどうか (true = 候補列挙のみ)
 *   - candidateIds: 削除対象とみなされた Draft ID 一覧
 *   - deletedIds: 実際に削除された ID 一覧 (dryRun=true なら常に空)
 *
 * candidateIds と deletedIds が一致しないケース: apply 実行中に
 * deleteById が false (= 既に消えていた等) を返した場合。
 */
final readonly class CleanupAutoCrawlDraftsResult
{
    /**
     * @param  list<string>  $candidateIds
     * @param  list<string>  $deletedIds
     */
    public function __construct(
        public bool $dryRun,
        public array $candidateIds,
        public array $deletedIds,
    ) {}
}

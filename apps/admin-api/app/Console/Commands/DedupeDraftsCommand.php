<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Conferences\DedupeDraftsUseCase;
use Illuminate\Console\Command;

/**
 * 同 URL の Draft Conference 重複を一括削除する artisan コマンド (Issue #169 Phase 2)。
 *
 * 動作:
 * - 全 Draft を取得し、OfficialUrl::normalize でグルーピング
 * - 同 URL に複数 Draft があれば最新 createdAt を残し、それ以外を削除
 * - 各グループで「残した ID」「削除した ID」を構造化ログに残す
 *
 * オプション:
 *   --dry-run    deleteById を呼ばず、削除予定 ID のみ表示
 *
 * 想定運用:
 * - Phase 1 (PR #170) で AutoCrawl 側の重複防止は完了しているため、
 *   本コマンドは「既存累積の片付け」目的での手動実行を想定。
 * - 必要なら Lambda invoke 経由で本番に対しても実行可能。
 *
 * 終了コード:
 * - SUCCESS (0): 正常完了 (= 重複 0 件でも 0)
 */
class DedupeDraftsCommand extends Command
{
    /** @var string */
    protected $signature = 'conferences:dedupe-drafts
                            {--dry-run : deleteById を呼ばず削除予定のみ表示}';

    /** @var string */
    protected $description = '同 URL の Draft Conference 重複を一括削除する (Issue #169 Phase 2)';

    public function handle(DedupeDraftsUseCase $useCase): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $modeLabel = $dryRun ? ' (dry-run)' : '';

        $this->info("dedupe-drafts 開始{$modeLabel}: 同 URL の Draft 重複を整理します...");

        $result = $useCase->execute($dryRun);

        $this->info('---');
        $this->info("Draft 総数: {$result->totalDrafts}");
        $this->info("重複グループ: {$result->duplicateGroups}");
        $this->info("削除件数: {$result->deletedCount}{$modeLabel}");

        if ($result->deletedCount > 0) {
            $this->info('削除した (or 予定の) ID:');
            foreach ($result->deletedIds as $id) {
                $this->line("  - {$id}");
            }
        }

        return self::SUCCESS;
    }
}

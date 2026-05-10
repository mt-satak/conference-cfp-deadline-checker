<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Conferences\CleanupAutoCrawlDrafts\CleanupAutoCrawlDraftsUseCase;
use Illuminate\Console\Command;

/**
 * AutoCrawl 起源 Draft の一括削除 artisan コマンド (Issue #188 PR-2)。
 *
 * 動作:
 * - デフォルトは dry-run。削除候補 ID を表示するだけで実削除しない。
 * - --apply 指定で実削除を行う (= 一発勝負の安全装置として常に dry-run を要求)。
 *
 * 想定運用:
 * - PR-1 リリース後、本番で 1 回手動実行する移行コマンド。
 *     php artisan conferences:cleanup-autocrawl-drafts            # 候補列挙
 *     php artisan conferences:cleanup-autocrawl-drafts --apply    # 実削除
 * - idempotent: 削除完了後に再実行しても候補 0 件で no-op。
 *
 * 終了コード:
 * - SUCCESS (0): 全件処理完了 (= 候補 0 件でも成功扱い)
 * - FAILURE (1): UseCase で予期せぬ例外発生時のみ
 */
class CleanupAutoCrawlDraftsCommand extends Command
{
    /** @var string */
    protected $signature = 'conferences:cleanup-autocrawl-drafts {--apply : 実削除を行う (省略時は dry-run)}';

    /** @var string */
    protected $description = 'AutoCrawl 起源 Draft (Published と officialUrl 重複) を一括削除する';

    public function handle(CleanupAutoCrawlDraftsUseCase $useCase): int
    {
        $apply = (bool) $this->option('apply');
        $dryRun = ! $apply;

        $this->info($dryRun
            ? 'Cleanup (dry-run): 削除候補を列挙します...'
            : 'Cleanup (apply): AutoCrawl 起源 Draft を削除します...');

        $result = $useCase->execute(dryRun: $dryRun);

        $this->info('---');
        $this->info('削除候補件数: '.count($result->candidateIds));
        if ($result->candidateIds !== []) {
            $this->info('候補 ID:');
            foreach ($result->candidateIds as $id) {
                $this->line("  - {$id}");
            }
        }

        if ($dryRun) {
            $this->warn('dry-run のため実削除は行いませんでした。--apply で実行してください。');

            return self::SUCCESS;
        }

        $this->info('削除済件数: '.count($result->deletedIds));
        $skipped = array_values(array_diff($result->candidateIds, $result->deletedIds));
        if ($skipped !== []) {
            $this->warn('削除に失敗した候補 (= deleteById が false を返した):');
            foreach ($skipped as $id) {
                $this->line("  - {$id}");
            }
        }

        return self::SUCCESS;
    }
}

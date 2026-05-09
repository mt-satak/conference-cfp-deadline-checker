<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Conferences\ArchivePastConferencesUseCase;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * 開催日を過ぎた Published を Archived に遷移させる artisan コマンド (Issue #165 Phase 2)。
 *
 * 動作:
 * - 全 Conference を取得
 * - status=Published かつ Conference::isPastEvent($today) を満たす対象を抽出
 * - 各対象の status を Archived に遷移、updatedAt を「今」に更新
 * - 対象 ID 一覧と件数を出力
 *
 * オプション:
 *   --dry-run    save() を呼ばず、対象一覧のみ表示
 *   --today=YYYY-MM-DD  比較基準日を上書き (= テスト / 過去検証用、未指定は Asia/Tokyo の today)
 *
 * 想定運用:
 * - 日次で EventBridge cron が起動 (= Phase 3 で CDK 化、毎朝 JST 6:00)
 * - ローカルでは手動実行で動作確認:
 *     php artisan conferences:archive-past --dry-run
 *     php artisan conferences:archive-past
 *
 * 終了コード:
 * - SUCCESS (0): 正常完了 (= 対象 0 件でも 0)
 * - FAILURE (1): UseCase で予期せぬ例外発生時のみ (Laravel デフォルト)
 */
class ArchivePastConferencesCommand extends Command
{
    /** @var string */
    protected $signature = 'conferences:archive-past
                            {--dry-run : save() を呼ばず対象一覧のみ表示}
                            {--today= : 比較基準日 (YYYY-MM-DD)、未指定は Asia/Tokyo の今日}';

    /** @var string */
    protected $description = '開催日を過ぎた Published を Archived に遷移させる (Issue #165 Phase 2)';

    public function handle(ArchivePastConferencesUseCase $useCase): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $todayOption = $this->option('today');
        $today = is_string($todayOption) && $todayOption !== ''
            ? $todayOption
            : Carbon::now('Asia/Tokyo')->toDateString();

        $modeLabel = $dryRun ? ' (dry-run)' : '';
        $this->info("archive-past 開始{$modeLabel}: 開催日を過ぎた Published を Archived に遷移します (today={$today})...");

        $result = $useCase->execute(today: $today, dryRun: $dryRun);

        $this->info('---');
        $this->info("チェック件数: {$result->totalChecked}");
        $this->info("アーカイブ件数: {$result->archivedCount}{$modeLabel}");

        if ($result->archivedCount > 0) {
            $this->info('対象 ID:');
            foreach ($result->archivedIds as $id) {
                $this->line("  - {$id}");
            }
        }

        return self::SUCCESS;
    }
}

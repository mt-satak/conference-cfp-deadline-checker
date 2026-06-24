<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Conferences\DeletePast\DeletePastConferencesUseCase;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * 開催日を過ぎた Conference を全ステータス対象で削除する artisan コマンド (Issue #221 PR-1)。
 *
 * 動作:
 * - 全 Conference を取得し、Conference::isPastEvent($today) を満たす対象を抽出 (ステータス不問)
 * - 対象があればハード削除、無ければスキップ (= 翌週再チェック)
 * - 対象 ID 一覧と件数を出力
 *
 * オプション:
 *   --apply              実削除を行う (省略時は dry-run = 対象一覧のみ表示)
 *   --today=YYYY-MM-DD   比較基準日を上書き (= テスト / 過去検証用、未指定は Asia/Tokyo の today)
 *
 * ハード削除 (= 復元不可) のため、Discover / Cleanup と同じく **デフォルト dry-run** とし、
 * 誤った手動実行で消えないようにする。週次 EventBridge schedule からは --apply 付きで起動する。
 *
 * 想定運用:
 * - 週次で EventBridge cron が起動 (= 月曜 JST 08:00、CDK 化)
 * - ローカル / 手動確認:
 *     php artisan conferences:delete-past            # dry-run
 *     php artisan conferences:delete-past --apply    # 実削除
 *
 * 終了コード:
 * - SUCCESS (0): 正常完了 (= 対象 0 件でも 0)
 * - FAILURE (1): UseCase で予期せぬ例外発生時のみ (Laravel デフォルト)
 */
class DeletePastConferencesCommand extends Command
{
    /** @var string */
    protected $signature = 'conferences:delete-past
                            {--apply : 実削除を行う (省略時は dry-run)}
                            {--today= : 比較基準日 (YYYY-MM-DD)、未指定は Asia/Tokyo の今日}';

    /** @var string */
    protected $description = '開催日を過ぎた Conference を全ステータス対象で削除する (Issue #221)';

    public function handle(DeletePastConferencesUseCase $useCase): int
    {
        $apply = (bool) $this->option('apply');
        $dryRun = ! $apply;
        $todayOption = $this->option('today');
        $today = is_string($todayOption) && $todayOption !== ''
            ? $todayOption
            : Carbon::now('Asia/Tokyo')->toDateString();

        $modeLabel = $dryRun ? ' (dry-run)' : '';
        $this->info("delete-past 開始{$modeLabel}: 開催日を過ぎた Conference を削除します (today={$today})...");

        $result = $useCase->execute(today: $today, dryRun: $dryRun);

        $this->info('---');
        $this->info("チェック件数: {$result->totalChecked}");
        $this->info("削除件数: {$result->deletedCount}{$modeLabel}");

        if ($result->deletedCount > 0) {
            $this->info('対象 ID:');
            foreach ($result->deletedIds as $id) {
                $this->line("  - {$id}");
            }
            if ($dryRun) {
                $this->info('dry-run のため削除は行いませんでした。--apply で実行してください。');
            }
        } else {
            $this->info('削除対象はありませんでした。スキップします。');
        }

        return self::SUCCESS;
    }
}

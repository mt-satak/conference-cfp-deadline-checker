<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Conferences\Discovery\DiscoverConferencesUseCase;
use Illuminate\Console\Command;

/**
 * 週次自動 CfP 発見 artisan コマンド (Issue #200 PR-3)。
 *
 * 動作:
 * - デフォルトは dry-run。各 source の URL 列挙を行い、新規候補件数だけ表示する
 * - --apply 指定で実際に Draft Conference を投入する
 *
 * 想定運用:
 * - 週次 EventBridge cron が起動 (= CDK で --apply 付きで実行する想定)
 * - 手動確認は `php artisan conferences:discover-new` で dry-run 実行
 *
 * 終了コード:
 * - SUCCESS (0): 全 source 巡回完了 (= 部分失敗があっても部分成功扱い)
 * - FAILURE (1): UseCase で予期せぬ例外発生時のみ
 */
class DiscoverConferencesCommand extends Command
{
    /** @var string */
    protected $signature = 'conferences:discover-new {--apply : 実際に Draft Conference を投入する (省略時は dry-run)}';

    /** @var string */
    protected $description = '週次自動 CfP 発見 (CfpSource 巡回 → LLM URL 列挙 → 新規 Draft 投入)';

    public function handle(DiscoverConferencesUseCase $useCase): int
    {
        $apply = (bool) $this->option('apply');
        $dryRun = ! $apply;

        $this->info($dryRun
            ? 'Discover (dry-run): 各 CfpSource を巡回して新規候補 URL を列挙します...'
            : 'Discover (apply): 各 CfpSource を巡回して新規 Draft Conference を投入します...');
        $startedAt = microtime(true);

        $result = $useCase->execute(dryRun: $dryRun);

        $elapsed = round(microtime(true) - $startedAt, 1);
        $this->info('---');
        $this->info("巡回 source 数: {$result->totalSources}");
        $this->info("source 失敗: {$result->sourcesFailed}");
        $this->info("候補 URL 総数: {$result->totalCandidateUrls}");
        $this->info("新規候補 URL: {$result->newCandidateUrls}");

        if (! $dryRun) {
            $this->info("Draft 作成数: {$result->draftsCreated}");
            $this->info("詳細抽出失敗: {$result->extractionFailed}");
            // Issue #224: 欠損補完のため公式リンクを追加抽出した回数 (= 追加 LLM 呼び出し数)
            $this->info("公式リンク追加抽出: {$result->officialFollowCount}");
        }
        $this->info("経過時間: {$elapsed} 秒");

        if ($result->failedSourceUrls !== []) {
            $this->warn('失敗 source URL:');
            foreach ($result->failedSourceUrls as $url) {
                $this->line("  - {$url}");
            }
        }

        if ($dryRun && $result->newCandidateUrls > 0) {
            // dry-run 時のガイダンス。$this->info で渡す (= 視認性は文言で確保)。
            $this->info('pass --apply to insert drafts.');
        }

        return self::SUCCESS;
    }
}

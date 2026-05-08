<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Conferences\AutoCrawl\AutoCrawlConferencesUseCase;
use Illuminate\Console\Command;

/**
 * 既知 URL の自動巡回 artisan コマンド (Issue #152 Phase 1a, 観測のみ)。
 *
 * 動作:
 * - ConferenceRepository::findAll() で取得した Published 全件を巡回
 * - 各 conference の officialUrl を ExtractConferenceDraftUseCase で再抽出
 * - 既存値との差分を検知 → Log::info に出力
 * - DB 書き込みなし (= 観測フェーズ、Draft 作成は Phase 1b で実装)
 *
 * 想定運用:
 * - 週 1 で EventBridge cron が起動 (= PR 3 で CDK 化)
 * - ローカルでは手動実行で動作確認:
 *     php artisan conferences:auto-crawl
 *
 * 終了コード:
 * - SUCCESS (0): 全件巡回完了 (= 抽出失敗があっても部分成功扱い)
 * - FAILURE (1): UseCase で予期せぬ例外発生時のみ
 */
class AutoCrawlConferencesCommand extends Command
{
    /** @var string */
    protected $signature = 'conferences:auto-crawl';

    /** @var string */
    protected $description = '既知 URL の自動巡回 (LLM 再抽出 + 差分検知ログ出力、DB 副作用なし)';

    public function handle(AutoCrawlConferencesUseCase $useCase): int
    {
        $this->info('Auto-crawl 開始: 既存 Published Conference 全件を再抽出します...');
        $startedAt = microtime(true);

        $result = $useCase->execute();

        $elapsed = round(microtime(true) - $startedAt, 1);
        $this->info('---');
        $this->info("巡回件数: {$result->totalChecked}");
        $this->info("差分検知: {$result->diffDetected}");
        $this->info("抽出失敗: {$result->extractionFailed}");
        $this->info("経過時間: {$elapsed} 秒");

        if ($result->extractionFailed > 0) {
            $this->warn('抽出失敗 URL:');
            foreach ($result->failedUrls as $url) {
                $this->line("  - {$url}");
            }
        }

        return self::SUCCESS;
    }
}

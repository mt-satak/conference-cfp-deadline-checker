<?php

declare(strict_types=1);

namespace App\Application\Conferences\AutoCrawl;

/**
 * 自動巡回 (Issue #152 Phase 1) の結果サマリ。
 *
 * Phase 1a (= 観測のみ) では:
 *   - totalChecked: 巡回した Published Conference の件数
 *   - diffDetected: 既存値と LLM 抽出値で差分が検知された件数
 *   - extractionFailed: HtmlFetchFailedException / LlmExtractionFailedException 等で
 *     抽出できなかった件数
 *   - failedUrls: 失敗した officialUrl のリスト (= ログ出力 + 観測用)
 *
 * Phase 1b で Draft 作成を導入する際、ここに createdDraftIds 等を追加する想定。
 */
final readonly class AutoCrawlResult
{
    /**
     * @param  string[]  $failedUrls
     */
    public function __construct(
        public int $totalChecked,
        public int $diffDetected,
        public int $extractionFailed,
        public array $failedUrls,
    ) {}
}

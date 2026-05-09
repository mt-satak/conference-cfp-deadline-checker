<?php

declare(strict_types=1);

namespace App\Application\Conferences\AutoCrawl;

/**
 * 自動巡回 (Issue #152 Phase 1) の結果サマリ。
 *
 * Phase 1a (観測のみ) で導入したフィールド:
 *   - totalChecked: 巡回した Published Conference の件数
 *   - diffDetected: 既存値と LLM 抽出値で差分が検知された件数
 *   - extractionFailed: HtmlFetchFailedException / LlmExtractionFailedException 等で
 *     抽出できなかった件数
 *   - failedUrls: 失敗した officialUrl のリスト (= ログ出力 + 観測用)
 *
 * Phase 1b で追加:
 *   - createdDraftIds: 差分検知 → Draft Conference として保存した新規 ID 一覧
 *     (= admin が「下書き」タブで確認 → 公開化判断する材料)
 */
final readonly class AutoCrawlResult
{
    /**
     * @param  string[]  $failedUrls
     * @param  string[]  $createdDraftIds  Phase 1b で自動巡回が新規作成した Draft Conference の ID 一覧
     */
    public function __construct(
        public int $totalChecked,
        public int $diffDetected,
        public int $extractionFailed,
        public array $failedUrls,
        public array $createdDraftIds = [],
    ) {}
}

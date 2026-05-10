<?php

declare(strict_types=1);

namespace App\Application\Conferences\AutoCrawl;

/**
 * 自動巡回 (Issue #152 Phase 1) の結果サマリ。
 *
 * Issue #152 Phase 1a (観測のみ) で導入したフィールド:
 *   - totalChecked: 巡回対象とした Published Conference の件数 (skip された分も含む)
 *   - diffDetected: 既存値と LLM 抽出値で差分が検知された件数
 *   - extractionFailed: HtmlFetchFailedException / LlmExtractionFailedException 等で
 *     抽出できなかった件数
 *   - failedUrls: 失敗した officialUrl のリスト (= ログ出力 + 観測用)
 *
 * Issue #188 で変更:
 *   - createdDraftIds → pendingChangesUpdatedIds: 差分検知時に Draft 別行を作るのではなく、
 *     Published 行内の pendingChanges フィールドを更新する設計に変更したため、
 *     更新された Conference (= Published) の ID 一覧を保持する。
 *   - skippedHasPending を追加: pending あり (= レビュー中) で再抽出をスキップした件数。
 *     人間ゲート保証のために skip-if-pending を導入した運用観測用。
 */
final readonly class AutoCrawlResult
{
    /**
     * @param  string[]  $failedUrls
     * @param  string[]  $pendingChangesUpdatedIds  Issue #188: pendingChanges を更新した Conference ID 一覧
     */
    public function __construct(
        public int $totalChecked,
        public int $diffDetected,
        public int $extractionFailed,
        public array $failedUrls,
        public array $pendingChangesUpdatedIds = [],
        public int $skippedHasPending = 0,
    ) {}
}

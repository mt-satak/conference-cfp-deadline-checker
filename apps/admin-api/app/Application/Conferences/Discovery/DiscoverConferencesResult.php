<?php

declare(strict_types=1);

namespace App\Application\Conferences\Discovery;

/**
 * 週次自動 CfP 発見 (Issue #200 PR-3) の結果サマリ。
 *
 * 観測対象:
 *   - totalSources: enabled なソース数 (= 巡回した CfpSource 件数)
 *   - sourcesFailed: HTML 取得 or LLM URL 列挙で失敗したソース数
 *   - totalCandidateUrls: LLM が抽出した URL 総数 (重複排除前)
 *   - newCandidateUrls: 既存 officialUrl と重複しない (= 新規) URL 数
 *   - draftsCreated: 詳細抽出 + Repository::save 完了した Draft 数 (実行モード時のみ > 0)
 *   - extractionFailed: 新規 URL の詳細抽出 (ExtractConferenceDraftUseCase) で失敗した件数
 *   - failedSourceUrls: 失敗ソース URL の一覧 (= ログ + 観測用)
 *   - createdDraftIds: 作成された Draft Conference の ID 一覧
 *   - dryRun: true なら save なし (候補列挙のみ)
 */
final readonly class DiscoverConferencesResult
{
    /**
     * @param  string[]  $failedSourceUrls
     * @param  string[]  $createdDraftIds
     */
    public function __construct(
        public bool $dryRun,
        public int $totalSources,
        public int $sourcesFailed,
        public int $totalCandidateUrls,
        public int $newCandidateUrls,
        public int $draftsCreated,
        public int $extractionFailed,
        public array $failedSourceUrls,
        public array $createdDraftIds,
    ) {}
}

<?php

namespace App\Infrastructure\LlmExtraction;

use App\Application\Conferences\Extraction\ConferenceDraft;
use App\Application\Conferences\Extraction\ConferenceDraftExtractor;
use App\Domain\Conferences\ConferenceFormat;

/**
 * ConferenceDraftExtractor の Mock 実装 (Issue #40 Phase 3 PR-2)。
 *
 * 用途:
 * - ローカル開発: AWS / Bedrock を経由せず admin UI のフォーム prefill 動作を確認できる
 * - 自動テスト: 決定論的な ConferenceDraft を返すので Feature テストに使える
 *
 * 本クラスは LLM を呼ばないため、API キー / IAM ロール / ネットワーク不要で動作する。
 * ServiceProvider が LLM_PROVIDER=mock の時にこの実装を bind する想定 (PR-2 後半)。
 */
class MockConferenceDraftExtractor implements ConferenceDraftExtractor
{
    public function extract(string $sourceUrl, string $html): ConferenceDraft
    {
        return new ConferenceDraft(
            sourceUrl: $sourceUrl,
            name: 'Mock カンファレンス',
            trackName: null,
            officialUrl: $sourceUrl,
            cfpUrl: $sourceUrl.'/cfp',
            eventStartDate: '2026-09-01',
            eventEndDate: '2026-09-02',
            venue: 'Mock 会場 (東京)',
            format: ConferenceFormat::Hybrid,
            cfpStartDate: '2026-05-01',
            cfpEndDate: '2026-07-31',
            categorySlugs: ['php', 'frontend'],
            description: 'これはローカル開発用の Mock LLM 抽出結果です。本番 Bedrock 実装に切り替えると実 HTML から動的に抽出されます。',
            themeColor: '#777BB4',
        );
    }
}

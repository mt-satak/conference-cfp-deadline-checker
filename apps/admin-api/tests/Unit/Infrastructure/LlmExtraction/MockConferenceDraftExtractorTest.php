<?php

use App\Application\Conferences\Extraction\ConferenceDraft;
use App\Domain\Conferences\ConferenceFormat;
use App\Infrastructure\LlmExtraction\MockConferenceDraftExtractor;

/**
 * MockConferenceDraftExtractor のユニットテスト (Issue #40 Phase 3 PR-2)。
 *
 * Mock 実装はローカル開発・テストでの ConferenceDraftExtractor の代用品。
 * - 入力 URL / HTML を解析せず、固定の ConferenceDraft を返す
 * - sourceUrl だけは入力値をそのまま返す (= 観測ログで「どの URL の Mock 結果か」分かる)
 *
 * 本番 Bedrock 実装は別クラス (BedrockConferenceDraftExtractor) で、
 * ServiceProvider が LLM_PROVIDER 環境変数で bind 切替する設計。
 */
it('extract は sourceUrl と固定の ConferenceDraft を返す', function () {
    // Given: Mock 実装インスタンス
    $extractor = new MockConferenceDraftExtractor;

    // When: 任意の URL と HTML で extract する
    $draft = $extractor->extract('https://example.com/2026', '<html>無視される入力</html>');

    // Then: ConferenceDraft 型で sourceUrl が反映されている
    expect($draft)->toBeInstanceOf(ConferenceDraft::class);
    expect($draft->sourceUrl)->toBe('https://example.com/2026');
    expect($draft->name)->toBe('Mock カンファレンス');
    expect($draft->format)->toBe(ConferenceFormat::Hybrid);
    expect($draft->categorySlugs)->toBe(['php', 'frontend']);
});

it('複数回呼んでも同じ固定値を返す (= 決定論的)', function () {
    // Given
    $extractor = new MockConferenceDraftExtractor;

    // When: 異なる入力で 2 回呼ぶ
    $a = $extractor->extract('https://a.example.com', '<a>');
    $b = $extractor->extract('https://b.example.com', '<b>');

    // Then: sourceUrl 以外は同じ
    expect($a->name)->toBe($b->name);
    expect($a->venue)->toBe($b->venue);
    expect($a->cfpEndDate)->toBe($b->cfpEndDate);

    // sourceUrl は入力値で異なる
    expect($a->sourceUrl)->toBe('https://a.example.com');
    expect($b->sourceUrl)->toBe('https://b.example.com');
});

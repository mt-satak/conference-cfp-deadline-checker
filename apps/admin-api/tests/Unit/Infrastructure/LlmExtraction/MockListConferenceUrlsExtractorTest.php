<?php

declare(strict_types=1);

use App\Infrastructure\LlmExtraction\MockListConferenceUrlsExtractor;

/**
 * MockListConferenceUrlsExtractor のユニットテスト (Issue #200 PR-3)。
 *
 * 主目的はカバレッジ補完。Mock 実装は固定 URL を返すだけ。
 */
it('Mock は固定の 2 URL を返す', function () {
    // Given/When
    $urls = (new MockListConferenceUrlsExtractor)->extract('https://anywhere.example.com/', '<html></html>');

    // Then
    expect($urls)->toBe([
        'https://mock-conf-1.example.com/',
        'https://mock-conf-2.example.com/',
    ]);
});

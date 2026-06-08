<?php

declare(strict_types=1);

namespace App\Infrastructure\LlmExtraction;

use App\Application\Conferences\Discovery\ListConferenceUrlsExtractor;

/**
 * ListConferenceUrlsExtractor の Mock 実装 (Issue #200 PR-3)。
 *
 * 用途:
 * - ローカル開発: AWS / Bedrock 不要で discover コマンドの動作確認ができる
 * - 自動テスト: 決定論的な URL リストを返す
 *
 * ServiceProvider が LLM_PROVIDER=mock の時にこの実装を bind する。
 */
class MockListConferenceUrlsExtractor implements ListConferenceUrlsExtractor
{
    public function extract(string $sourceUrl, string $html): array
    {
        return [
            'https://mock-conf-1.example.com/',
            'https://mock-conf-2.example.com/',
        ];
    }
}

<?php

namespace App\Application\Conferences\Extraction;

use RuntimeException;

/**
 * LLM 抽出処理失敗を表す例外 (Issue #40 Phase 3)。
 *
 * 主に Bedrock 呼び出し失敗 / JSON Schema 不整合 / クォータ超過を想定。
 * HTTP 層で 502 / 503 相当に整形して、ユーザに「時間を置いて再試行 / 手動入力に
 * フォールバック」を促す。
 */
class LlmExtractionFailedException extends RuntimeException
{
    public static function modelError(string $url, string $reason): self
    {
        return new self("LLM extraction failed for {$url}: {$reason}");
    }

    public static function invalidResponse(string $url, string $reason): self
    {
        return new self("LLM response for {$url} did not match schema: {$reason}");
    }

    public static function quotaExceeded(string $url): self
    {
        return new self("LLM quota exceeded while processing {$url}");
    }
}

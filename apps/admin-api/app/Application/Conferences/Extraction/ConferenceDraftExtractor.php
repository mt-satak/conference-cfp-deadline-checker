<?php

namespace App\Application\Conferences\Extraction;

/**
 * HTML から ConferenceDraft を抽出するポート (Issue #40 Phase 3)。
 *
 * 本番実装は AWS Bedrock 上の Claude Sonnet 4.6 + Tool Use で PR-2 にて実装。
 * ローカル開発・テストは Mock 実装 (固定レスポンス) を Bind して使う。
 *
 * 実装が考慮すべき点 (= contract):
 * - sourceUrl は抽出結果の officialUrl と別 (LLM 出力の説明に使う + 観測ログ)
 * - HTML はサニタイズ済み前提 (script/style 除去) で来ると仮定して良い
 * - 抽出失敗 (LLM エラー / JSON Schema 不整合 / クォータ超過) は LlmExtractionFailedException
 * - ハルシネーション対策: 必ず人間レビューが入る前提で安全側に倒す (推測 < 不明 = null)
 *   不明値は null で返し、確実な値のみ埋める
 */
interface ConferenceDraftExtractor
{
    /**
     * @throws LlmExtractionFailedException
     */
    public function extract(string $sourceUrl, string $html): ConferenceDraft;
}

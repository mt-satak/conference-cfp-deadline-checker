<?php

namespace App\Application\Conferences\Extraction;

/**
 * 公式サイト URL から ConferenceDraft を抽出する UseCase (Issue #40 Phase 3 PR-1)。
 *
 * 責務:
 * - HtmlFetcher で URL から HTML を取得
 * - ConferenceDraftExtractor で HTML → ConferenceDraft に変換
 * - 例外は bubble up (HTTP 層が 502 等にマップして UI で握る)
 *
 * 設計判断:
 * - LLM は判断者ではなく「下書き作成 bot」。出力 ConferenceDraft は status を
 *   持たない (= 必ず人間レビューを経て、admin UI 側で Conference Entity を作る)
 * - HtmlFetcher / ConferenceDraftExtractor の双方を interface 経由で受け取る
 *   ことでローカル mock / 本番 Bedrock を DI コンテナで切り替え可能にしている
 */
class ExtractConferenceDraftUseCase
{
    public function __construct(
        private readonly HtmlFetcher $htmlFetcher,
        private readonly ConferenceDraftExtractor $extractor,
    ) {}

    /**
     * @throws HtmlFetchFailedException
     * @throws LlmExtractionFailedException
     */
    public function execute(string $url): ConferenceDraft
    {
        $html = $this->htmlFetcher->fetch($url);

        return $this->extractor->extract($url, $html);
    }
}

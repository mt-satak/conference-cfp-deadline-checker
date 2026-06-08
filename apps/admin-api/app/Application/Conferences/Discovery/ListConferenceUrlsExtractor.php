<?php

declare(strict_types=1);

namespace App\Application\Conferences\Discovery;

use App\Application\Conferences\Extraction\LlmExtractionFailedException;

/**
 * CfP 集約ページ HTML から「個別カンファレンスページ URL」を抽出するポート (Issue #200 PR-3)。
 *
 * 本番実装は AWS Bedrock 上の Claude Sonnet 4.6 + Tool Use (PR-3 同時実装)。
 * テスト時は Mock 実装 (固定レスポンス) を bind して使う。
 *
 * 役割:
 * - DiscoverConferencesUseCase が CfpSource (= fortee.jp/events 等) の HTML を渡し、
 *   そこに列挙されている個別カンファレンスページの URL リストを返してもらう
 * - 各 URL は ExtractConferenceDraftUseCase に渡されて詳細抽出される
 *
 * 実装が考慮すべき contract:
 * - sourceUrl は抽出元の URL (= ログ + 相対 URL の絶対化基準)
 * - HTML はサニタイズ済み前提で来ると仮定して良い
 * - 抽出失敗 / クォータ超過は LlmExtractionFailedException
 * - 抽出 URL は HTTPS 絶対 URL のみ (LLM 出力で相対 URL が来ても変換するか除外する)
 * - 重複排除や正規化は呼出側 (UseCase) の責務 (= Extractor は LLM 出力をそのまま返す)
 */
interface ListConferenceUrlsExtractor
{
    /**
     * @return string[] 抽出された個別カンファレンスページの絶対 URL リスト
     *
     * @throws LlmExtractionFailedException
     */
    public function extract(string $sourceUrl, string $html): array;
}

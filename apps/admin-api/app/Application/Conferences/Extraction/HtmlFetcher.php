<?php

namespace App\Application\Conferences\Extraction;

/**
 * 公式サイト URL から HTML を取得するポート (Issue #40 Phase 3)。
 *
 * Application 層から Infrastructure 層 (HTTP クライアント) を呼び出す契約。
 * 本番実装は Laravel HTTP クライアント (Guzzle ベース) で PR-2 にて実装。
 *
 * 実装が考慮すべき点 (= contract):
 * - HTTPS のみ受け付け、HTTP は HtmlFetchFailedException を投げる
 * - 4xx/5xx ステータスは HtmlFetchFailedException::statusError
 * - HTML を超えるサイズ (例: 5MB) は HtmlFetchFailedException::tooLarge で打ち切り
 *   (= プロンプトインジェクション + 無駄なトークン消費の予防)
 * - SPA 等の JS レンダリングが必要なサイトは静的 HTML しか返らない (現 Phase 範囲外)
 */
interface HtmlFetcher
{
    /**
     * @throws HtmlFetchFailedException
     */
    public function fetch(string $url): string;
}

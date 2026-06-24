<?php

namespace App\Domain\Conferences;

/**
 * カンファレンスの公開状態。
 *
 * Phase 0.5 (Issue #41) で Draft / Published を導入。
 *
 * - Draft: 仮登録状態。CfP 期間や開催情報が未確定でも保存可能。LLM 抽出結果や
 *   AutoCrawl による差分検知ドラフトの保持場所。公開フロントエンドには露出しない。
 * - Published: 公開状態。現状の必須項目セット (StoreConferenceRequest 相当) を
 *   すべて満たす。公開フロントエンドに表示される。
 *
 * 状態遷移:
 * - Draft → Published: Admin UI の「公開する」ボタン
 *
 * NOTE: 旧 Archived 状態は Issue #221 で廃止 (= 過去イベントは DeletePastTask が
 * 週次でハード削除する方針に変更)。
 */
enum ConferenceStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

<?php

namespace App\Domain\Conferences;

/**
 * カンファレンスの公開状態。
 *
 * Phase 0.5 (Issue #41) で Draft / Published を導入。
 * Issue #165 で「開催日を過ぎた Published を自動アーカイブ」する機能のために Archived を追加。
 *
 * - Draft: 仮登録状態。CfP 期間や開催情報が未確定でも保存可能。LLM 抽出結果や
 *   AutoCrawl による差分検知ドラフトの保持場所。公開フロントエンドには露出しない。
 * - Published: 公開状態。現状の必須項目セット (StoreConferenceRequest 相当) を
 *   すべて満たす。公開フロントエンドに表示される。
 * - Archived: 過去アーカイブ。日次 Lambda (Issue #165 Phase 3) が
 *   `eventEndDate < today` (or `eventStartDate < today`) を満たす Published を
 *   自動的にこの状態へ遷移させる。Admin UI の default タブからは除外され、
 *   「アーカイブ」タブで閲覧可能。物理削除ではなくソフト削除なので、誤判定があっても
 *   復元可能 (= 必要時に手動 Unarchive UI を別途実装する余地を残す)。
 *
 * 状態遷移:
 * - Draft → Published: Admin UI の「公開する」ボタン
 * - Published → Archived: 日次 Lambda (Issue #165 Phase 3 で実装)
 * - Archived → 任意: 手動 unarchive は Phase 1 ではサポートしない
 */
enum ConferenceStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}

<?php

namespace App\Domain\Conferences;

/**
 * カンファレンスの公開状態。
 *
 * Phase 0.5 (Issue #41) で導入。Conference エンティティの公開可否を表現する。
 *
 * - Draft: 仮登録状態。CfP 期間や開催情報が未確定でも保存可能 (PR-2 で
 *   バリデーションが分岐)。Phase 1 の seed や Phase 3 の LLM 抽出結果を
 *   保持する場として使う。公開フロントエンドには露出しない (将来 Astro 側
 *   で除外フィルタを実装)。
 * - Published: 公開状態。現状の必須項目セット (StoreConferenceRequest 相当)
 *   をすべて満たす。
 *
 * 状態遷移は Draft → Published を Admin UI から行う想定 (PR-3)。
 * Published → Draft 取り下げや archived は当面サポートしない (Issue #41 Out of Scope)。
 */
enum ConferenceStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

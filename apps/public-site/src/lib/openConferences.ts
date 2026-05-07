import type { Conference } from '../types/conference';
import { deadlineLabel } from './deadlineLabel';

/**
 * 「あと N 日」がアクション可能な (open / urgent) カンファレンスだけを抽出する
 * 共通フィルタ (Issue #86 / Phase 3)。
 *
 * トップページとカテゴリ別ページの両方で使う。一覧で closed / unknown を出さない
 * 方針 (= ユーザーがアクションを取れないため) を 1 箇所に集約する。
 */
export function filterOpenConferences(
    conferences: readonly Conference[],
    today: Date,
): Conference[] {
    return conferences.filter((c) => {
        const { status } = deadlineLabel(c.cfpEndDate, today);
        return status === 'open' || status === 'urgent' || status === 'today';
    });
}

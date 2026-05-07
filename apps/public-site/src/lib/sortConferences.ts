import type { Conference } from '../types/conference';

/**
 * カンファレンスを cfpEndDate (CfP 締切) の昇順 (= 締切が近い順) でソートする
 * 純粋関数 (Issue #86 / Phase 3)。
 *
 * - 同日締切の場合は name の昇順 (= ロケール awareness の `localeCompare`) で安定化
 * - cfpEndDate が null のカンファレンスは末尾にまとめる
 *   (= 締切未定でアクション優先度が低いため。実用上は filterOpenConferences で
 *   既に除外されているケースが多いが、defensive な実装)
 * - 元の配列は変更しない
 */
export function sortByDeadlineAsc(conferences: readonly Conference[]): Conference[] {
    return [...conferences].sort((a, b) => {
        if (a.cfpEndDate === null && b.cfpEndDate === null) {
            return a.name.localeCompare(b.name);
        }
        if (a.cfpEndDate === null) return 1;
        if (b.cfpEndDate === null) return -1;
        if (a.cfpEndDate !== b.cfpEndDate) {
            return a.cfpEndDate < b.cfpEndDate ? -1 : 1;
        }
        return a.name.localeCompare(b.name);
    });
}

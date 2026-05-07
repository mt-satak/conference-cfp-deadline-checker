import type { Conference } from '../types/conference';

/**
 * カテゴリ slug 抽出 / フィルタの純粋関数 (Issue #86 / Phase 3)。
 *
 * Astro の SSG で `getStaticPaths` がカテゴリ別ページを生成するためにも使う。
 * Conference のデータ shape (= categories: readonly string[]) に依存。
 */

/**
 * 渡されたカンファレンス全件から unique なカテゴリ slug を昇順で取り出す。
 */
export function extractCategorySlugs(conferences: readonly Conference[]): string[] {
    const set = new Set<string>();
    for (const c of conferences) {
        for (const slug of c.categories) {
            set.add(slug);
        }
    }
    return [...set].sort();
}

/**
 * 指定 slug を categories に含むカンファレンスだけ残す。
 *
 * 元の配列は変更しない。
 */
export function filterByCategory(
    conferences: readonly Conference[],
    slug: string,
): Conference[] {
    return conferences.filter((c) => c.categories.includes(slug));
}

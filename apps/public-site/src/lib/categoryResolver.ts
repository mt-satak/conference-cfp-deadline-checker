import type { Category } from '../types/category';

/**
 * Conference.categories (UUID v4 配列) を Category 一覧を使って slug 配列に解決する
 * 純粋関数 (Issue #95 / Phase 4.4)。
 *
 * - 入力 UUID 配列の順序を維持 (= admin で意図した並びを保つ)
 * - Category 一覧に存在しない UUID は除外 (= 削除済み category への参照を黙殺)
 *
 * @param uuids Conference.categories の UUID 配列
 * @param categories `loadCategories()` 等で取得した Category 一覧
 */
export function resolveCategoryUuidsToSlugs(
    uuids: readonly string[],
    categories: readonly Category[],
): string[] {
    const idToSlug = new Map<string, string>();
    for (const c of categories) {
        idToSlug.set(c.id, c.slug);
    }
    const result: string[] = [];
    for (const uuid of uuids) {
        const slug = idToSlug.get(uuid);
        if (slug !== undefined) {
            result.push(slug);
        }
    }
    return result;
}

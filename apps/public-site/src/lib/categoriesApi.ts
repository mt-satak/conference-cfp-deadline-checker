import type { Category } from '../types/category';

/**
 * admin-api `/api/public/categories` のレスポンス shape (Issue #95 / Phase 4.4)。
 *
 * admin-api の Domain Category をベースにした projection。
 * 公開フロント側で必要なフィールドだけを mapApiToCategory で取り出す。
 */
export interface ApiCategory {
    readonly categoryId: string;
    readonly slug: string;
    readonly name: string;
    readonly displayOrder: number;
    readonly axis?: string;
    readonly createdAt: string;
    readonly updatedAt: string;
}

interface ApiResponse {
    readonly data: readonly ApiCategory[];
    readonly meta: { readonly count: number };
}

/**
 * API レスポンスの 1 要素を公開フロント側の Category 型に変換する純粋関数。
 *
 * 公開側で必要なのは UUID → slug 解決のみなので id / slug / name の 3 つ。
 * displayOrder / axis / createdAt / updatedAt は admin 管理用なので落とす。
 */
export function mapApiToCategory(api: ApiCategory): Category {
    return {
        id: api.categoryId,
        slug: api.slug,
        name: api.name,
    };
}

/**
 * 公開 API から Categories をビルド時に取得する。
 *
 * conferencesApi と同方針で fetch 失敗時はビルドを fail させる。
 *
 * @param baseUrl 例: https://d1fz1i6glcp2yn.cloudfront.net
 */
export async function fetchPublicCategories(baseUrl: string): Promise<Category[]> {
    const url = `${baseUrl.replace(/\/$/, '')}/api/public/categories`;
    const response = await fetch(url);
    if (!response.ok) {
        throw new Error(
            `fetchPublicCategories: HTTP ${response.status} from ${url}`,
        );
    }
    const json = (await response.json()) as ApiResponse;
    return json.data.map(mapApiToCategory);
}

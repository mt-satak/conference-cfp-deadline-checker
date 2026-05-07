import type { Category } from '../types/category';
import type { Conference, ConferenceFormat } from '../types/conference';
import { resolveCategoryUuidsToSlugs } from './categoryResolver';

/**
 * admin-api `/api/public/conferences` のレスポンス shape (Issue #91 / Phase 4.3, #95 / Phase 4.4)。
 *
 * admin-api の Domain Conference をベースにした projection。
 * 公開フロント側で必要なフィールドだけを mapApiToConference で取り出す。
 */
export interface ApiConference {
    readonly conferenceId: string;
    readonly name: string;
    readonly trackName: string | null;
    readonly officialUrl: string;
    readonly cfpUrl: string | null;
    readonly eventStartDate: string | null;
    readonly eventEndDate: string | null;
    readonly venue: string | null;
    readonly format: ConferenceFormat | null;
    readonly cfpStartDate: string | null;
    readonly cfpEndDate: string | null;
    /**
     * admin-api 側は UUID v4 配列。mapApiToConference で Categories 一覧を使い slug 配列に解決する。
     */
    readonly categories: readonly string[];
    readonly description: string | null;
    readonly themeColor: string | null;
    readonly createdAt: string;
    readonly updatedAt: string;
    readonly status: 'draft' | 'published';
}

interface ApiResponse {
    readonly data: readonly ApiConference[];
    readonly meta: { readonly count: number };
}

/**
 * API レスポンスの 1 要素を公開フロント側の Conference 型に変換する純粋関数。
 *
 * categories は Categories 一覧を使って UUID → slug に解決する (Issue #95 / Phase 4.4)。
 * Categories 一覧に存在しない UUID は除外される (categoryResolver の仕様)。
 */
export function mapApiToConference(
    api: ApiConference,
    categories: readonly Category[],
): Conference {
    return {
        id: api.conferenceId,
        name: api.name,
        officialUrl: api.officialUrl,
        eventStartDate: api.eventStartDate,
        eventEndDate: api.eventEndDate,
        venue: api.venue,
        format: api.format,
        cfpEndDate: api.cfpEndDate,
        description: api.description,
        categories: resolveCategoryUuidsToSlugs(api.categories, categories),
    };
}

/**
 * 公開 API から Conferences をビルド時に取得する。
 *
 * - エラーは throw して Astro build を fail させる (= 古いデータでデプロイされるより安全)
 * - 戻り値は status=Published のみ (= admin-api 側で確定済みの projection)
 * - categories は引数で渡された Category 一覧を使って UUID → slug に解決される
 *
 * @param baseUrl    例: https://d1fz1i6glcp2yn.cloudfront.net
 * @param categories 解決に使う Category 一覧 (= loadCategories の結果)
 */
export async function fetchPublicConferences(
    baseUrl: string,
    categories: readonly Category[],
): Promise<Conference[]> {
    const url = `${baseUrl.replace(/\/$/, '')}/api/public/conferences`;
    const response = await fetch(url);
    if (!response.ok) {
        throw new Error(
            `fetchPublicConferences: HTTP ${response.status} from ${url}`,
        );
    }
    const json = (await response.json()) as ApiResponse;
    return json.data.map((api) => mapApiToConference(api, categories));
}

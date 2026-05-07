import type { Conference, ConferenceFormat } from '../types/conference';

/**
 * admin-api `/api/public/conferences` のレスポンス shape (Issue #91 / Phase 4.3)。
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
     * admin-api 側は UUID v4 の配列。Phase 4.3 では公開フロント側で表示しないため
     * mapApiToConference で空配列に丸める。Phase 4.4 で UUID → name 解決を実装する。
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
 * categories は Phase 4.3 では空配列で固定 (UUID をそのまま表示できないため)。
 * Phase 4.4 で `/api/public/categories` から取得した name マップで解決予定。
 */
export function mapApiToConference(api: ApiConference): Conference {
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
        categories: [],
    };
}

/**
 * 公開 API から Conferences をビルド時に取得する。
 *
 * - エラーは throw して Astro build を fail させる (= 古いデータでデプロイされるより安全)
 * - 戻り値は status=Published のみ (= admin-api 側で確定済みの projection)
 *
 * @param baseUrl 例: https://d1fz1i6glcp2yn.cloudfront.net
 */
export async function fetchPublicConferences(baseUrl: string): Promise<Conference[]> {
    const url = `${baseUrl.replace(/\/$/, '')}/api/public/conferences`;
    const response = await fetch(url);
    if (!response.ok) {
        throw new Error(
            `fetchPublicConferences: HTTP ${response.status} from ${url}`,
        );
    }
    const json = (await response.json()) as ApiResponse;
    return json.data.map(mapApiToConference);
}

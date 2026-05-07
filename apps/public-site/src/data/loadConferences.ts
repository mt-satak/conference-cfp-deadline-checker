import type { Conference } from '../types/conference';
import { fetchPublicConferences } from '../lib/conferencesApi';
import { mockConferences } from './conferences.mock';
import { loadCategories } from './loadCategories';

/**
 * カンファレンスデータの取得元を一元管理する (Issue #91 / Phase 4.3, #95 / Phase 4.4)。
 *
 * - `PUBLIC_API_BASE_URL` 環境変数が設定されていれば admin-api 公開 API から fetch。
 *   このとき Conference.categories の UUID は Category 一覧を使って slug に解決される
 * - 未設定ならローカル mock データを使う (= ローカル開発で admin-api 起動なしでも動く)。
 *   mock の categories は最初から slug 文字列なので解決不要
 *
 * Astro の SSG ビルド時にトップレベル await で呼ばれる前提。
 * fetch 失敗時はビルドを fail させる (= 古いデータでデプロイされるより安全)。
 */
export async function loadConferences(): Promise<readonly Conference[]> {
    const baseUrl = import.meta.env.PUBLIC_API_BASE_URL;
    if (typeof baseUrl === 'string' && baseUrl !== '') {
        const categories = await loadCategories();
        return await fetchPublicConferences(baseUrl, categories);
    }
    return mockConferences;
}

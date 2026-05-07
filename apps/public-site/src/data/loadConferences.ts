import type { Conference } from '../types/conference';
import { fetchPublicConferences } from '../lib/conferencesApi';
import { mockConferences } from './conferences.mock';

/**
 * カンファレンスデータの取得元を一元管理する (Issue #91 / Phase 4.3)。
 *
 * - `PUBLIC_API_BASE_URL` 環境変数が設定されていれば admin-api 公開 API から fetch
 *   (= 本番 / Amplify build / 任意の環境で本番データを使いたいケース)
 * - 未設定ならローカル mock データを使う (= ローカル開発で admin-api 起動なしでも動く)
 *
 * Astro の SSG ビルド時にトップレベル await で呼ばれる前提。
 * fetch 失敗時はビルドを fail させる (= 古いデータでデプロイされるより安全)。
 */
export async function loadConferences(): Promise<readonly Conference[]> {
    const baseUrl = import.meta.env.PUBLIC_API_BASE_URL;
    if (typeof baseUrl === 'string' && baseUrl !== '') {
        return await fetchPublicConferences(baseUrl);
    }
    return mockConferences;
}

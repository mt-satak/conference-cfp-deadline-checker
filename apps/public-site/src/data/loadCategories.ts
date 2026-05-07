import type { Category } from '../types/category';
import { fetchPublicCategories } from '../lib/categoriesApi';
import { mockCategories } from './categories.mock';

/**
 * Categories の取得元を一元管理する (Issue #95 / Phase 4.4)。
 *
 * loadConferences と同じ env-driven パターン:
 *  - PUBLIC_API_BASE_URL 設定時: admin-api `/api/public/categories` から fetch
 *  - 未設定時: ローカル mock 使用
 *
 * fetch 失敗時はビルドを fail させる (= 古いデータでデプロイより安全)。
 */
export async function loadCategories(): Promise<readonly Category[]> {
    const baseUrl = import.meta.env.PUBLIC_API_BASE_URL;
    if (typeof baseUrl === 'string' && baseUrl !== '') {
        return await fetchPublicCategories(baseUrl);
    }
    return mockCategories;
}

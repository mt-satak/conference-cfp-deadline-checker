import type { Category } from '../types/category';

/**
 * Phase 4.4 用の Category mock データ (Issue #95)。
 *
 * conferences.mock.ts の categories 配列で参照される slug と整合させる。
 * id (UUID) は仮のものなので mock conferences の categories とは互換性なし
 * (= mock conferences の categories は slug 配列、本物の admin-api では UUID 配列)。
 *
 * 公開フロント側のコードは「Conference.categories: UUID[] → slug[] に変換する」
 * フローを通すため、mock conferences のデータを fetch モードと同じ shape にするには
 * categories を UUID で持たせる必要がある。が、mock の役割はあくまで「ローカル開発で
 * admin-api 起動なしで動かす」ことなので、シンプルさ優先で以下のいずれか:
 *
 *  1. mock conferences の categories を UUID にする (現実装と非互換)
 *  2. mock の loadConferences は categoryResolver を通さない短絡パスを使う
 *
 * 本実装では 2 (= mock データはそのまま slug を categories に持つ、解決ステップ無し)。
 * fetch モードでのみ resolveCategoryUuidsToSlugs を通す。
 */
export const mockCategories: readonly Category[] = [
    { id: 'mock-php', slug: 'php', name: 'PHP' },
    { id: 'mock-web', slug: 'web', name: 'Web' },
    { id: 'mock-go', slug: 'go', name: 'Go' },
    { id: 'mock-backend', slug: 'backend', name: 'Backend' },
    { id: 'mock-python', slug: 'python', name: 'Python' },
    { id: 'mock-data', slug: 'data', name: 'Data' },
    { id: 'mock-javascript', slug: 'javascript', name: 'JavaScript' },
    { id: 'mock-typescript', slug: 'typescript', name: 'TypeScript' },
    { id: 'mock-ruby', slug: 'ruby', name: 'Ruby' },
];

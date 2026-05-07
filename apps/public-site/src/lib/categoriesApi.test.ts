import { describe, it, expect } from 'vitest';
import { mapApiToCategory, type ApiCategory } from './categoriesApi';

/**
 * Categories API レスポンス → 公開フロント Category 型へのマッピングテスト
 * (Issue #95 / Phase 4.4)。
 */

function makeApiCategory(overrides: Partial<ApiCategory> = {}): ApiCategory {
    return {
        categoryId: '1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02',
        slug: 'php',
        name: 'PHP',
        displayOrder: 100,
        createdAt: '2026-04-15T10:30:00+09:00',
        updatedAt: '2026-04-15T10:30:00+09:00',
        ...overrides,
    };
}

describe('mapApiToCategory', () => {
    it('categoryId を id にマッピングし、slug / name を抽出する', () => {
        // Given
        const api = makeApiCategory();

        // When
        const result = mapApiToCategory(api);

        // Then
        expect(result.id).toBe('1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02');
        expect(result.slug).toBe('php');
        expect(result.name).toBe('PHP');
    });

    it('axis / displayOrder などの管理用フィールドは公開側 type に含まれない', () => {
        // Given: axis 付きの API レスポンス
        const api = makeApiCategory({ axis: 'A' });

        // When
        const result = mapApiToCategory(api);

        // Then: 公開側 Category 型 (id / slug / name) のみが返る
        expect(Object.keys(result).sort()).toEqual(['id', 'name', 'slug']);
    });
});

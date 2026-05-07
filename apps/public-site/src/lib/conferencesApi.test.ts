import { describe, it, expect } from 'vitest';
import type { Category } from '../types/category';
import { mapApiToConference, type ApiConference } from './conferencesApi';

/**
 * 公開 API レスポンス → 公開フロント Conference 型へのマッピングテスト
 * (Issue #91 / Phase 4.3, #95 / Phase 4.4)。
 *
 * - admin-api の Conference shape (UUID conferenceId / status / createdAt 等を持つ) から
 *   公開フロント側で使うフィールドだけを抽出する純粋関数
 * - categories は Category 一覧を使って UUID → slug に解決する (Phase 4.4)
 */

const categoryFixtures: readonly Category[] = [
    { id: '1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02', slug: 'php', name: 'PHP' },
    { id: '2e5a3b94-7c59-4a2d-8d9b-8f3c4e5a6b03', slug: 'web', name: 'Web' },
];

function makeApiConference(overrides: Partial<ApiConference> = {}): ApiConference {
    return {
        conferenceId: '550e8400-e29b-41d4-a716-446655440000',
        name: 'Sample Conf',
        trackName: null,
        officialUrl: 'https://example.com',
        cfpUrl: null,
        eventStartDate: '2026-09-19',
        eventEndDate: '2026-09-20',
        venue: '東京',
        format: 'offline',
        cfpStartDate: null,
        cfpEndDate: '2026-07-15',
        categories: ['1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02'],
        description: '説明',
        themeColor: null,
        createdAt: '2026-04-15T10:30:00+09:00',
        updatedAt: '2026-04-15T10:30:00+09:00',
        status: 'published',
        ...overrides,
    };
}

describe('mapApiToConference', () => {
    it('conferenceId を id にマッピングし、必要フィールドだけ抽出する', () => {
        // Given
        const api = makeApiConference();

        // When
        const result = mapApiToConference(api, categoryFixtures);

        // Then
        expect(result.id).toBe('550e8400-e29b-41d4-a716-446655440000');
        expect(result.name).toBe('Sample Conf');
        expect(result.officialUrl).toBe('https://example.com');
        expect(result.eventStartDate).toBe('2026-09-19');
        expect(result.eventEndDate).toBe('2026-09-20');
        expect(result.venue).toBe('東京');
        expect(result.format).toBe('offline');
        expect(result.cfpEndDate).toBe('2026-07-15');
        expect(result.description).toBe('説明');
    });

    it('categories の UUID 配列を Category 一覧を使って slug 配列に解決する', () => {
        // Given: API は 2 つの UUID を返す
        const api = makeApiConference({
            categories: [
                '1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02',
                '2e5a3b94-7c59-4a2d-8d9b-8f3c4e5a6b03',
            ],
        });

        // When
        const result = mapApiToConference(api, categoryFixtures);

        // Then: slug 配列に解決
        expect(result.categories).toEqual(['php', 'web']);
    });

    it('Category 一覧に存在しない UUID は categories から除外される', () => {
        // Given: 存在しない UUID 含む
        const api = makeApiConference({
            categories: [
                '1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02', // php (存在)
                '99999999-9999-9999-9999-999999999999', // 存在しない
            ],
        });

        // When
        const result = mapApiToConference(api, categoryFixtures);

        // Then
        expect(result.categories).toEqual(['php']);
    });

    it('null 値を持つフィールド (eventStartDate / venue / format / cfpEndDate / description) はそのまま null', () => {
        // Given
        const api = makeApiConference({
            eventStartDate: null,
            eventEndDate: null,
            venue: null,
            format: null,
            cfpEndDate: null,
            description: null,
        });

        // When
        const result = mapApiToConference(api, categoryFixtures);

        // Then
        expect(result.eventStartDate).toBeNull();
        expect(result.eventEndDate).toBeNull();
        expect(result.venue).toBeNull();
        expect(result.format).toBeNull();
        expect(result.cfpEndDate).toBeNull();
        expect(result.description).toBeNull();
    });
});

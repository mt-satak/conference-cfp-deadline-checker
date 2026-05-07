import { describe, it, expect } from 'vitest';
import { mapApiToConference, type ApiConference } from './conferencesApi';

/**
 * 公開 API レスポンス → 公開フロント Conference 型へのマッピングテスト
 * (Issue #91 / Phase 4.3)。
 *
 * - admin-api の Conference shape (UUID conferenceId / status / createdAt 等を持つ) から
 *   公開フロント側で使うフィールドだけを抽出する純粋関数
 * - categories は本フェーズでは空配列で固定 (Phase 4.4 で UUID → name 解決を実装予定)
 */

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
        // Given: published Conference 1 件
        const api = makeApiConference();

        // When
        const result = mapApiToConference(api);

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

    it('categories は本フェーズでは空配列で固定する (Phase 4.4 で UUID → name 解決予定)', () => {
        // Given: API 側は UUID 配列を返すが、現状は表示できないため空にする
        const api = makeApiConference({
            categories: [
                '1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02',
                '2e5a3b94-7c59-4a2d-8d9b-8f3c4e5a6b03',
            ],
        });

        // When
        const result = mapApiToConference(api);

        // Then
        expect(result.categories).toEqual([]);
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
        const result = mapApiToConference(api);

        // Then
        expect(result.eventStartDate).toBeNull();
        expect(result.eventEndDate).toBeNull();
        expect(result.venue).toBeNull();
        expect(result.format).toBeNull();
        expect(result.cfpEndDate).toBeNull();
        expect(result.description).toBeNull();
    });
});

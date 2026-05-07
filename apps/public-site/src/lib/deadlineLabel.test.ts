import { describe, it, expect } from 'vitest';
import { deadlineLabel, type DeadlineLabelStatus } from './deadlineLabel';

/**
 * CfP 締切までのラベル生成ロジック (Issue #86 / Phase 2)。
 *
 * 一覧画面で「あと N 日」「本日締切」「締切終了」等のバッジ表示に使う。
 * 残り日数だけでなく status (UI で色分けする際の手がかり) も同時に返す。
 */
describe('deadlineLabel', () => {
    it('CfP 終了日が urgent 閾値より先の場合「あと N 日」と open ステータスを返す', () => {
        // Given: 30 日後が締切 (urgent 閾値 7 日を十分超える)
        const today = new Date('2026-05-07T00:00:00Z');

        // When
        const result = deadlineLabel('2026-06-06', today);

        // Then
        expect(result.text).toBe('あと 30 日');
        expect(result.status).toBe<DeadlineLabelStatus>('open');
    });

    it('CfP 終了日が今日と同じ日の場合「本日締切」と today ステータスを返す (urgent と区別、UI で赤色表示)', () => {
        // Given: 当日
        const today = new Date('2026-05-07T08:00:00Z');

        // When
        const result = deadlineLabel('2026-05-07', today);

        // Then
        expect(result.text).toBe('本日締切');
        expect(result.status).toBe<DeadlineLabelStatus>('today');
    });

    it('CfP 終了日が今日より過去の場合「締切終了」と closed ステータスを返す', () => {
        // Given: 3 日前に終了
        const today = new Date('2026-05-07T00:00:00Z');

        // When
        const result = deadlineLabel('2026-05-04', today);

        // Then
        expect(result.text).toBe('締切終了');
        expect(result.status).toBe<DeadlineLabelStatus>('closed');
    });

    it('CfP 終了日が 7 日以内の場合は urgent ステータス、それ以外は open ステータスを返す', () => {
        // Given/When/Then: ちょうど 7 日後 → urgent
        const today = new Date('2026-05-07T00:00:00Z');
        expect(deadlineLabel('2026-05-14', today).status).toBe<DeadlineLabelStatus>('urgent');

        // 8 日後 → open
        expect(deadlineLabel('2026-05-15', today).status).toBe<DeadlineLabelStatus>('open');
    });

    it('CfP 終了日が null の場合「締切未定」と unknown ステータスを返す', () => {
        // Given: 締切未定 (admin で未入力)
        const today = new Date('2026-05-07T00:00:00Z');

        // When
        const result = deadlineLabel(null, today);

        // Then
        expect(result.text).toBe('締切未定');
        expect(result.status).toBe<DeadlineLabelStatus>('unknown');
    });
});

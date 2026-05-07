import { describe, it, expect } from 'vitest';
import { daysUntilDeadline, formatJstDate } from './dates';

/**
 * CfP 締切までの残り日数計算 (Issue #86 / Phase 1 → Phase 2 で利用)。
 *
 * カンファレンス一覧 / 詳細ページで「あと N 日」表示の元になる純粋関数。
 * Phase 1 では純粋ロジックの最小ユニットとして TDD で書き、Phase 2 のトップページ
 * 実装でそのまま使う。
 *
 * テストは memory `feedback_pest_japanese` / `feedback_test_gwt` に倣い日本語 + GWT 形式。
 */
describe('daysUntilDeadline', () => {
  it('CfP 終了日が今日より後の場合、残り日数を正の整数で返す', () => {
    // Given: 今日が 2026-05-07、CfP 終了日が 2026-05-10
    const today = new Date('2026-05-07T00:00:00Z');
    const deadline = '2026-05-10';

    // When
    const result = daysUntilDeadline(deadline, today);

    // Then: 3 日後
    expect(result).toBe(3);
  });

  it('CfP 終了日が今日と同じ日の場合は 0 を返す', () => {
    // Given: 今日と CfP 終了日が同日
    const today = new Date('2026-05-07T15:30:00Z');
    const deadline = '2026-05-07';

    // When
    const result = daysUntilDeadline(deadline, today);

    // Then: 当日扱いで 0
    expect(result).toBe(0);
  });

  it('CfP 終了日が今日より過去の場合は負の値を返す (= 既に締切超過)', () => {
    // Given: CfP 終了日が 5 日前
    const today = new Date('2026-05-07T00:00:00Z');
    const deadline = '2026-05-02';

    // When
    const result = daysUntilDeadline(deadline, today);

    // Then: -5 (= 締切から 5 日経過)
    expect(result).toBe(-5);
  });

  it('CfP 終了日が ISO 形式 (YYYY-MM-DD) の文字列であれば JST / UTC 境界をまたいでも日付部分だけで判定する', () => {
    // Given: 今日が 2026-05-07 23:59 UTC = JST では 2026-05-08 08:59
    //   ただし「日付」としての判定は UTC 日付で統一 (= 2026-05-07)
    const today = new Date('2026-05-07T23:59:00Z');
    const deadline = '2026-05-08';

    // When
    const result = daysUntilDeadline(deadline, today);

    // Then: UTC 日付ベースで 1 日後
    expect(result).toBe(1);
  });
});

describe('formatJstDate', () => {
  it('UTC 0 時 (= JST 9 時) を JST に変換すると同日付を返す', () => {
    // Given: UTC 2026-05-07 00:00 = JST 09:00
    const date = new Date('2026-05-07T00:00:00Z');

    // When
    const result = formatJstDate(date);

    // Then
    expect(result).toBe('2026-05-07');
  });

  it('UTC で日が変わる前 (15:00 UTC) は JST では翌日になっている', () => {
    // Given: UTC 2026-05-07 15:00 = JST 2026-05-08 00:00
    const date = new Date('2026-05-07T15:00:00Z');

    // When
    const result = formatJstDate(date);

    // Then
    expect(result).toBe('2026-05-08');
  });

  it('YYYY-MM-DD のゼロ埋め形式で返す', () => {
    // Given: JST 1 月 1 日
    const date = new Date('2026-01-01T00:00:00Z'); // JST 09:00 同日

    // When
    const result = formatJstDate(date);

    // Then
    expect(result).toBe('2026-01-01');
  });
});

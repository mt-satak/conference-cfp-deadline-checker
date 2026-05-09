import { describe, it, expect } from 'vitest';
import { daysUntilDeadline, formatJstDate } from './dates';

/**
 * CfP 締切までの残り日数計算 (Issue #86 / Phase 1 → Phase 2 で利用)。
 *
 * Issue #174: 旧版は UTC ベースで日付差を計算していたため JST 0:00-08:59 (=
 * UTC 前日) の間「あと N 日」が 1 日古いままズレる構造的バグがあった。本テストは
 * JST 基準で日付を判定する新しい実装を保証する。
 *
 * deadline 文字列 (YYYY-MM-DD) は JST のカレンダー日付として扱う前提。
 * today は Date オブジェクト (絶対時刻) を渡し、内部で JST のカレンダー日付に変換する。
 *
 * テストは memory `feedback_pest_japanese` / `feedback_test_gwt` に倣い日本語 + GWT 形式。
 */
describe('daysUntilDeadline (JST 基準、Issue #174)', () => {
  it('CfP 終了日が今日 (JST) より後の場合、残り日数を正の整数で返す', () => {
    // Given: today = JST 2026-05-07 12:00 (= UTC 03:00)
    const today = new Date('2026-05-07T03:00:00Z');
    const deadline = '2026-05-10';

    // When / Then: JST で 3 日後
    expect(daysUntilDeadline(deadline, today)).toBe(3);
  });

  it('CfP 終了日が今日 (JST) と同じ日の場合は 0 を返す', () => {
    // Given: today = JST 2026-05-07 15:30
    const today = new Date('2026-05-07T06:30:00Z');
    const deadline = '2026-05-07';

    // When / Then
    expect(daysUntilDeadline(deadline, today)).toBe(0);
  });

  it('CfP 終了日が今日 (JST) より過去の場合は負の値を返す', () => {
    // Given: today = JST 2026-05-07
    const today = new Date('2026-05-07T03:00:00Z');
    const deadline = '2026-05-02';

    // When / Then: -5 (= 締切から 5 日経過)
    expect(daysUntilDeadline(deadline, today)).toBe(-5);
  });

  it('JST 0:00 ぴったりに切り替わる: 5/9 23:59 JST と 5/10 00:00 JST で 1 日変わる', () => {
    // Given: deadline = 5/15、today1 = JST 5/9 23:59、today2 = JST 5/10 00:00
    const deadline = '2026-05-15';
    const justBeforeMidnight = new Date('2026-05-09T14:59:00Z'); // JST 5/9 23:59
    const justAfterMidnight = new Date('2026-05-09T15:00:00Z'); // JST 5/10 00:00

    // When / Then
    expect(daysUntilDeadline(deadline, justBeforeMidnight)).toBe(6);
    expect(daysUntilDeadline(deadline, justAfterMidnight)).toBe(5);
  });

  it('JST 0:00-08:59 (= UTC 前日) でも JST 当日基準で残日数を返す (Issue #174 主要回帰防止)', () => {
    // Given: today = JST 2026-05-10 05:00 = UTC 2026-05-09 20:00
    //   旧 UTC ベース実装ではここで getUTCDate() = 9 を返してしまい
    //   "5/10 today, deadline 5/15" の本来 5 日が 6 日と表示される。
    const today = new Date('2026-05-09T20:00:00Z');
    const deadline = '2026-05-15';

    // When / Then: JST 5/10 基準で 5 日
    expect(daysUntilDeadline(deadline, today)).toBe(5);
  });

  it('JST 23:59 (= UTC 14:59 同日) の場合も JST 当日基準で残日数を返す', () => {
    // Given: today = JST 2026-05-10 23:59 = UTC 2026-05-10 14:59
    //   UTC でも JST でも当日 5/10 で一致するためどちらの実装でも結果は同じだが、
    //   JST 早朝のケースとの対称性を担保するため明示的にテスト。
    const today = new Date('2026-05-10T14:59:00Z');
    const deadline = '2026-05-10';

    // When / Then: 当日 = 0
    expect(daysUntilDeadline(deadline, today)).toBe(0);
  });

  it('UTC 日付では翌日になっているが JST ではまだ当日のケース', () => {
    // Given: today = UTC 2026-05-08 00:30 = JST 2026-05-08 09:30
    //   このケースは JST と UTC の日付が一致する (= 09:00 以降の UTC midnight 直後)
    const today = new Date('2026-05-08T00:30:00Z');
    const deadline = '2026-05-08';

    // When / Then: 当日扱い 0
    expect(daysUntilDeadline(deadline, today)).toBe(0);
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

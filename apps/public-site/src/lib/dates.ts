/**
 * 日付ユーティリティ (Issue #86)。
 *
 * カンファレンス CfP 締切までの残り日数計算など、UI 全般で使う純粋関数を集約。
 * 公開フロントは SSG ベースなので、ビルド時 (Astro build) に実行される計算が中心。
 */

/**
 * CfP 締切日 (YYYY-MM-DD) から、基準日 (today) との UTC 日付差を整数日数で返す。
 *
 * - 正の値: あと N 日
 * - 0: 今日 (当日)
 * - 負の値: 締切から N 日経過
 *
 * 時刻部分は無視し UTC の日付単位で計算する。これにより JST / UTC 境界による
 * 「あと 0 日表示が時間で揺れる」現象を避ける。
 *
 * @param deadline CfP 終了日 (ISO 形式の YYYY-MM-DD 想定、`new Date(deadline)` で解釈可能な文字列)
 * @param today 基準時刻 (テスト容易性のため明示的に渡す)
 */
export function daysUntilDeadline(deadline: string, today: Date): number {
  const deadlineDate = new Date(deadline);
  const deadlineUtc = Date.UTC(
    deadlineDate.getUTCFullYear(),
    deadlineDate.getUTCMonth(),
    deadlineDate.getUTCDate(),
  );
  const todayUtc = Date.UTC(
    today.getUTCFullYear(),
    today.getUTCMonth(),
    today.getUTCDate(),
  );
  const msPerDay = 24 * 60 * 60 * 1000;
  return Math.round((deadlineUtc - todayUtc) / msPerDay);
}

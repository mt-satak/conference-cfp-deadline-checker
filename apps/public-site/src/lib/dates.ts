/**
 * 日付ユーティリティ (Issue #86)。
 *
 * カンファレンス CfP 締切までの残り日数計算など、UI 全般で使う純粋関数を集約。
 * 公開フロントは SSG ベースなので、ビルド時 (Astro build) に実行される計算が中心。
 */

/**
 * CfP 締切日 (YYYY-MM-DD) から、基準日 (today) との JST 日付差を整数日数で返す。
 *
 * - 正の値: あと N 日
 * - 0: 今日 (当日)
 * - 負の値: 締切から N 日経過
 *
 * 「日付」の境界は JST 0:00 で切り替わる (Issue #174)。
 * 旧版は UTC 基準で計算していたため、JST 0:00-08:59 (= UTC 前日) の間「あと N 日」が
 * 1 日古いまま表示される構造的バグがあった。本関数は today を JST のカレンダー日付に
 * 変換してから比較することで、JST 0:00 ぴったりで切替が起きるようにする。
 *
 * @param deadline CfP 終了日 (ISO 形式の YYYY-MM-DD、JST のカレンダー日付として解釈)
 * @param today 基準時刻 (Date オブジェクト、内部で JST 日付に変換)
 */
export function daysUntilDeadline(deadline: string, today: Date): number {
  // deadline は YYYY-MM-DD 文字列。Date.UTC で JST 想定の midnight UTC timestamp に。
  // 同じ UTC midnight 同士の比較になるので、deadline と today の両方を「JST カレンダー
  // 日付の UTC midnight」として表現すれば差分は正しく日数になる。
  const [dy, dm, dd] = deadline.split('-').map(Number);
  const deadlineUtc = Date.UTC(dy, dm - 1, dd);

  const todayJst = jstDateComponents(today);
  const todayUtc = Date.UTC(todayJst.year, todayJst.month - 1, todayJst.day);

  const msPerDay = 24 * 60 * 60 * 1000;
  return Math.round((deadlineUtc - todayUtc) / msPerDay);
}

/**
 * Date オブジェクトを JST タイムゾーンの year/month/day に分解する内部ヘルパ (Issue #174)。
 *
 * Intl.DateTimeFormat で JST に変換したうえで構造化された値 (formatToParts) を取り、
 * runtime のシステム timezone に依存せず常に JST 日付として返す。
 */
function jstDateComponents(date: Date): { year: number; month: number; day: number } {
  const formatter = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Tokyo',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  });
  const parts = formatter.formatToParts(date);
  const lookup = (type: string) =>
    Number(parts.find((p) => p.type === type)?.value ?? '0');
  return {
    year: lookup('year'),
    month: lookup('month'),
    day: lookup('day'),
  };
}

/**
 * Date を JST (Asia/Tokyo) の YYYY-MM-DD 文字列にフォーマットする。
 *
 * 公開フロント (Issue #86 / Phase 2) は日本国内のユーザーが対象なので、
 * 表示の日付/時刻は JST に揃える。Intl.DateTimeFormat を使えば runtime の
 * timezone に依存せず JST に明示変換できる。
 */
export function formatJstDate(date: Date): string {
  const formatter = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Tokyo',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  });
  // en-CA ロケールは ISO 8601 互換の YYYY-MM-DD を返す (ja-JP は YYYY/MM/DD)
  return formatter.format(date);
}
